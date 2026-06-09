# Gitwire — Performance Audit

> Audit date: 2026-06-10. Scope: `includes/` (PHP) and `src/` (JS/React).

---

## Summary

| Severity | Count | Description |
|----------|-------|-------------|
| HIGH     | 2     | N+1 DB/transient reads per request; sync on every page load |
| MEDIUM   | 3     | Repeated `get_installed()` calls; option read without request cache; unbounded JS Map |
| LOW      | 2     | Missing `autoload => false`; stale `get_installed()` in installer write path |

---

## HIGH

### H1 — `annotate_installed()` calls `Connections::find()` + `get_transient()` per record

**File:** `includes/class-rest.php` lines 1354–1422

Inside `annotate_installed()`, each record in a `foreach` loop independently:
1. Calls `Connections::find( $conn_id )` — which calls `get_option('gitwire_connections')` and iterates the array (lines 1360–1362).
2. Calls `get_transient( 'gitwire_remote_...' )` once per record (line 1404).
3. Calls `file_exists( $rec['install_path'] . '/theme.json' )` once per record (line 1370).
4. Calls `Installer::get_known_fatal_remote_head()` for active repos with available updates (line 1412) — each invocation does at least one `get_option()`.

For N installed repos this is at minimum **N option reads** (connections) + **N transient reads** + **N filesystem stats** per annotate call.

`Connections::all()` (line 33 in `class-connections.php`) reads the `gitwire_connections` option once and returns the full array. Pre-loading all connections into a keyed map before the loop would reduce N option reads to 1.

---

### H2 — `sync_installed()` runs on every admin page load

**File:** `includes/class-admin.php` line 201

`sync_installed()` is called inside `admin_enqueue_scripts` on every WP admin page where Gitwire enqueues assets. It performs:
- `Installer::get_installed()` — option read
- For each record without a `head`: a live API call to `get_commits()` (network)
- For each record: `self::fetch_remote_head()` — another network call — then `set_transient()`
- One `update_option('gitwire_installed', ...)` if any record changed

On a site with 5 installed repos this is up to 10 API calls + 5 transient writes on every page load, blocking the enqueue hook. The transients written here are then read back immediately by `annotate_installed()` called right after on line 1332.

---

## MEDIUM

### M1 — `Installer::get_installed()` called multiple times in same request

**File:** `includes/class-rest.php` lines 926, 967, 1011

The `get_repos()` handler for each provider (GitHub, GitLab, Bitbucket) each calls `Installer::get_installed()` independently. In a single `/get-repos` request only one branch is taken, so this affects one call per request — but inside `class-installer.php` itself, `get_installed()` is called at lines 167, 207, 238, 260, 433, 513, 778, 853, 884, 992 with no request-scoped memoisation.

`get_installed()` also contains a migration loop (lines 488–499) that runs on every call and calls `update_option()` if migrated, meaning N stale installs can trigger N writes.

---

### M2 — `gitwire_pending_update` option read without request cache

**File:** `includes/class-rest.php` lines 453, 506, 1265, 1350, 1662, 2087

`get_option('gitwire_pending_update')` is called 6+ times across a single request. WordPress caches `get_option()` results in the alloptions cache when `autoload` is `true`, so repeat reads hit the in-memory cache — but the option is written with `autoload => false` at line 2100, bypassing the alloptions preload. This means each `get_option()` call may issue a separate `SELECT` query.

---

### M3 — `commitsCache` Map in commits-modal.js is unbounded

**File:** `src/components/installed/commits-modal.js` line 7

```js
const commitsCache = new Map();
```

This module-level Map accumulates entries for every repo the user views commits for and is never evicted. In a long session viewing many repos, this grows without bound. It also caches fetched data permanently — if commits change between views, the user sees stale data until page reload.

---

## LOW

### L1 — `gitwire_installed` option not explicitly set to `autoload => false`

**File:** `includes/class-installer.php`, `get_installed()` at line 483

The `gitwire_installed` option stores the full record for every installed repo (potentially large). If it was registered without `autoload => false` it is loaded on every WP request, not just admin pages. Neither `add_option()` nor `update_option()` in the codebase pass `autoload => false` for this option.

---

### L2 — Migration branch in `get_installed()` can write on every call

**File:** `includes/class-installer.php` lines 486–499

If any installed record has a legacy key format (no `provider:` prefix), `get_installed()` calls `update_option()`. In normal operation after migration this never fires, but it runs the `strpos` check on every call — a minor overhead for the common case.

---

## Positive patterns (no action needed)

- Assets are enqueued conditionally (Gitwire admin page only).
- Browse panel uses `Promise.all()` to parallelise repo fetches across providers.
- `LogsPanel` and `CommitsModal` are lazily loaded / rendered on demand.
- Repo detection (`fetch_remote_head`) is batched inside `sync_installed` not per-request.
- `commitsCache` correctly uses a module-level Map rather than re-fetching on every render.
