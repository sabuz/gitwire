# Known Bugs

## Open

_None currently open._

## Fixed

### Public repos prompted to reconnect after their connection was deleted

**Status:** fixed
**Affects:** free
**Reported:** 2026-08-22

**Symptoms:**
Install a public repo through a connection, then delete that connection while another one remains. The Installed row shows a "Connection Required" badge and a Reconnect action, even though nothing is broken: updates keep working.

**Root cause:**
`annotate_installed()` set `needs_reconnect` purely on the connection ID no longer resolving. That is the right test for a private repo and meaningless for a public one, which `Provider_Factory` already falls back to an anonymous client for — `get_credentials()` returns null for a dead ID and the factory builds a token-less client from it. The record had no way to tell the two apart: the installations table stored no privacy flag, and by the time the badge renders the browse-cache rows that knew are gone, deleted along with the connection.

**Fix:**
`gitwire_installations` gained a `private` column, written at install time from the browse-cache row. A URL import has no such row, so it falls back to whether a connection was needed to reach the repo at all. `needs_reconnect` now requires the repo to be private.

**Note:** existing rows default to `private = 0`, so a private install predating this change stops prompting until it is reinstalled. Acceptable pre-release; there is no migration to backfill it.

---

### Browse detection exhausted GitHub's anonymous rate limit in one page load

**Status:** fixed
**Affects:** free
**Reported:** 2026-08-22

**Symptoms:**
With a public (token-less) GitHub connection and Auto-Detect on, opening the Add Repository tab burned through GitHub's unauthenticated allowance almost immediately. Everything needing the API afterwards failed for the rest of the hour: Import from URL, install, branch listings. Cards for repositories the request gave up on sat on a "Detecting…" spinner that never resolved.

**Root cause:**
Unauthenticated GitHub allows 60 requests per hour per IP. `detect_batch()` handles 10 repositories per request, each costing a root listing plus up to five file fetches — the endpoint's own docblock notes "ten of them can be seventy round trips". At the default 50 repositories per page, `runBatch()` fires five such requests, so one page load could ask for several hundred calls against a budget of 60. Even the floor case of one call per repository spends 50 of the 60.

`run_background_detection()` guarded every row against `gitwire_gh_rl_*`. `detect_batch()`, the interactive path, had no such guard, and silently omitted anything it skipped from the response.

**Fix:**
`detect_batch()` holds back a `DETECTION_RESERVE` of 15 requests for user-initiated work (installing, branch listings, resolving a pasted URL) and declines speculative lookups below it. The background job keeps its higher floor of 50, since it is not even on screen.

Skipped repositories are now named in the response under `paused`, with the reason, instead of being dropped — the omission is what left cards spinning. The client marks them, stops queueing further chunks that would be refused on the same grounds, and shows a "Detection Paused" badge plus a notice explaining why. They stay installable, and opening one detects that single repository, which is what the reserve is for.

Paused repositories are deliberately kept out of the detections map rather than given a placeholder, so nothing downstream mistakes them for a real result.

---

### "Refresh Repositories" truncated large connections to a single API page

**Status:** fixed
**Affects:** free
**Reported:** 2026-08-22

**Symptoms:**
A connection with more than 100 repositories loses everything past the first 100 when the Browse tab's Refresh button is used. The missing repos come back on the next scheduled refresh, so it reads as an intermittent disappearance.

**Root cause:**
`REST_Repositories::clear_cache()` fetched page one per connection and then called `remove_stale_since()` anyway. Rows on later pages still carried an `updated_at` from before the cycle stamp, so the prune deleted them — the same shape as the bug its own docblock claimed to have fixed, moved from the whole list to its tail. The endpoint also ignored the per-source cap the cron sweep honoured, since removed as a setting.

**Fix:**
`Repositories::refresh_repositories()` became the single sweep both paths run: it walks every page, honours the cap, prunes only once a connection has answered its last page, and parks a resume cursor when the time budget runs out. `clear_cache()` now calls it and maps its per-connection errors straight into the response.

---

### Every Import from URL failure reported as "not found or you don't have access"

**Status:** fixed
**Affects:** free
**Reported:** 2026-08-22

**Symptoms:**
Pasting a public repository URL returns "Repository not found or you don't have access." even though the repository is public and plainly exists. Correlates with Smart Install being on, which is misleading — Smart Install is nowhere in this code path.

**Root cause:**
`resolve_repo()` computed `is_public` as `! is_wp_error( $detected )`, collapsing every failure mode into one. A rate-limit 403, a 5xx, and a DNS failure were all reported as an access problem, and the provider's own message was discarded. The Smart Install correlation is indirect: Smart Install forces Auto-Detect on, Auto-Detect makes the Browse tab run `detect-batch` over the whole page, and that exhausts GitHub's 60/hour anonymous limit — after which resolve gets a 403 and mislabels it.

**Fix:**
`describe_resolve_failure()` splits access from everything else. Only 401 and 404 route to the private/not-found path, since GitHub deliberately answers 404 for a private repo so an anonymous caller cannot distinguish it from a missing one. A 403 or 429 gets a rate-limit message, anything else surfaces the provider's own text, and both land on the error step instead of the access prompt. `handleConnectAndContinue`'s catch got the same split, so a rate limit no longer looks like "this connection cannot see it".

**Follow-up:** the underlying exhaustion is fixed separately, see "Browse detection exhausted GitHub's anonymous rate limit in one page load" above.

---

### Install modal detected and preselected the type with Auto-Detect off

**Status:** fixed
**Affects:** free
**Reported:** 2026-08-22

**Symptoms:**
With Auto-Detect Repository Type switched off, browse cards correctly showed no type badge, but clicking Install still showed a detection badge and silently preselected plugin or theme. The toggle's own help text promises the opposite: "When off, Gitwire asks whether to install as plugin or theme at install time."

**Root cause:**
`InstallForm` was never given the setting — no caller passed it and the component did not accept it. Its mount effect called `api.detectRepo()` whenever no detection was handed in, then overwrote the selected type from the result. The "Install As" selector was gated on `detection?.type === 'unknown'`, so a successful detection kept it hidden and the user was never asked. `import-from-url.js` carried its own copy of the same badge-and-selector logic with the same gap, and its `installType` fell through to `undefined` when detection was null.

**Fix:**
Both forms take `autoDetectType`. The detect call is skipped in the install modal (the answer would only be discarded), the badge is not rendered, and "Install As" is shown whenever detection is off — not only on an unknown result. Import from URL still makes its resolve and connect round trips, since those prove the repo is reachable and drive the private-repo path, but stops using the result to type or to badge.

---

### Repository activity dates shifted by the viewer's timezone

**Status:** fixed
**Affects:** free
**Reported:** 2026-08-22

**Symptoms:**
The "last updated" line on a browse card is wrong by a fixed number of hours, matching the browser's UTC offset. East of UTC every repo looks staler than it is; west of UTC, a repo pushed hours ago reads "just now", because the parsed date lands in the future and the relative-time helper's `s < 60` branch catches it.

**Root cause:**
`last_activity_at` is written with `gmdate( 'Y-m-d H:i:s' )` and reached the client as that bare string. `Date()` does not treat the space-separated form as UTC — it parses it as local time.

**Fix:**
`Repository::get_paginated()` converts the column to ISO-8601 with an explicit `Z` on the way out, and maps the `1970-01-01` sentinel (written when a provider gives no usable date) to an empty string so the card omits the row instead of rendering "56y ago".

---

### Detection results cached inconsistently between the single and batch endpoints

**Status:** fixed
**Affects:** free
**Reported:** 2026-08-22

**Symptoms:**
The same repository could be typed on one code path and re-typed on every request through another, and a rate-limit blip during a single detect pinned a repo as "unknown" until the cache was cleared by hand.

**Root cause:**
`detect_repo()` read and wrote the detection cache only for unauthenticated lookups, on the theory that a connection-scoped result might be private. `detect_batch()` cached unconditionally. Detection is not connection-scoped — it is per provider and repository — so the guard bought nothing and only made the two endpoints disagree. Worse, the unauthenticated branch it did allow was the one that cached failures as `unknown`, which `detect_batch()` explicitly refuses to do.

**Fix:**
`detect_repo()` now reads and writes the cache the same way `detect_batch()` does, and neither persists an error result. The unauthenticated caller still gets an `unknown` payload rather than an error, so the existing UI flows are unchanged; it is just never stored.

---

### Public connection silently hidden when a Pro private connection is added for the same account

**Status:** fixed
**Affects:** both
**Reported:** 2026-06-28

**Symptoms:**
A user adds a public connection (username only) for e.g. GitHub. Later they add a Pro private connection with a PAT for the same account. The public connection disappears from the connections list — it still exists in the DB but is no longer shown. When the private connection is removed or Pro is disabled, the public connection reappears unexpectedly. The user has likely forgotten it was ever there.

**Root cause:**
The UI (or the REST response) suppresses the public connection when a private connection exists for the same provider/identifier. The intent was to prevent showing duplicates, but the suppression is silent — no indication to the user that the public connection still exists and will resurface.

**Fix:**
`merged_list()` in gitwire-pro already tagged shadowed public rows with `status: inactive` instead of filtering them out, but the JS side never read that field — the connections list showed the same badges as an active connection, and selecting the row crashed on a reference to `InactiveConnectionCard`, which didn't exist anywhere in the codebase. Added the missing component (provider, identifier, host URL, and a no-confirmation "Remove Connection" button — no rate limit, avatar, name, or authenticated state, per spec) and wired the "Inactive" badge into the connections list row. gitwire-pro `69ef87f`; matching `.is-inactive` greyed-out row style in gitwire `87ff2bf`.

---

### Email field inconsistency between public and Pro connections

**Status:** fixed
**Affects:** both
**Reported:** 2026-06-28

**Symptoms:**
Free (public) connections store `NULL` for the `email` field. Pro (private) connections store a blank string `""` instead of `NULL` when no email is provided. Inconsistent across the two plugins.

**Root cause:**
`Connections::upsert()` already computed `$email = null` correctly in PHP, but the INSERT branch for new connections built the query with a hand-rolled `$wpdb->prepare( "INSERT ... VALUES (%s, ...)", ...)`. `wpdb::prepare()` has no NULL-passthrough for `%s` — it escapes `null` to an empty string before it ever reaches SQL. The UPDATE branch a few lines up already used `$wpdb->update()`, which special-cases `null` before formatting and writes real SQL `NULL` — so the same connection could get `''` on creation and `NULL` on its next credential update.

**Fix:**
Switched the INSERT branch to `$wpdb->insert()`, giving it the same NULL handling as the UPDATE branch. gitwire-pro `09d3c74`.

**Related:** the same NULL-vs-empty-string split existed for `host_url`, roles reversed — `Public_Connections::add()` (free) stored the raw `''` default for connections with no self-hosted URL (GitHub, Bitbucket, plain GitLab.com), while Pro's `upsert()` already normalized `''` to `null` for the same column. Normalized `add()` to do the same. gitwire `1f392bf`.

---

### "Repositories per Page" and "Max per Source" settings not respected

**Status:** fixed
**Affects:** both
**Reported:** 2026-06-28

**Symptoms:**
The "Repositories per Page" and "Max per Source" settings exist in plugin settings but are not applied when fetching or displaying repository lists.

**Root cause:**
"Repositories per Page" was already applied correctly end to end (`Repositories::get_repositories()` → `Repository::get_paginated()` → SQL `LIMIT`/`OFFSET`, JS "Load More" reads the offset from `has_more`). "Max per Source" was the actual bug: `refresh_repositories()` stopped requesting *new* pages once the running total passed the cap, but every page already fetched was stored in full. The provider API's page size is a fixed 100, which doesn't evenly divide 250 (one of the three preset cap values), so a cap of 250 let 300 repos land in the cache before the loop noticed.

**Fix:**
Slice each page to the remaining budget before storing it, so a cap that does not divide the provider's fixed 100-row page size still lands exactly on the cap. gitwire `a7e5309`.

**Superseded:** "Max per Source" has since been removed as a setting. A thousand repositories is ten API requests, 0.2% of GitHub's hourly budget, and the sweep is already bounded by `gitwire_refresh_time_budget` and its resume cursor — so the cap was solving a cost problem that did not exist. The stale cleanup this fix leaned on (`remove_stale()` against a `fetched_full_names` list) was also replaced by `remove_stale_since()`, which prunes on a per-cycle `updated_at` stamp so a sweep can span several cron ticks.

---

### Search in "Add Repository" panel breaks with unloaded repositories

**Status:** fixed (already resolved prior to this audit)
**Affects:** both
**Reported:** 2026-06-28

**Symptoms:**
Search in the "Add Repository" panel only searches the currently loaded/paginated set of repositories, not the full repository list. Repositories not yet fetched are invisible to search.

**Verification:**
Traced the full path: `RepositoryBrowser`'s search box already debounces (350ms) into a server request (`loadRepos( 0, false, search, ... )`) that resets to offset 0 and passes `search` to `GET /repos`, which filters server-side over the `gitwire_repositories` cache table (`name LIKE %s OR owner LIKE %s`), not the JS-loaded array. This mechanism predates the bug report (present since commit `b5efc0d`, 2026-06-20); the client-side `matchesSearch` filter is only a same-tick preview of the already-loaded set while the debounced request is in flight, not the only search path. No separate/duplicate search implementation exists in gitwire-pro. No code change made — this entry appears to have been stale in the doc.

---

### Bitbucket workspace name displayed with @ prefix

**Status:** fixed
**Affects:** both
**Reported:** 2026-06-28

**Symptoms:**
Bitbucket workspace names are shown with a leading `@` in the UI (e.g. `@acme-org` instead of `acme-org`). The `@` was already removed from the Bitbucket email field — the same fix was not applied to the workspace name.

**Fix:**
Guarded on `'bitbucket' !== provider` everywhere the identifier is rendered with an `@` prefix. Already fixed in gitwire (`a03a11f`) and gitwire-pro's connections list/detail (`3d752d2`) prior to this audit. One remaining unguarded instance found in gitwire-pro's `connection-picker.js` (the "Try to connect with a saved account" dropdown shown from Import from URL) — fixed in `ce2af60`.

---

## Tasks

### Plugin header — free vs Pro link area

**Status:** fixed

**Design:**

**Free plugin** — plain links in the top-right header area: Docs (Support omitted for now — see Fix).

**Pro plugin** — a single dropdown button in the top-right. Clicking opens a dropdown menu with Connect/Disconnect (label toggles on license state), Docs, and Support.

**Fix:**
Free plugin: added a Docs link (no underline, matching the rest of the plugin's link styling) next to the existing spot, hidden when Pro's header dropdown is active (Pro already surfaces its own Docs pointing at the same URL, so showing both was redundant) — detected via whether Pro has registered the `gitwire.header.actions` filter, the same signal already used to render Pro's dropdown. A Support link was added and then removed again: the support page isn't live yet, so free only shows Docs until it is. Pro's dropdown Support item stays as is (separate decision, not tied to the free plugin's page readiness). External links use the same `&#8599;` entity icon as the repo card/installed panel (`ExternalLinkIcon`), not the `@wordpress/icons` `external` SVG icon. gitwire `3d8b3ec`, `c0fc1cd`, `9c77b5c`, `43c0d30`, `6c58d41`.

Pro plugin: `LicenseButton` was a bare activate/deactivate popover with no Docs or Support entry points, "Deactivate" instead of "Disconnect", and no confirmation before dropping a connected license. Rebuilt as a dropdown menu (Connect/Disconnect, Docs, Support) using `DropdownMenu`/`MenuGroup`/`MenuItem` from `@wordpress/components` — the new Storybook `Menu` component turned out to be a locked private API (gated to an internal core-package allowlist; throws if a plugin opts in), so `DropdownMenu` is the actual public equivalent. Connect opens a centered modal (matching the Import from URL modal's pattern, 400px, with help text on what the license unlocks) instead of a cramped inline form in the dropdown's popover. Disconnect shows a confirmation dialog first ("Are you sure you want to disconnect your license? This will deactivate GitWire Pro on this site.") since disconnecting can consume a license activation slot; on confirm it clears the key and the menu label flips back to "Connect" in place, no reload. Docs uses the same URL as the free plugin's link (Pro doesn't get separate documentation). External links use the entity-based `ExternalLinkIcon` directly after the label text, matching free's repo card/installed panel convention. gitwire-pro `061d4b7`, `94b4685`, `9fa6ea5`, `1224e20`.

Changelog/account-portal links were left out — no such pages exist yet to link to.

---

## Template

```
### [Short title]

**Status:** open | investigating | blocked | fixed in X.X.X
**Affects:** free | pro | both
**Reported:** YYYY-MM-DD
**WordPress version:** X.X
**PHP version:** X.X

**Symptoms:**
What the user sees.

**Root cause:**
What's actually wrong (once known).

**Workaround:**
If any.

**Fix:**
PR / commit reference once resolved.
```
