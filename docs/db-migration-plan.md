# Gitwire — Database Layer Refactor Plan

## Goals

1. Replace ad-hoc `dbDelta()` calls with a structured migration system that supports `ALTER TABLE` (add/drop columns, change indexes) — required for the Pro plugin's `credentials` and `scope` columns.
2. Consolidate scattered DB operations into per-table model classes for a single, authoritative place to read/write each table.
3. Wire up the missing `upgrader_process_complete` hook so schema changes apply on plugin update, not only on activation.

---

## Current State

### Classes with direct `$wpdb` operations

| Class | Table(s) | Role |
|---|---|---|
| `class-connection-resolver.php` | `gitwire_connections` | SELECT only — find by ID, by provider |
| `class-public-connections.php` | `gitwire_connections` | Full CRUD for public (unauthenticated) connections |
| `class-connection-meta.php` | `gitwire_connections` | UPDATE profile cache columns (name, avatar_url, rate_*, error, authenticated) |
| `class-installer.php` | `gitwire_installations`, `gitwire_commits` | Installations upsert/delete/update + commits delete |
| `class-rest-installer.php` | `gitwire_commits` | Commits SELECT + upsert |
| `class-repositories.php` | `gitwire_repositories` | Full repositories CRUD (already model-like) |
| `class-schema.php` | all tables | `dbDelta()` CREATE TABLE + DROP TABLE |
| `class-error-handler.php` | `wp_options` | Direct options reads/writes for pre-WP-load safety — keep as-is |

### Problems with current structure

- Three separate classes (`class-connection-resolver.php`, `class-public-connections.php`, `class-connection-meta.php`) all write to `gitwire_connections` with no single source of truth.
- Commit operations split across `class-installer.php` and `class-rest-installer.php`.
- No `upgrader_process_complete` hook — schema changes don't fire on WP-updater plugin updates.
- `dbDelta()` cannot drop columns or alter types — blocks the Pro plugin's add/remove column pattern.

---

## Proposed Structure

Base classes live directly in `includes/` — reachable by the Pro plugin without traversing subdirectories. The `database/` directory contains only per-table subdirectories, each with its own migration and model class.

```
includes/
├── class-migration-base.php           ← ported from fit-assistant (column_exists, index_exists, table_exists)
├── class-model-base.php               ← adapted: get(array $where), upsert(array $data), delete(array $where)
└── database/
    ├── class-database-manager.php     ← activation hook, upgrader_process_complete, uninstall hook
    ├── connections/
    │   ├── class-migration.php        ← CREATE TABLE gitwire_connections
    │   └── class-model.php            ← all gitwire_connections operations
    ├── installations/
    │   ├── class-migration.php        ← CREATE TABLE gitwire_installations
    │   └── class-model.php            ← all gitwire_installations operations
    ├── repositories/
    │   ├── class-migration.php        ← CREATE TABLE gitwire_repositories
    │   └── class-model.php            ← all gitwire_repositories operations
    └── commits/
        ├── class-migration.php        ← CREATE TABLE gitwire_commits
        └── class-model.php            ← all gitwire_commits operations
```

Pro plugin mirrors the same structure under its own `includes/`:

```
gitwire-pro/includes/
└── database/
    └── connections/
        └── class-pro-migration.php    ← ALTER TABLE ADD/DROP credentials, scope, email
```

---

## Layer Designs

### `Migration_Base`

Port directly from fit-assistant. Provides:

```php
protected function table_exists( string $table ): bool
protected function column_exists( string $table, string $column ): bool
protected function index_exists( string $table, string $index ): bool
protected function get_table_name( string $name ): string  // must use $wpdb->base_prefix, not $wpdb->prefix — current Schema uses base_prefix; switching to prefix would silently move tables on multisite
```

### `Model_Base`

Adapted from fit-assistant. Composite PK support via array conditions.

Base methods use internal names to avoid signature conflicts with domain methods on concrete models:

```php
abstract protected function table(): string;
abstract protected function columns(): array;          // whitelist of valid column names — all $where/$data keys are validated against this before SQL is built

protected function get_row( array $where ): ?array
protected function get_rows( array $where = [] ): array
protected function insert_row( array $data ): bool
protected function update_rows( array $data, array $where ): bool
protected function delete_rows( array $where ): bool
protected function upsert_row( array $data ): bool    // INSERT ... ON DUPLICATE KEY UPDATE
```

Concrete models expose public domain methods (`find()`, `all()`, `delete_by_id()`, etc.) that call these base methods. No public `get()`/`delete()` on the base — eliminates all signature pressure.

### Per-table `Migration` classes

Each table has its own `class-migration.php` extending `Migration_Base`. Each owns its own `DB_VERSION` option, CREATE TABLE SQL, and any version-gated `ALTER TABLE` upgrades.

```php
// database/installations/class-migration.php
class Migration extends Migration_Base {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    const DB_VERSION        = '2.0.0';
    const DB_VERSION_OPTION = 'gitwire_installations_db_version';
    const TABLE             = 'gitwire_installations';

    public function migrate(): void        // dbDelta + guarded ALTER TABLE steps; bump version option only after all steps succeed
    public function needs_migrate(): bool  // checks table existence AND version — both conditions, not version alone
    public function drop_tables(): void    // DROP TABLE gitwire_installations

    // Version-gated upgrade example:
    private function upgrade_2_1_0(): void {
        if ( ! $this->column_exists( self::TABLE, 'created_at' ) ) {
            return;
        }
        global $wpdb;
        $table = $this->get_table_name( self::TABLE );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query( "ALTER TABLE {$table} DROP COLUMN created_at" );
    }
}
```

### `Database_Manager`

Orchestrates all four per-table migrations via a single entry point. Lives at `includes/database/class-database-manager.php`.

**Instantiation timing**: must be instantiated from `Plugin::__construct()` (same as the current `register_activation_hook` call in class-plugin.php:68), not from `boot()`/`plugins_loaded`. WordPress silently ignores activation hooks registered after the main plugin file has loaded.

**Uninstall**: `uninstall.php` already exists and takes precedence over `register_uninstall_hook()`. Do not add `register_uninstall_hook()` — update `uninstall.php` to call `Database_Manager::uninstall()` instead of `Schema::uninstall()`.

```php
private function __construct() {
    register_activation_hook( GITWIRE_FILE, [ $this, 'migrate' ] );
    // no register_uninstall_hook — uninstall.php handles this
    add_action( 'upgrader_process_complete', [ $this, 'maybe_migrate' ], 10, 2 );
}

public function migrate(): void {
    Connections\Migration::instance()->migrate();
    Installations\Migration::instance()->migrate();
    Repositories\Migration::instance()->migrate();
    Commits\Migration::instance()->migrate();
    delete_option( 'gitwire_db_version' ); // clean up pre-2.0 single option — at the end, after all tables succeed
}

public function maybe_migrate( $upgrader, array $hook_extra ): void {
    // guard: this plugin only, update action only — mirrors current class-plugin.php:136
    if ( ( $hook_extra['action'] ?? '' ) !== 'update' || ( $hook_extra['type'] ?? '' ) !== 'plugin' ) {
        return;
    }
    // handle both single-plugin ('plugin' key) and bulk ('plugins' key) upgrader paths
    $plugins = array_filter( [
        $hook_extra['plugin'] ?? '',
        ...( (array) ( $hook_extra['plugins'] ?? [] ) ),
    ] );
    if ( ! in_array( plugin_basename( GITWIRE_FILE ), $plugins, true ) ) {
        return;
    }
    $this->migrate();
}

public static function uninstall(): void  // called from uninstall.php; drop_tables() on each if setting enabled
```

**Boot-time fallback**: `Plugin::boot()` must keep its `Schema::needs_install()` → `Schema::install()` equivalent using `Database_Manager`. The upgrader hook runs the old plugin code during updates, so the new migration class may not exist yet — the boot-time check is the reliable path, the upgrader hook is the fast path.

```php
// in Plugin::boot() — replaces current Schema::needs_install() call
if ( Database_Manager::instance()->needs_migrate() ) {
    Database_Manager::instance()->migrate();
}
```

---

## Model Method Signatures

Each model lives at `database/{table}/class-model.php` and extends `Model_Base`.

### `Connections\Model`

Consolidates `class-connection-resolver.php` + `class-public-connections.php` + `class-connection-meta.php`.

```php
public function all(): array
public function find( string $id ): ?array
public function find_by_provider( string $provider ): ?array
public function find_by_identifier( string $provider, string $identifier ): ?array
public function insert( array $data ): bool
public function delete_by_id( string $id ): bool                 // named to avoid base delete(array $where) signature conflict
public function save_metadata( string $id, array $data ): bool   // profile cache columns
public function clear_metadata( string $id ): bool
```

### `Installations\Model`

Extracts DB operations from `class-installer.php`.

```php
public function all(): array
public function find_by_path( string $install_path ): ?array
public function upsert( array $data ): bool
public function update_head( string $provider, string $full_name, string $sha ): bool
public function update_remote_head( string $provider, string $full_name, string $sha ): bool
public function update_basename( string $provider, string $full_name, string $basename ): bool
public function delete_by_repo( string $provider, string $full_name ): bool  // named to avoid base delete(array $where) signature conflict
```

### `Repositories\Model`

Refactor of `class-repositories.php`. Business logic stays there if needed; pure DB ops move into the model.

```php
public function get_paginated( array $filters ): array
public function upsert_batch( string $connection_id, array $repos ): bool
public function get_type( string $connection_id, string $full_name ): ?array
public function set_type( string $connection_id, string $full_name, string $type, ?array $meta ): bool
public function clear( string $connection_id = '' ): bool
public function clear_types(): bool
public function get_untyped_batch( int $limit ): array
public function remove_stale( string $connection_id, array $current_full_names ): bool
```

### `Commits\Model`

Consolidates commit operations from `class-installer.php` and `class-rest-installer.php`.

```php
public function find( int $installation_id, string $branch ): ?array  // named to avoid base get(array $where) signature conflict
public function upsert( int $installation_id, string $branch, array $data ): bool
public function delete_by_installation( int $installation_id ): bool
```

---

## Classes Removed

| Class | Absorbed into |
|---|---|
| `class-connection-resolver.php` | `database/connections/class-model.php` |
| `class-public-connections.php` | `database/connections/class-model.php` |
| `class-connection-meta.php` | `database/connections/class-model.php` |
| `class-schema.php` | `database/*/class-migration.php` + `database/class-database-manager.php` |

`class-installer.php`, `class-rest-installer.php`, and `class-repositories.php` keep their business logic but lose their direct `$wpdb` calls — they become consumers of the model layer.

---

## Pro Plugin Integration

The Pro plugin mirrors the same directory structure. Its migration class extends `Migration_Base` from the free plugin's `includes/`. It does not call `dbDelta()` — only `ALTER TABLE` with `column_exists` guards.

```
gitwire-pro/includes/
└── database/
    └── connections/
        └── class-pro-migration.php
```

```php
// gitwire-pro/includes/database/connections/class-pro-migration.php
class Pro_Migration extends Migration_Base {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // All three are Pro-only; free schema omits them entirely.
    // Drop order (deactivate) is the reverse of add order (activate).
    // Free migrations must never drop these columns while Pro is active — guard by checking Pro plugin status before any DROP.
    const COLUMNS = [ 'email', 'credentials', 'scope' ];

    public function activate(): void {
        global $wpdb;
        $table = $this->get_table_name( 'gitwire_connections' );

        // Add in dependency order: credentials references AFTER email, scope AFTER credentials.
        if ( ! $this->column_exists( 'gitwire_connections', 'email' ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN email VARCHAR(255) NULL AFTER identifier" );
            if ( $wpdb->last_error ) { return; }
        }
        if ( ! $this->column_exists( 'gitwire_connections', 'credentials' ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN credentials TEXT NULL AFTER email" );
            if ( $wpdb->last_error ) { return; }
        }
        if ( ! $this->column_exists( 'gitwire_connections', 'scope' ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN scope VARCHAR(16) NOT NULL DEFAULT 'all' AFTER credentials" );
        }
    }

    public function deactivate( bool $remove_data ): void {
        if ( ! $remove_data ) {
            return;
        }
        global $wpdb;
        $table = $this->get_table_name( 'gitwire_connections' );

        // Drop in reverse add order to avoid FK-style dependency issues.
        foreach ( array_reverse( self::COLUMNS ) as $col ) {
            if ( $this->column_exists( 'gitwire_connections', $col ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query( "ALTER TABLE {$table} DROP COLUMN {$col}" );
            }
        }
    }
}
```

---

## Implementation Sequence

1. **`includes/class-migration-base.php`** — port from fit-assistant; use `$wpdb->base_prefix` in `get_table_name()`; adjust namespace to `Gitwire`
2. **`includes/class-model-base.php`** — Gitwire-adapted version; protected base methods (`get_row`, `get_rows`, `delete_rows`, `upsert_row`); abstract `columns()` whitelist
3. **Per-table `class-migration.php`** — one per table dir; move CREATE TABLE SQL from `class-schema.php`; each gets its own `DB_VERSION` option; bump version only after all steps succeed; `needs_migrate()` checks table existence AND version. Remove `email`, `credentials`, and `scope` from the `gitwire_connections` CREATE TABLE (Pro-only). For existing dev installs that already have these columns: add a one-time `ALTER TABLE DROP COLUMN` guarded by `column_exists()` in `Connections\Migration::migrate()`, only when Pro is not active
4. **`database/class-database-manager.php`** — activation hook + `upgrader_process_complete` (both single and bulk guard); boot-time `needs_migrate()` fallback in `Plugin::boot()`; no `register_uninstall_hook()`
5. **`uninstall.php`** — replace `Schema::uninstall()` call with `Database_Manager::uninstall()`
6. **`autoload.php`** — add every new class (`Database_Manager`, `Migration_Base`, `Model_Base`, all per-table `Migration` and `Model` classes) to the class map before any migration code runs; a missing entry silently causes a fatal on activation/update
7. **`database/commits/class-model.php`** — smallest scope; validates the pattern before tackling larger tables
8. **`database/installations/class-model.php`** — extract from `class-installer.php`
9. **`database/repositories/class-model.php`** — thin DB layer; `class-repositories.php` stays as service
10. **`database/connections/class-model.php`** — consolidate 3 classes into 1; delete originals
11. **Update consumers** — `class-installer.php`, `class-rest-installer.php`, REST classes, cron
12. **Delete** — `class-connection-resolver.php`, `class-public-connections.php`, `class-connection-meta.php`, `class-schema.php`

---

## Decisions

1. **`class-repositories.php`** — Keep as a service class (Option B). Pagination, search, and cron-batch logic is business logic, not DB layer. Only raw `$wpdb` calls move into `database/repositories/class-model.php`; `class-repositories.php` becomes a consumer of it.

2. **`Model_Base::upsert`** — `upsert_batch` stays on `Repositories\Model` only. The base method handles single-row upserts; the repositories batch pattern is too table-specific to belong on the base.

3. **Request caching** — Per-method static cache on each model (same pattern as current `all_rows()`). No shared utility needed at this scale.

4. **`class-error-handler.php`** — Keep as-is. It queries `wp_options` directly for pre-WP-load safety and must not route through the model layer.
