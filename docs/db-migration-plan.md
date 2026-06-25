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
protected function get_table_name( string $name ): string
```

### `Model_Base`

Adapted from fit-assistant. Composite PK support via array conditions:

```php
abstract protected function table(): string;

public function get( array $where ): ?array          // single row
public function get_all( array $where = [] ): array  // all matching rows
public function insert( array $data ): bool
public function update( array $data, array $where ): bool
public function delete( array $where ): bool
public function upsert( array $data ): bool          // INSERT ... ON DUPLICATE KEY UPDATE
```

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

    public function migrate(): void        // dbDelta + bump version option
    public function maybe_migrate(): void  // no-op if version matches
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

```php
private function __construct() {
    register_activation_hook( GITWIRE_FILE, [ $this, 'migrate' ] );
    register_uninstall_hook( GITWIRE_FILE, [ __CLASS__, 'uninstall' ] );
    add_action( 'upgrader_process_complete', [ $this, 'maybe_migrate' ], 10, 2 );
}

public function migrate(): void {
    Connections\Migration::instance()->migrate();
    Installations\Migration::instance()->migrate();
    Repositories\Migration::instance()->migrate();
    Commits\Migration::instance()->migrate();
    // clean up the single-option from the pre-2.0 schema class
    delete_option( 'gitwire_db_version' );
}

public function maybe_migrate( $upgrader, array $hook_extra ): void {
    if (
        'plugin' !== ( $hook_extra['type'] ?? '' ) ||
        ! in_array( plugin_basename( GITWIRE_FILE ), $hook_extra['plugins'] ?? [], true )
    ) {
        return;
    }
    $this->migrate();
}

public static function uninstall(): void                        // drop_tables() on each if setting enabled
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
public function update_plugin_file( string $provider, string $full_name, string $file ): bool
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
    const COLUMNS = [ 'email', 'credentials', 'scope' ];

    public function activate(): void {
        global $wpdb;
        $table = $this->get_table_name( 'gitwire_connections' );

        // Add in dependency order: credentials references AFTER email, scope AFTER credentials.
        if ( ! $this->column_exists( 'gitwire_connections', 'email' ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN email VARCHAR(255) NULL AFTER identifier" );
        }
        if ( ! $this->column_exists( 'gitwire_connections', 'credentials' ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN credentials TEXT NULL AFTER email" );
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

1. **`includes/class-migration-base.php`** — port from fit-assistant, adjust namespace to `Gitwire`
2. **`includes/class-model-base.php`** — Gitwire-adapted version (array-based `$where` conditions)
3. **Per-table `class-migration.php`** — one per table dir; move CREATE TABLE SQL from `class-schema.php`; each gets its own `DB_VERSION` option. Remove `email`, `credentials`, and `scope` from the `gitwire_connections` CREATE TABLE — those columns are Pro-only and will be added by `Pro_Migration::activate()`
4. **`database/class-database-manager.php`** — wire up activation, `upgrader_process_complete`, uninstall hooks; remove old hook registrations from `class-schema.php`
5. **`database/commits/class-model.php`** — smallest scope; validates the pattern before tackling larger tables
6. **`database/installations/class-model.php`** — extract from `class-installer.php`
7. **`database/repositories/class-model.php`** — refactor `class-repositories.php`
8. **`database/connections/class-model.php`** — consolidate 3 classes into 1; delete originals
9. **Update consumers** — `class-installer.php`, `class-rest-installer.php`, REST classes, cron
10. **Delete** — `class-connection-resolver.php`, `class-public-connections.php`, `class-connection-meta.php`, `class-schema.php`

---

## Decisions

1. **`class-repositories.php`** — Keep as a service class (Option B). Pagination, search, and cron-batch logic is business logic, not DB layer. Only raw `$wpdb` calls move into `database/repositories/class-model.php`; `class-repositories.php` becomes a consumer of it.

2. **`Model_Base::upsert`** — `upsert_batch` stays on `Repositories\Model` only. The base method handles single-row upserts; the repositories batch pattern is too table-specific to belong on the base.

3. **Request caching** — Per-method static cache on each model (same pattern as current `all_rows()`). No shared utility needed at this scale.

4. **`class-error-handler.php`** — Keep as-is. It queries `wp_options` directly for pre-WP-load safety and must not route through the model layer.
