# Known Bugs

## Open

_None currently open._

## Fixed

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
Slice each page to the remaining budget before adding it to `fetched_full_names`, so the existing `remove_stale()` cleanup (which deletes anything not in that list) prunes the overflow down to the configured cap. gitwire `a7e5309`.

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

**Free plugin** — plain links in the top-right header area: Docs, Support.

**Pro plugin** — a single dropdown button in the top-right. Clicking opens a dropdown menu with Connect/Disconnect (label toggles on license state), Docs, and Support.

**Fix:**
Free plugin: added a Support link (`https://gitwire.app/support`) next to the existing Docs link, both plain top-right links. gitwire `3d8b3ec`.

Pro plugin: `LicenseButton` was a bare activate/deactivate popover with no Docs or Support entry points, "Deactivate" instead of "Disconnect", and no confirmation before dropping a connected license. Rebuilt as a dropdown menu (Connect/Disconnect, Docs, Support) matching the existing `connection-picker.js` Dropdown pattern. Connect opens an inline license-key form (Enter-to-submit, inline error). Disconnect shows a confirmation dialog first ("Are you sure you want to disconnect your license? This will deactivate GitWire Pro on this site.") since disconnecting can consume a license activation slot; on confirm it clears the key and the menu label flips back to "Connect" in place, no reload. gitwire-pro `061d4b7`.

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
