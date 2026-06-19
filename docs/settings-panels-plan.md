# Gitwire — Settings panels plan

Two new settings panels, a per-repo auto-update feature, a schema cleanup, and a shallow type-detection optimisation. This document is for review before any code is written.

> Effort markers: ● small, ●● medium, ●●● large.

---

## Settings map

All settings before and after. "Status" is one of: **existing** (key unchanged), **moved** (key unchanged, UI panel changes), **new** (new key), or **per-row** (stored on a DB table row, not `gitwire_settings`).

### Panel 1 — Browse & Detection

| Key | Default | Status | Notes |
|-----|---------|--------|-------|
| `repo_list_refresh_frequency` | `'daily'` | moved | Was in the single catch-all panel |
| `smart_install` | `true` | existing | Stays here; locks `auto_detect_type` when on |
| `auto_detect_type` | `true` | new | Locked on when `smart_install` is true |
| `repos_per_page` | `50` | new | Range 10–100 |
| `excluded_repos` | `[]` | new | Array of `owner/repo` strings |
| `max_repos_per_source` | `'unlimited'` | new | Int or `'unlimited'`; caps total repos fetched per connection |
| `background_type_detection` | `false` | new | Batch-detect `type_meta = NULL` rows each cron cycle |
| `detection_batch_size` | `'auto'` | new | Repos detected per cron cycle; `'auto'` or 10–200 |
| `shallow_detection` | `false` | new | Reuse stored file patterns to skip full file scans on re-detect |

### Panel 2 — Installed & Updates

| Key | Default | Status | Notes |
|-----|---------|--------|-------|
| `show_repo_label` | `true` | moved | Was in the single catch-all panel |
| `block_on_fatal` | `true` | new | Feature is hardcoded; this key exposes it. Enabled = block SHA permanently; disabled = 5-min transient retry |
| `update_check_interval` | `'daily'` | new | `'hourly'` / `'6hours'` / `'daily'` / `'weekly'` / `'never'` |
| `auto_update` _(per-row)_ | `0` | per-row | New column on `gitwire_installed` |
| `auto_update_scope` _(per-row)_ | `'current'` | per-row | New column on `gitwire_installed`; `'current'` or `'any'` |

### Logging panel (unchanged, not in scope)

| Key | Default | Status |
|-----|---------|--------|
| `enable_logging` | `false` | existing |
| `log_retention_days` | `7` | existing |
| `log_level` | `'activity'` | existing |

### General / Danger zone (unchanged, not in scope)

| Key | Default | Status |
|-----|---------|--------|
| `remove_data_on_uninstall` | `false` | existing |

---

## Context

The plan adds two focused panels to replace the current single catch-all. Seven existing keys are accounted for — two move to Panel 1, one moves to Panel 2, four stay where they are. Nine new `gitwire_settings` keys are added (`auto_detect_type`, `repos_per_page`, `excluded_repos`, `max_repos_per_source`, `background_type_detection`, `detection_batch_size`, `shallow_detection`, `block_on_fatal`, `update_check_interval`) plus two new `gitwire_installed` columns (`auto_update`, `auto_update_scope`).

---

## Panel 1 — Browse & Detection

Everything that controls what appears in the Browse tab and how repos are identified.

### Settings

**0. Smart Install** (existing, moved here)  
Already implemented as `smart_install`. No changes — relocated from the current single panel. When on, it locks `auto_detect_type` (below) and forces detection before every install.

**1. Auto-detect repository type** ●  
Toggle, default on.

- Cannot be disabled while Smart Install is active (UI enforces it — the toggle is locked with a tooltip: "Smart Install requires type detection").
- If Smart Install is off and this is turned off: at install time Gitwire asks "Install as plugin or theme?" instead of auto-detecting. The type prompt replaces the silent smart-detect step.
- New key: `auto_detect_type` (bool, default `true`).

**2. Repos per page** ●  
Number input, range 10–100, default 50.

- Controls the `LIMIT` in `get_repo_list()` (currently hardcoded 100). JS sends no `per_page` param today — it would be added to the `GET /repos` query string and read server-side.
- New key: `repos_per_page` (int, default `50`, valid range 10–100).

**Search and filter — server-side** ●●

Search and filter query the full `gitwire_repo_cache` table, not just the repos already loaded in the browser. The first load fetches the first `repos_per_page` rows. A search or filter change fires a fresh server request at offset 0 and returns the first matching page from all stored repos. Load More passes both the current offset and all active params so pagination works within a filtered/searched result set.

API: `GET /repos?search=keyword&source=connection_id&type=plugin&offset=0`

**Search:**
- Matches against `name` and `owner` (`WHERE name LIKE %s OR owner LIKE %s`)
- Debounced in JS — request fires 350ms after the user stops typing, not per keystroke
- Clearing the field reloads the default unfiltered view

**Source filter** (by connection):
- `WHERE connection_id = %s`
- Dropdown populated from the user's configured connections

**Type filter** (plugin / theme / unknown / all):
- Requires a dedicated `type` VARCHAR(20) column on `gitwire_repo_cache` — populated whenever `type_meta` is written, indexed, and fast to filter. `LIKE` matching against the JSON `type_meta` TEXT field cannot use an index.
- Values: `'plugin'`, `'block-theme'`, `'classic-theme'`, `'unknown'`, `''` (not yet detected)
- `WHERE type = %s`

**`gitwire_repo_cache` schema addition:**
The `type` column is part of Group A schema changes. It is written in `set_repo_type()` alongside `type_meta` and reset to `''` when `type_meta` is cleared.

**3. Exclude repositories** ●●  
`FormTokenField` (or plain textarea, one `owner/repo` per line as fallback).

- Repos matching an entry in this list are never returned by `get_repo_list()`. The filter runs in the SQL query (`full_name NOT IN (...)`), not in PHP, so excluded repos don't count against the page limit.
- New key: `excluded_repos` (array of strings, default `[]`).
- On save, validate each entry is `owner/repo` format; reject anything else with an inline error.

**4. Max repos per source** ●●  
Select: `100 / 250 / 500 / No limit`. Default `No limit`.

- Why this setting exists: fetching the full repo list from a provider is one API call per page (typically 30–100 repos per page). A user with 1 000 repos across multiple sources generates a lot of cron traffic. Capping the total repo count per source lets users trade discovery completeness for faster cron and fewer API calls. `No limit` is the default because restricting discovery silently is surprising.
- Stored as an integer or `'unlimited'`. PHP side: `refresh_repo_lists()` tracks `$total_fetched` across pages and breaks when `$total_fetched >= $max_repos`.
- New key: `max_repos_per_source` (int or `'unlimited'`, default `'unlimited'`).

**5. Repository list cache lifetime** (existing, moved here)  
Already implemented as `repo_list_refresh_frequency` ('hourly' / 'daily' / 'weekly'). No changes needed — just relocated to this panel in the UI.

**6. Background type pre-detection** ●●  
Toggle, default off.

- When on: after each `refresh_repo_lists()` run, `cron_refresh_repo_types()` is extended to detect `type_meta = NULL` rows in configurable batches, not just installed repos.
- Progress is tracked in a `gitwire_detection_cursor` option (stores the last processed `full_name`). Each cron cycle picks up where the last one left off — both execution-timeout and API-limit stops are safe because the cursor is saved before exiting the loop.
- **Execution time guard:** `time() - $batch_start > $threshold` checked inside the loop; breaks early and saves cursor.
- **API rate limit guard:** if the provider returns 429 or rate-limit-remaining drops below a safe floor (10 requests), breaks early and saves cursor.
- When off (default): type detection stays lazy — only triggered when the user browses a page or installs a repo.
- New key: `background_type_detection` (bool, default `false`).
- New key: `detection_batch_size` (`'auto'` or int, default `'auto'`, valid range 10–200). Controls how many `NULL`-type repos are processed per cron cycle. In `auto` mode the value is derived from `ini_get('max_execution_time')` — a safe fraction of the PHP time limit is used so the batch finishes well within the allowed window.

**7. Shallow detection** ●●  
Toggle, default off.

- When on: before running a full file scan, Gitwire checks whether the repo's root file listing matches the pattern stored from the previous detection. If it matches, only one targeted API call is made instead of the full scan (up to 7 calls). See the [Shallow Detection](#shallow-detection) section below for the full algorithm.
- When off: every detection always runs the full scan.
- Best suited to large collections (500+ repos) where API rate limits are a concern. For small collections the overhead of the comparison outweighs the saving.
- New key: `shallow_detection` (bool, default `false`).

---

## Panel 2 — Installed & Updates

Everything that controls how installed repos behave after install.

### Settings

**1. Show repo label** (existing, moved here)  
Already implemented as `show_repo_label`. No changes — relocated from the current single panel.

**2. Block on fatal error** ●  
Toggle, default on. Currently hardcoded — this setting exposes the behaviour so users can change it.

- **Enabled (default):** when a commit causes a fatal error, that SHA is blocked permanently until a new commit is detected on the branch.
- **Disabled:** Gitwire will still pull and revert the fatal commit. Help text on the toggle: "Note: a fatal commit will still be blocked for 5 minutes per retry due to an internal rate limit."
- New key: `block_on_fatal` (bool, default `true`).

**3. Auto-update check interval** ●  
Select: `Every hour / Every 6 hours / Daily / Weekly / Never`. Default `Daily`.

- "Never" disables the update-check cron entirely (removes the scheduled event). Any other value reschedules it at the new interval.
- This replaces / extends the existing `repo_list_refresh_frequency` concept — or is a separate cron from it (update checks and repo list refresh are different operations). Recommend keeping them separate so users can refresh the browse list often without hammering the update-check endpoint.
- New key: `update_check_interval` (string: `'hourly'` / `'6hours'` / `'daily'` / `'weekly'` / `'never'`, default `'daily'`).

**4. Auto-update per repo** ●●●  
Not a global setting — a per-repo action in the Installed table action menu.

Flow:
1. "Enable auto-update" in the repo's action menu (or a toggle column in the table).
2. Popup: "Auto-update this repo when new commits are detected on:"
   - ◉ Current branch (`main`)
   - ○ Any branch (update to the new head whenever any branch gets a commit)
3. On confirm: saves an `auto_update` flag + `auto_update_scope` (`'current'` or `'any'`) to the `gitwire_installed` row.
4. During update-check cron: if `auto_update = 1` and a newer head is found, Gitwire runs the update silently and fires an admin notice: "owner/repo was updated to abc1234."

Storage: two new columns on `gitwire_installed`:
- `auto_update` TINYINT(1) NOT NULL DEFAULT 0
- `auto_update_scope` VARCHAR(10) NOT NULL DEFAULT 'current'

> Auto-update is a free feature.  
> Email notification on auto-update (listing plugins/themes updated with no fatal) is deferred to a future version. Admin notice only for now.

---

## Schema simplification — `gitwire_repo_cache`

Two columns dropped, one added, three renamed on `gitwire_repo_cache`; one column dropped on `gitwire_installed`.

### Columns to drop

| Column | Why it existed | Why it can go |
|--------|---------------|---------------|
| `page` SMALLINT | Tracked which provider API page a row came from | Not needed — full fetches resolve all pages; the per-row page number has no use |
| `has_more` TINYINT(1) | Tracked whether the provider had more pages after this row | Same reason — cron now fetches until the provider returns `has_more = false` |

The `connection_page (connection_id, page)` index is also dropped alongside `page`.

### Columns to add

| Column | Type | Reason |
|--------|------|--------|
| `type` | VARCHAR(20) NOT NULL DEFAULT `''` | Flattened type for indexed filtering. Values: `'plugin'`, `'block-theme'`, `'classic-theme'`, `'unknown'`, `''` (not yet detected). Written alongside `type_meta` in `set_repo_type()`; reset to `''` when `type_meta` is cleared. |

### Columns to rename

**On `gitwire_repo_cache`:**

| Old name | New name | Reason |
|----------|----------|--------|
| `updated_at` VARCHAR(32) | `last_activity_at` | Stores the repo's last activity timestamp from the provider (ISO 8601). The old name collided with the cron-marker column below. `last_activity_at` matches what GitHub and GitLab surface in their UIs. |
| `created_at` INT UNSIGNED | `updated_at` | Stores when this cache row was last written by cron. Renamed from `created_at` because it is updated on every UPSERT, not just on insert. |
| `type_data` TEXT | `type_meta` | The name `type_data` is generic and collides conceptually with the new flat `type` column. `type_meta` is consistent with WordPress meta naming and clarifies this column stores the full detection payload, not just the type value. |

**On `gitwire_installed`:**

| Change | Detail |
|--------|--------|
| Drop `subtype` column | Subtype is merged into `type`. Existing rows: `type='theme', subtype='block'` → `type='block-theme'`; `type='theme', subtype='classic'` (or `''`) → `type='classic-theme'`. |

### `updated_at` — narrowed to one job

`updated_at` (formerly `created_at`) remains in the table with a single purpose: **cron-cycle marker**. After upserting all repos for a connection, `DELETE WHERE connection_id = %s AND updated_at < $refresh_started` removes rows that weren't touched in this cycle (repos that disappeared from the provider). Without it, gone repos accumulate indefinitely. It is written on every UPSERT solely to support this one DELETE.

Two cache-age checks are removed (both reference `created_at` in current code — the column that becomes `updated_at`):

- `WHERE created_at > %d` in `get_repo_list()` — treated old rows as a cache miss and triggered an on-demand fetch when cron was behind. Removed: cron owns freshness; reads return whatever is in the table. `get_repo_list()` returns `null` only when the table has no rows at all (first run before cron or a manual refresh).
- `AND created_at > %d` in `get_repo_type()` — same pattern, same reasoning. Removed.

`Settings::get_repositories_max_age()` controls cron scheduling only — it no longer gates reads anywhere.

### DB_VERSION bump required

Removing columns is a schema change. `Schema::DB_VERSION` must be incremented so `dbDelta` runs and drops the columns on existing installs.

> **Note:** `dbDelta` does not drop columns automatically — a manual `ALTER TABLE ... DROP COLUMN` is needed in the upgrade path, guarded by the version check.

---

## Shallow detection

A pattern-matching optimisation for re-detection during cron or background passes. Off by default; most useful for large collections where API rate limits are a concern.

### How it works

**Current full detection** (worst case, 7 API calls per repo):
1. Fetch root directory listing
2. Fetch `style.css` (if present) — check for `Theme Name:` header
3. Fetch up to 5 PHP files — check for `Plugin Name:` header

**Shallow detection** (2 API calls per repo, when key files match):
1. Fetch root directory listing
2. Check all `key_files` from the previous detection are still present
3. If all present — fetch only the files that need content verification (see table below), confirm headers still there
4. If any `key_file` is missing, or `key_files` is empty — fall back to full detection

### `key_files` per detection path

`key_files` stores only the files that drove the original detection decision — not the full root listing.

| Detection path | `key_files` | Content re-check |
|----------------|-------------|-----------------|
| Block theme via `theme.json` | `["style.css", "theme.json"]` | Fetch `style.css`, verify `Theme Name:` — `theme.json` presence already confirmed in step 2 |
| Block theme via `templates/` | `["style.css", "templates/"]` | Fetch `style.css`, verify `Theme Name:` — `templates/` presence already confirmed |
| Classic theme, high confidence | `["style.css", "functions.php"]` | Fetch `style.css`, verify `Theme Name:` — `functions.php` presence already confirmed |
| Classic theme, medium confidence | `["style.css"]` | Fetch `style.css`, verify `Theme Name:` |
| Classic theme via `functions.php` only | `["functions.php"]` | Presence confirmed — re-detect as classic theme, no file fetch needed |
| Plugin, high confidence | `["my-plugin.php"]` | Fetch the file, verify `Plugin Name:` |
| Plugin, low confidence | `[]` | Always full re-detect — low confidence signals are unreliable |
| Unknown | `[]` | Always full re-detect |

**On `templates/` safety:** `templates/` only appears in `key_files` after `style.css` with `Theme Name:` is confirmed. Plugins and WooCommerce template overrides that have a `templates/` dir won't have `Theme Name:` in `style.css`, so they never reach this branch in the detector.

### `type_meta` JSON shape

`type_meta` stores confidence, the detected display name, and `key_files`. The `type` value is stored in the dedicated `type` column — not repeated in `type_meta`.

```json
{ "confidence": "high", "name": "My Theme", "key_files": ["style.css", "theme.json"] }
```

```json
{ "confidence": "high", "name": "My Theme", "key_files": ["style.css", "functions.php"] }
```

```json
{ "confidence": "high", "name": "My Plugin", "key_files": ["my-plugin.php"] }
```

```json
{ "confidence": "medium", "name": "", "key_files": ["functions.php"] }
```

```json
{ "confidence": "low", "name": "", "key_files": [] }
```

### Why no "save temporarily before clearing" is needed

The UPSERT in `set_repo_list()` already excludes `type_meta` from `ON DUPLICATE KEY UPDATE`, so cached detection results survive across cron refreshes. The existing `key_files` are already in place when the next detection runs — no separate save/restore step is required.

### Caveat — when shallow detection can miss

- A plugin that renames its main file: old `key_file` is gone from the new listing → fallback triggers correctly
- A plugin that moves its `Plugin Name:` header to a different file but keeps the old file: shallow detection fetches the old file, finds the header gone, falls back to full detection
- A theme that removes `style.css`: absent from new listing → fallback

All edge cases correctly trigger a fallback. The only scenario shallow detection cannot catch is a file that keeps its name but has its header stripped — but in that case the content re-check (step 3) finds the header missing and falls back to full detection anyway.

---

## Deferred — Update notifications panel

Moving `show update available` badges, admin notice copy, and email digest to their own panel is planned but deferred to the next release. Not in scope here.

---

## Implementation order

**Group A — Schema changes (do first, single migration)**
1. **Cache table cleanup** — on `gitwire_repo_cache`: drop `page`, `has_more`, `connection_page` index; rename `updated_at` → `last_activity_at`, `created_at` → `updated_at`, `type_data` → `type_meta`; add `type` VARCHAR(20) with an index. On `gitwire_installed`: drop `subtype`, migrate existing rows (`type='theme'+subtype='block'` → `type='block-theme'`, else → `type='classic-theme'`). Add all `ALTER TABLE` statements to the upgrade path.
2. **Remove cache-age read checks** — remove `WHERE created_at > %d` from `get_repo_list()` and `AND created_at > %d` from `get_repo_type()`; update the class docblock on `Repo_Cache`.
3. **Installed table extension** — add `auto_update` and `auto_update_scope` columns to `gitwire_installed`.
4. **Bump `DB_VERSION`** — covers all table changes in one migration.

**Group B — Settings + Panel 1 (independent of Group A)**

5. **New setting keys + validation** — add `auto_detect_type`, `repos_per_page`, `excluded_repos`, `max_repos_per_source`, `background_type_detection`, `detection_batch_size`, `shallow_detection`, `block_on_fatal`, `update_check_interval` to `Settings::merge_save()` and `get_public()`.
6. **Panel 1 UI** — render Browse & Detection settings panel in JS; wire `POST /settings`.
7. **Exclude repos filter** — add `full_name NOT IN (...)` clause to `get_repo_list()`.
8. **Repos per page** — thread `repos_per_page` into `get_repo_list()` LIMIT and JS offset math.
9. **Server-side search and filter** — add `search`, `source`, and `type` params to `GET /repos`; add corresponding WHERE clauses to `get_repo_list()`; wire debounced search input and filter dropdowns in `browse-panel.js` to pass params on every load/load-more call; populate `type` column in `set_repo_type()` and clear it in `clear_repo_types()`.
10. **Max repos per source** — replace the page-based loop termination in `refresh_repo_lists()` with a `$total_fetched` counter.
11. **Background type pre-detection** — extend `cron_refresh_repo_types()` with a `NULL`-row batch pass when `background_type_detection` is on; implement batch cursor option and execution/rate-limit guards.
12. **Shallow detection** — extend `type_meta` writes to include `key_files` and `name`; add pattern-match shortcut in `Repo_Detector::detect()` when `shallow_detection` is on; skip shallow for low-confidence and unknown results (`key_files = []`).

**Group C — Panel 2 + Auto-update (depends on Group A step 3)**

13. **Panel 2 UI** — render Installed & Updates settings panel in JS; move `show_repo_label` here; wire `block_on_fatal` and `update_check_interval`.
14. **Update check interval** — reschedule or remove the update-check cron event when `update_check_interval` changes.
15. **Auto-update cron logic** — extend update-check cron to run silent installs for rows where `auto_update = 1`.
16. **Auto-update UI** — "Enable auto-update" action menu item + scope popup modal in Installed panel.

Group A must land before Group C. Groups B and C are otherwise independent of each other.

---

## Open questions summary

No unresolved questions — all decisions recorded above.
