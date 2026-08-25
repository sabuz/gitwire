# Gitwire Developer Hooks

All hooks follow the `gitwire_` prefix convention. Actions fire at specific lifecycle points; filters allow reading or replacing internal data. Gitwire Pro uses the same hooks — third-party extensions should hook in at `gitwire_loaded` priority 20 or later to run after Pro.

---

## Actions

### `gitwire_loaded`

Fires after Gitwire has fully booted and all internal hooks are registered. Use this hook to register Pro or third-party extensions before the plugin makes any `apply_filters` calls.

```php
add_action( 'gitwire_loaded', function () {
    // Register extension hooks here.
} );
```

---

### `gitwire_rest_init`

Fires when Gitwire registers its REST routes under the `gitwire/v1` namespace. Use this hook to register additional routes in that namespace.

```php
add_action( 'gitwire_rest_init', function () {
    register_rest_route( 'gitwire/v1', '/my-endpoint', [
        'methods'             => 'GET',
        'callback'            => 'my_callback',
        'permission_callback' => '__return_true',
    ] );
} );
```

---

### `gitwire_enqueue_assets`

Fires when Gitwire enqueues its admin-panel scripts and styles. Use this hook to enqueue scripts that depend on the Gitwire app bundle.

```php
add_action( 'gitwire_enqueue_assets', function () {
    wp_enqueue_script(
        'my-gitwire-extension',
        plugin_dir_url( __FILE__ ) . 'build/extension.js',
        [ 'gitwire-app' ],
        '1.0.0',
        true
    );
} );
```

---

### `gitwire_refresh_connections`

WP Cron action scheduled every 30 minutes. Gitwire uses it internally to refresh connection metadata. Third-party extensions can hook in to run connection-related background tasks on the same schedule.

```php
add_action( 'gitwire_refresh_connections', function () {
    // Runs every 30 minutes alongside the built-in refresh.
} );
```

---

### Cron events

Every recurring event Gitwire schedules, all safe to hook or to trigger by hand with `wp cron event run <hook>`.

| Hook | Schedule | Does |
|------|----------|------|
| `gitwire_refresh_repositories` | Repository Refresh Frequency | Sweeps every connection's repository list into the cache table. Never touches stored types. |
| `gitwire_refresh_repository_types` | Repository Type Refresh Frequency | Re-detects the type of every installed repository. Removed entirely when the frequency is `never` or type detection is off. |
| `gitwire_background_type_detection` | Every 30 minutes, fixed | Types cache rows that have no detection yet. No-op unless Background Type Pre-Detection is on. |
| `gitwire_update_check` | Update Check Frequency | Checks installed repositories for new commits. Removed when set to `never`. |
| `gitwire_refresh_connections` | Every 30 minutes, fixed | Refreshes connection metadata and rate-limit readings. |
| `gitwire_maintenance` | Hourly | Syncs installed records, purges orphaned backups and log files. |
| `gitwire_trim_logs` | Hourly | Drops log entries past the retention window. |

---

## Filters

### `gitwire_connections_all`

Filters the full list of connections (all scopes, including Pro private rows and public rows).

#### Parameters

| # | Type | Description |
|---|------|-------------|
| 1 | `array[]` | All connection records from the database. |

**Return:** `array[]`

```php
add_filter( 'gitwire_connections_all', function ( array $connections ): array {
    // Add a virtual connection.
    $connections[] = [
        'id'         => 'my-virtual-conn',
        'provider'   => 'github',
        'identifier' => 'myorg',
        'scope'      => 'all',
    ];
    return $connections;
} );
```

---

### `gitwire_connections`

Filters the public-safe, scope-filtered connection list visible to the current user. Credentials are already stripped before this filter fires.

#### Parameters

| # | Type | Description |
|---|------|-------------|
| 1 | `array[]` | Connections visible to the current user, credentials stripped. |
| 2 | `int`     | Current WordPress user ID. |

**Return:** `array[]`

---

### `gitwire_find_connection`

Filters the connection record returned for a specific connection ID.

#### Parameters

| # | Type | Description |
|---|------|-------------|
| 1 | `array\|null` | Connection record from the database, or null when not found. |
| 2 | `string`       | Connection ID. |

**Return:** `array|null`

---

### `gitwire_connection_for_provider`

Filters the first connection record found for a given provider.

#### Parameters

| # | Type | Description |
|---|------|-------------|
| 1 | `array\|null` | Connection record, or null when none exists for the provider. |
| 2 | `string`       | Provider key: `'github'`, `'gitlab'`, or `'bitbucket'`. |

**Return:** `array|null`

---

### `gitwire_get_credentials`

Filters the credentials for a connection by ID. This is the primary hook for supplying credentials from external storage — Gitwire Pro uses it to return decrypted token credentials for private connections.

Return an array with provider-specific keys, or `null` to fall through to the next handler.

**GitHub credential keys:** `token`, `username`
**GitLab credential keys:** `token`, `gitlab_url`
**Bitbucket credential keys:** `email`, `api_token`, `workspace`

#### Parameters

| # | Type | Description |
|---|------|-------------|
| 1 | `array\|null` | Credentials from internal storage, or null. |
| 2 | `string`       | Connection ID. |

**Return:** `array|null`

```php
add_filter( 'gitwire_get_credentials', function ( ?array $creds, string $id ): ?array {
    if ( 'my-conn-id' !== $id ) {
        return $creds;
    }
    return [ 'token' => get_option( 'my_github_token' ) ];
}, 10, 2 );
```

---

### `gitwire_provider_credentials`

Filters the credentials for the default connection of a provider. Called when no connection ID is specified.

#### Parameters

| # | Type | Description |
|---|------|-------------|
| 1 | `array\|null` | Credentials, or null when none are configured. |
| 2 | `string`       | Provider key: `'github'`, `'gitlab'`, or `'bitbucket'`. |

**Return:** `array|null`

---

### `gitwire_provider_factory_auth`

Filters the resolved credential array immediately before a provider API client is instantiated. Use this to inject or override credentials at the API level.

#### Parameters

| # | Type | Description |
|---|------|-------------|
| 1 | `array`        | Resolved credential array. |
| 2 | `string`       | Provider key: `'github'`, `'gitlab'`, or `'bitbucket'`. |
| 3 | `string\|null` | Connection ID, or null for the default connection. |

**Return:** `array`

---

### `gitwire_rate_limited_message`

Filters GitHub's rate-limit message before it reaches the caller. The unauthenticated wording mentions Gitwire Pro; use this to replace it when the request came from a connection that already has Pro (or another extension) attached, where pitching Pro from inside Pro reads oddly.

#### Parameters

| # | Type | Description |
|---|------|-------------|
| 1 | `string` | The rate-limit message. |
| 2 | `string` | Connection ID used for this request, empty for anonymous. |
| 3 | `bool`   | Whether the request carried a token. |

**Return:** `string`

```php
add_filter( 'gitwire_rate_limited_message', function ( string $message, string $connection_id, bool $authenticated ): string {
    if ( $authenticated ) {
        return $message;
    }
    return __( "GitHub's hourly rate limit for unauthenticated requests has been reached. It resets automatically within the hour.", 'my-extension' );
}, 10, 3 );
```

---

### `gitwire_detection_batch_size`

Filters the number of repositories to type-detect per background cron cycle. Lower this on resource-constrained servers; raise it to speed up initial detection on large installs.

The default is adaptive: `100` when the lowest cached GitHub rate-limit reading still shows 3,000 or more requests left for the hour, `25` otherwise. Only GitHub qualifies for the larger batch. GitLab meters per minute, so a fresh minute always reads as idle regardless of what the next half hour holds, and Bitbucket reports no usage headers outside scaled-tier organisations.

#### Parameters

| # | Type | Description |
|---|------|-------------|
| 1 | `int` | Repositories per cycle. Default: `25`, or `100` when GitHub quota is idle. |

**Return:** `int`

```php
add_filter( 'gitwire_detection_batch_size', fn() => 10 );
```

---

### `gitwire_detection_time_budget`

Filters how long one background type-detection tick may spend calling providers before it stops and leaves the rest for the next tick. Cron requests inherit `max_execution_time` from php.ini, commonly 30 seconds, and stopping short of it is what lets the resume cursor be written instead of the request being killed mid-batch.

#### Parameters

| # | Type | Description |
|---|------|-------------|
| 1 | `int` | Seconds per tick. Default: `20`. |

**Return:** `int`

```php
add_filter( 'gitwire_detection_time_budget', fn() => 10 );
```

---

### `gitwire_refresh_time_budget`

Filters how long one repository-list refresh may spend sweeping provider pages. Applies to both the scheduled sweep and the Browse tab's Refresh button, which share the same code path. When the budget runs out mid-connection, a cursor is parked and the next cron tick resumes from that page — stale rows are left in place until the sweep actually reaches the last page.

#### Parameters

| # | Type | Description |
|---|------|-------------|
| 1 | `int` | Seconds per sweep. Default: `20`. |

**Return:** `int`

```php
add_filter( 'gitwire_refresh_time_budget', fn() => 45 );
```

---

### `gitwire_detect_batch_time_budget`

Filters how long one `POST /repos/detect-batch` request may spend calling providers. Each repository costs a tree listing plus up to five file fetches, so a full batch of ten can be seventy round trips. Whatever is ready when the budget runs out is returned; the rest stay undetected and are picked up by a later request or by background detection.

#### Parameters

| # | Type | Description |
|---|------|-------------|
| 1 | `int` | Seconds per request. Default: `15`. |

**Return:** `int`

```php
add_filter( 'gitwire_detect_batch_time_budget', fn() => 30 );
```
