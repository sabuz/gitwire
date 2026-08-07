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

WP Cron action scheduled every 30 minutes. Gitwire uses it internally to refresh the repository list cache and connection metadata. Third-party extensions can hook in to run connection-related background tasks on the same schedule.

```php
add_action( 'gitwire_refresh_connections', function () {
    // Runs every 30 minutes alongside the built-in refresh.
} );
```

---

## Filters

### `gitwire_connections_all`

Filters the full list of connections (all scopes, including Pro private rows and public rows).

**Parameters**

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

**Parameters**

| # | Type | Description |
|---|------|-------------|
| 1 | `array[]` | Connections visible to the current user, credentials stripped. |
| 2 | `int`     | Current WordPress user ID. |

**Return:** `array[]`

---

### `gitwire_find_connection`

Filters the connection record returned for a specific connection ID.

**Parameters**

| # | Type | Description |
|---|------|-------------|
| 1 | `array\|null` | Connection record from the database, or null when not found. |
| 2 | `string`       | Connection ID. |

**Return:** `array|null`

---

### `gitwire_connection_for_provider`

Filters the first connection record found for a given provider.

**Parameters**

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

**Parameters**

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

**Parameters**

| # | Type | Description |
|---|------|-------------|
| 1 | `array\|null` | Credentials, or null when none are configured. |
| 2 | `string`       | Provider key: `'github'`, `'gitlab'`, or `'bitbucket'`. |

**Return:** `array|null`

---

### `gitwire_provider_factory_auth`

Filters the resolved credential array immediately before a provider API client is instantiated. Use this to inject or override credentials at the API level.

**Parameters**

| # | Type | Description |
|---|------|-------------|
| 1 | `array`        | Resolved credential array. |
| 2 | `string`       | Provider key: `'github'`, `'gitlab'`, or `'bitbucket'`. |
| 3 | `string\|null` | Connection ID, or null for the default connection. |

**Return:** `array`

---

### `gitwire_detection_batch_size`

Filters the number of repositories to type-detect per background cron cycle. Lower this on resource-constrained servers; raise it to speed up initial detection on large installs.

**Parameters**

| # | Type | Description |
|---|------|-------------|
| 1 | `int` | Repositories per cycle. Default: `25`. |

**Return:** `int`

```php
add_filter( 'gitwire_detection_batch_size', fn() => 10 );
```
