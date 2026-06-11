# Gitwire Free / Pro Split

## Business rule

Private repository access is the Pro gate. The free plugin supports all three providers in public mode — username/workspace only, no tokens.

## WP.org constraint

The free plugin distributed on WordPress.org must contain **zero Pro code** — no gated classes, no license-check stubs, no Pro UI behind a flag. The Pro plugin is distributed outside WP.org and extends the free plugin via defined hooks.

---

## Public API access (free tier)

All three providers support unauthenticated access to public repositories with just a username or workspace slug:

| Provider | Identifier | API endpoint | Rate limit |
|----------|-----------|--------------|------------|
| GitHub | username | `GET /users/{username}/repos` | 60 req/hr per IP |
| GitLab | username | `GET /users?username={u}` → `GET /users/{id}/projects?visibility=public` | varies |
| Bitbucket | workspace slug | `GET /2.0/repositories/{workspace}` | unauthenticated |

The free plugin stores these identifiers as plain strings in `gitwire_settings` — no encryption needed.

---

## Feature table

| Feature | Free | Pro |
|---------|------|-----|
| Browse public repos — GitHub, GitLab, Bitbucket (username only) | ✓ | ✓ |
| Public URL import (no credentials needed) | ✓ | ✓ |
| Installed management (update, branch, activate, delete) | ✓ | ✓ |
| Logs | ✓ | ✓ |
| Settings, Tools | ✓ | ✓ |
| Token-based connections (GitHub PAT, GitLab token, Bitbucket app password) | | ✓ |
| Browse private repos | | ✓ |
| Private URL import (connection picker) | | ✓ |
| Multiple connections per provider | | ✓ |
| Per-user (personal) connections | | ✓ |
| Delete guard ("installed by X") | | ✓ |

---

## Free plugin: settings

The free plugin's Settings panel has a **Browse accounts** section — simple text fields, no tokens:

```
Browse accounts

  GitHub        [ username              ]
  GitLab        [ username              ]  [ Instance URL (optional) ]
  Bitbucket     [ workspace slug        ]

                [ Save ]
```

Stored in `gitwire_settings`:

```php
'github_username'    => '',
'gitlab_username'    => '',
'gitlab_url'         => 'https://gitlab.com',
'bitbucket_workspace'=> '',
```

No token fields. No test-connection button. No encryption. The Pro plugin replaces this entire section with its richer Connections UI via a JS hook (see below).

---

## Architecture

### Two separate plugins, true separation

```
plugins/gitwire/          ← free plugin, published on WP.org
plugins/gitwire-pro/      ← Pro add-on, distributed outside WP.org
```

The free plugin defines **extension hooks** (PHP filters/actions + JS filters via `@wordpress/hooks`). The Pro plugin hooks into them. No Pro code lives in the free plugin.

### Code that moves to Pro

| Currently in free | Moves to |
|-------------------|----------|
| `includes/class-connections.php` | `gitwire-pro/includes/class-connections.php` |
| Connection CRUD routes in `class-rest.php` | `gitwire-pro/includes/class-rest.php` |
| Connection section in `settings-panel.js` | `gitwire-pro/src/` |
| Connection picker in `import-from-url.js` | `gitwire-pro/src/` |
| Connection selector in `browse-panel.js` | `gitwire-pro/src/` |

---

## Extension hooks

### PHP filters

```php
// Resolve a single connection by ID.
// Pro returns the decrypted credential array; free returns null (falls back to public API).
apply_filters( 'gitwire_find_connection', null, $connection_id );

// Return all token-based connections visible to the current user.
// Free always returns []; Pro returns the full connection list.
apply_filters( 'gitwire_connections', [], $user_id );
```

### PHP actions

```php
// Fires at the end of the free plugin's plugins_loaded callback.
// Pro hooks here to guarantee its filters are registered before any apply_filters call.
do_action( 'gitwire_loaded' );

// Fires when REST routes are registered — Pro adds its connection CRUD routes here.
do_action( 'gitwire_rest_init' );
```

### Plugin load order

WordPress loads all plugin files alphabetically (`gitwire` before `gitwire-pro`), then fires action hooks. Both plugins register their `add_filter` / `add_action` calls during the file-load phase. The actual `apply_filters` calls in the free plugin only happen inside action callbacks (`init`, `rest_api_init`, etc.) — by which point both plugins are fully loaded.

`gitwire_loaded` makes this explicit. Free fires it at the end of its `plugins_loaded` callback. Pro hooks into it to register all its filters before `init` runs:

```php
// gitwire/class-plugin.php — end of plugins_loaded callback
do_action( 'gitwire_loaded' );

// gitwire-pro/class-pro-loader.php
add_action( 'gitwire_loaded', function () {
    add_filter( 'gitwire_find_connection', [ Connections::class, 'find' ], 10, 2 );
    add_filter( 'gitwire_connections',     [ Connections::class, 'all'  ], 10, 2 );
    add_action( 'gitwire_rest_init',       [ new REST(), 'register_routes' ] );
} );
```

This is the same pattern WooCommerce extensions use (`woocommerce_loaded`).

### JS hooks (`@wordpress/hooks`)

The free plugin calls `applyFilters` from `@wordpress/hooks` inside React render functions. Unlike PHP, execution order between scripts does not matter: by the time any component renders, all scripts on the page have been parsed and executed — Pro's `addFilter` calls are already registered.

| Filter | Free default | Pro provides |
|--------|-------------|--------------|
| `gitwire.settings.accountsSection` | `<FreeAccountsSection />` (username fields) | `<ConnectionsSection />` (full token UI) |
| `gitwire.importUrl.privatePicker` | `null` | `<ConnectionPicker />` |
| `gitwire.browse.connectionFilter` | `null` | `<BrowseConnectionFilter />` |

---

## Free plugin JS changes

### `src/components/settings-panel.js`

Replace the current connections section with a **Browse accounts** component (username fields). Wrap it in `applyFilters` so Pro can substitute its own Connections section:

```js
import { applyFilters } from '@wordpress/hooks';
import BrowseAccounts from './browse-accounts';

const accountsSection = applyFilters(
    'gitwire.settings.accountsSection',
    <BrowseAccounts settings={ settings } onChange={ onChange } />
);

// in render:
{ accountsSection }
```

### `src/components/import-from-url.js`

In the `'private'` state, apply the filter and fall back to a passive notice:

```js
import { applyFilters } from '@wordpress/hooks';

const privatePicker = applyFilters(
    'gitwire.importUrl.privatePicker',
    null,
    { owner, repo, provider }
);

// in render (private state):
{ privatePicker ?? (
    <p>
        { __( 'This repository is private.', 'gitwire' ) }{ ' ' }
        <a href="https://gitwire.com/pro" target="_blank" rel="noopener noreferrer">
            { __( 'Gitwire Pro supports private repositories.', 'gitwire' ) }
        </a>
    </p>
) }
```

### `src/components/browse-panel.js`

Remove connection selector UI. Apply the filter; when `null` (free), render nothing above the list. Browse uses the public API endpoints with the stored username, no `connection_id`:

```js
import { applyFilters } from '@wordpress/hooks';

const connectionFilter = applyFilters(
    'gitwire.browse.connectionFilter',
    null,
    { connections }
);

// in render, above repo list:
{ connectionFilter }
```

---

## Free plugin PHP changes

### `class-rest.php`

- Replace all `Connections::find( $id )` calls with `apply_filters( 'gitwire_find_connection', null, $id )`.
- Replace `Connections::get_credentials()` calls with `apply_filters( 'gitwire_get_credentials', [], $provider, $id )`.
- Remove the four connection CRUD routes. Fire `do_action( 'gitwire_rest_init' )` at the end of `register_routes()`.
- `/repos` GET: when credentials are empty (filter returns `[]`), use the stored username for an unauthenticated call — this is already the fallback path in the provider classes.

### `includes/class-connections.php`

Delete from the free plugin entirely. The free plugin stores only usernames in `gitwire_settings`.

### `includes/helper/class-settings.php`

Add the four username/workspace fields to `get_public()` and `merge_save()`:

```php
'github_username'     => '',
'gitlab_username'     => '',
'gitlab_url'          => 'https://gitlab.com',
'bitbucket_workspace' => '',
```

### Provider API classes

Each provider class already falls back to unauthenticated when no token is present. Ensure they also pick up the stored username/workspace when `connection_id` is absent:

- `class-api.php` (GitHub): use `Settings::get('github_username')` when no token
- `class-gitlab-api.php`: use `Settings::get('gitlab_username')` and `Settings::get('gitlab_url')`
- `class-bitbucket-api.php`: use `Settings::get('bitbucket_workspace')`

---

## Pro plugin

### Structure

```
plugins/gitwire-pro/
├── gitwire-pro.php
├── autoload.php
├── includes/
│   ├── class-connections.php     ← moved from free (AES-256-GCM encryption)
│   ├── class-rest.php            ← connection CRUD routes
│   ├── class-pro-loader.php      ← registers all PHP hooks
│   └── class-license.php         ← license validation (future)
├── src/
│   ├── index.js                  ← registers JS filters
│   ├── components/
│   │   ├── connections-section.js
│   │   ├── connection-picker.js
│   │   └── browse-connection-filter.js
│   └── api.js
├── build/
└── readme.txt
```

### `gitwire-pro.php`

```php
<?php
/**
 * Plugin Name: Gitwire Pro
 * Description: Private repository access for Gitwire.
 * Version: 1.0.0
 * Requires Plugins: gitwire
 * License: proprietary
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'GITWIRE_VERSION' ) ) {
    add_action( 'admin_notices', static function () {
        echo '<div class="notice notice-error"><p>'
            . esc_html__( 'Gitwire Pro requires the Gitwire plugin to be installed and active.', 'gitwire-pro' )
            . '</p></div>';
    } );
    return;
}

require_once __DIR__ . '/autoload.php';
( new \GitwirePro\Pro_Loader() )->init();
```

### `class-pro-loader.php`

```php
public function init(): void {
    add_action( 'gitwire_loaded', [ $this, 'register_hooks' ] );
}

public function register_hooks(): void {
    add_filter( 'gitwire_find_connection', [ Connections::class, 'find' ], 10, 2 );
    add_filter( 'gitwire_connections',     [ Connections::class, 'all'  ], 10, 2 );
    add_filter( 'gitwire_get_credentials', [ Connections::class, 'get_credentials' ], 10, 3 );
    add_action( 'gitwire_rest_init',       [ new REST(), 'register_routes' ] );
    add_action( 'admin_enqueue_scripts',   [ $this, 'enqueue' ] );
}
```

### `src/index.js`

```js
import { addFilter } from '@wordpress/hooks';
import ConnectionsSection from './components/connections-section';
import ConnectionPicker from './components/connection-picker';
import BrowseConnectionFilter from './components/browse-connection-filter';

addFilter(
    'gitwire.settings.accountsSection',
    'gitwire-pro',
    () => <ConnectionsSection />
);

addFilter(
    'gitwire.importUrl.privatePicker',
    'gitwire-pro',
    ( _el, context ) => <ConnectionPicker { ...context } />
);

addFilter(
    'gitwire.browse.connectionFilter',
    'gitwire-pro',
    ( _el, context ) => <BrowseConnectionFilter { ...context } />
);
```

---

## `connection_id` in installed records

Free: repos installed via public API have no `connection_id`. Updates use the same unauthenticated path.

Pro: repos installed via a token connection store `connection_id`. When Pro is active, `gitwire_find_connection` resolves it. When Pro is deactivated, the filter returns `null` and updates fall back to public — which works for public repos, fails for private (expected).

No data migration needed in either direction.

---

## WP.org upsell compliance

Permitted (one passive mention per context):
- Fallback text in import-from-url private state: one line + external link
- Note in the Browse accounts section: "Need private repos? [Gitwire Pro →]"

Not permitted:
- Modal upsell dialogs
- Notices on every admin page load
- Feature stubs that exist solely to show a paywall

---

## Implementation order

1. Add username/workspace fields to `class-settings.php` (`github_username`, `gitlab_username`, `gitlab_url`, `bitbucket_workspace`)
2. Update provider API classes to use stored username for unauthenticated calls when no token
3. Add `BrowseAccounts` component to `settings-panel.js`
4. Replace direct `Connections::` calls in `class-rest.php` with `apply_filters`
5. Remove connection CRUD routes from `class-rest.php`; fire `do_action( 'gitwire_rest_init' )`
6. Delete `class-connections.php` from free plugin
7. Update `settings-panel.js`, `import-from-url.js`, `browse-panel.js` with `applyFilters` + fallbacks
8. Fire `do_action( 'gitwire_loaded' )` at end of free plugin's `plugins_loaded` callback
9. Create `plugins/gitwire-pro/` with `class-connections.php` (moved), Pro REST routes, `src/index.js`
10. Test free-only: username fields visible, public repos browse, private URL shows passive notice
11. Test free + Pro: Connections section replaces Browse accounts, private repos accessible
