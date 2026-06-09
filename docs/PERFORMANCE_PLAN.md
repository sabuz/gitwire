# Gitwire — Performance Implementation Plan

> Companion to `PERFORMANCE_AUDIT.md`. Ordered by impact / effort ratio — do H1 and H2 first.

---

## Phase 1 — High impact, low risk

### P1-A: Pre-load connections before `annotate_installed()` loop (H1)

**File:** `includes/class-rest.php` — `annotate_installed()`

Before the `foreach`, load all connections into a keyed map:

```php
$all_connections = [];
foreach ( Connections::all() as $conn ) {
    $all_connections[ $conn['id'] ] = $conn;
}
```

Replace `Connections::find( $conn_id )` inside the loop with:

```php
if ( $conn_id && ! isset( $all_connections[ $conn_id ] ) ) {
    $rec['needs_reconnect'] = true;
}
```

**Result:** N `get_option()` calls → 1 call.

---

### P1-B: Add request-scope memo to `get_pending_update()` (H1, M2)

`get_option('gitwire_pending_update')` is called 6+ times per request. Since the option is `autoload => false`, each call may hit the DB.

Add a private static cache:

```php
private static ?array $pending_cache = null;
private static bool   $pending_loaded = false;

private static function get_pending_update(): ?array {
    if ( ! self::$pending_loaded ) {
        self::$pending_cache  = get_option( 'gitwire_pending_update' ) ?: null;
        self::$pending_loaded = true;
    }
    return self::$pending_cache;
}
```

Replace all `get_option( 'gitwire_pending_update' )` calls with `self::get_pending_update()`.
Clear the cache whenever `update_option( 'gitwire_pending_update', ... )` is called: `self::$pending_cache = $value; self::$pending_loaded = true;`.

**Result:** Up to 6 DB queries → 1.

---

### P1-C: Move `sync_installed()` out of `admin_enqueue_scripts` (H2)

`sync_installed()` makes live API calls during asset enqueuing — this fires on every admin page, blocks the request, and cannot be parallelised.

**Option A — REST endpoint (recommended):** Remove the `sync_installed()` call from `class-admin.php::enqueue()`. Add a dedicated REST endpoint `GET /gitwire/v1/sync` that returns `{installed, orphaned}`. Call it from JS on mount with `useEffect`. Pass `installed: []` as the initial inline data so the UI renders immediately.

**Option B — Transient gate:** Wrap the sync in a short-lived transient lock so it only runs once per N minutes (e.g. 5 min) rather than every page load. Simpler but still blocks the enqueue hook.

Option A is preferred — it also enables the installed list to refresh without a page reload.

---

## Phase 2 — Medium impact, moderate effort

### P2-A: Memoize `Installer::get_installed()` for the request lifetime (M1)

Add a static cache to `class-installer.php`:

```php
private static ?array $installed_cache = null;

public static function get_installed(): array {
    if ( null !== self::$installed_cache ) {
        return self::$installed_cache;
    }
    // ... existing logic ...
    self::$installed_cache = $result;
    return $result;
}

public static function invalidate_installed_cache(): void {
    self::$installed_cache = null;
}
```

Call `invalidate_installed_cache()` in any method that writes `gitwire_installed` (`update_option`).

**Result:** ~10 option reads per request → 1.

---

### P2-B: Add eviction and TTL to `commitsCache` (M3)

**File:** `src/components/installed/commits-modal.js`

Replace the bare `Map` with a simple TTL cache:

```js
const CACHE_TTL = 5 * 60 * 1000; // 5 min
const commitsCache = new Map(); // key → { data, ts }

function getCached( key ) {
    const entry = commitsCache.get( key );
    if ( ! entry ) return undefined;
    if ( Date.now() - entry.ts > CACHE_TTL ) {
        commitsCache.delete( key );
        return undefined;
    }
    return entry.data;
}

function setCached( key, data ) {
    commitsCache.set( key, { data, ts: Date.now() } );
}
```

Update `commitsCache.has/get/set` calls to use `getCached`/`setCached`.
`clearCommitsCache` (exported at line 133) continues to work as-is.

**Result:** No unbounded growth; stale data revalidated after 5 minutes.

---

## Phase 3 — Low impact, easy wins

### P3-A: Set `autoload => false` for `gitwire_installed` (L1)

Every `update_option( 'gitwire_installed', ... )` call should pass the fourth argument:

```php
update_option( 'gitwire_installed', $result, false );
```

Also ensure `add_option()` (if used at initial setup) passes `false` for autoload.

**Result:** Option excluded from WP's alloptions preload — reduces per-request DB overhead on non-Gitwire admin pages.

---

### P3-B: Short-circuit migration check in `get_installed()` (L2)

Store a migration flag so the `strpos` loop does not run on every call:

```php
if ( get_option( 'gitwire_installed_migrated' ) ) {
    return (array) get_option( 'gitwire_installed', [] );
}
```

After migration completes: `add_option( 'gitwire_installed_migrated', true, '', false );`

---

## Execution order

| Step | Item  | Est. effort |
|------|-------|-------------|
| 1    | P1-A  | ~30 min |
| 2    | P1-B  | ~30 min |
| 3    | P2-A  | ~45 min |
| 4    | P3-A  | ~15 min |
| 5    | P2-B  | ~30 min |
| 6    | P1-C  | ~2–3 h (REST endpoint approach) |
| 7    | P3-B  | ~20 min |

Start with P1-A → P1-B → P2-A as a single PR. These are pure PHP, no API surface changes, and cut the majority of the N+1 reads. P1-C is the highest-value change but has the most surface area — do it in a separate PR after the others are merged and tested.
