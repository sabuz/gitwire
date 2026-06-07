# Gitwire — Add repository / Installed-first build plan

Implementation plan for the UX in [`ux-add-repository-flow.md`](ux-add-repository-flow.md). The driving idea: the redesign quietly bundles **three** large changes — a UX shell rewrite, a credentials **data-model migration**, and badge + delete-guard. Landing them together is how it slips. Ship in three phases so the high-value, low-risk UX wins don't wait on the migration.

> Effort markers are relative: ● small, ●● medium, ●●● large.

---

## Phase 1 — UX shell (no data-model change) ✓ DONE

Pure front-end + one new read-only endpoint. Free tier. No change to how credentials are stored, so zero migration risk. This is where most of the perceived improvement lives.

### Scope

1. **Remove the top-level Browse tab.** ● ✓
   - Dropped `browse` from `TABS` and `PATH_TO_TAB` in [`app.js`](../src/app.js); removed the submenu item in `class-admin.php`.
   - `?path=browse` redirects to `add-repository` (back-compat).
2. **Replace the Connect wall with an action-oriented empty state.** ● ✓
   - `connect-prompt.js` removed. Empty Installed shows **Add repository** + "Install plugins and themes directly from GitHub, GitLab, or Bitbucket."
3. **Add repository surface.** ●● ✓ (intentional scope reduction)
   - Browse panel renders directly; Import from URL opens in a modal. Sub-tabs, connection-state defaulting, and last-used memory were intentionally skipped — the current shape is good enough for Phase 1.
4. **Import from URL flow.** ●● ✓
   - URL field + **Check repository** button.
   - `POST /repos/resolve` endpoint live — parses URL, attempts anonymous read, returns public/private + detected type.
   - Public → type / branch / slug-conflict / Install (Smart Install respected).
   - Private/404 → connection picker (single site-wide connection per provider).

### Out of scope for Phase 1
Multiple connections, per-user connections, badges, delete guard. Private URL import works only against the one site-wide connection per provider that already exists.

---

## Phase 2 — Connections data model + migration

The real engineering lift. Do it on its own so a migration bug can't take down Phase 1's UX. Pro tier features ride on top.

### Scope

1. **New credential model.** ●●●
   - `gitwire_connections`: collection of `{ id, provider, label, scope: site|user, user_id?, credentials, is_default }`.
   - **Encrypt `credentials` at rest** (key derived from `wp_salt()`) — applies to free single-connection too.
   - `Provider_Factory::make()` resolves a connection (by id, or provider default) instead of reading the single token field. Filter seam: `gitwire_provider_factory_auth`.
   - No migration needed — plugin is pre-release, no live credentials to preserve. Replace the old `gitwire_settings` token fields directly.
2. **`connection_id` on installed records.** ●●
   - Stamp the connection used at install time onto each `gitwire_installed` record so updates/sync stay on the right account. `sync_installed()` / `fetch_remote_head()` resolve via `connection_id`, fall back to provider default.
3. **Pro UX.** ●●
   - Multiple connections per provider with a default; Browse account switcher ("Showing: Personal (default) ▾").
   - Per-user (personal) vs site connections — pickers show current user's personal + permitted site connections.
   - **Loud honesty copy:** personal = "kept out of colleagues' pickers," not "private/secure." (See review note in flow doc.)
4. **Orphaned-connection state.** ●●
   - Per-row "Needs connection — reconnect" when an install's `connection_id` no longer resolves. Decide re-own rules.

### Done when
A user can save multiple accounts per provider, mark a default, scope a connection to themselves, install a private repo with a chosen connection, and have updates keep using it.

---

## Phase 3 — Badge + delete guard

Cheapest-to-ship, lowest-risk, depends on `connection_id` / installed metadata from Phase 2 for the "installed by X" attribution.

### Scope

1. **Gitwire badge on native lists.** ●
   - `plugin_row_meta` + theme card: small "Gitwire" badge, tooltip "Installed from owner/repo on <provider>." Free (light), Pro optional link to Installed row.
2. **Delete guard — split by surface.** ●●
   - **Gitwire Installed table** (we own UI): full confirm modal, "installed by X," Remove-from-Gitwire vs Delete-files.
   - **Native screen:** light only — capability-gated `delete_plugin` server-side hook (filter `gitwire_can_install_repo` / a delete equivalent) that blocks with a clear notice; no injected modal. Honesty copy: "warns / can restrict," not "protects."

### Done when
Installed items are identifiable on the native Plugins/Themes screens, and a cross-user delete of a personally-installed repo is surfaced (in Gitwire) / safely gated (native), with copy that explains who and why.

---

---

## Free vs Pro — restrictions to enforce in a future phase

Documented here so the gating requirements are clear when we split the codebase.

### Free tier
- **One connection per provider** (GitHub / GitLab / Bitbucket). Adding a second connection to the same provider requires Pro. The Settings UI reflects this: providers that already have a connection are shown as disabled in the "Add account" picker with a "Pro" badge.
- **Public repos only.** A free connection raises the API rate limit and enables Browse, but installing private repos is a Pro feature. Planned enforcement points:
  - Settings card: help text on the connect form — "Free accounts can browse and install public repositories. Upgrade to Pro for private repository access."
  - `POST /install` and `POST /repos/resolve`: server-side guard — if `is_pro` is false and the resolved repo is private, return a `pro_required` WP_Error.
  - Browse panel: private repo cards show a "Pro" badge instead of an Install button when `is_pro` is false.
- `is_pro` is exposed via the `gitwire_is_pro` PHP filter (default `false`). The Pro add-on hooks this to `true`.

### Pro tier
- Multiple connections per provider, each with a label and a default flag.
- Private repo access across Browse, Import from URL, and updates.
- Per-user (personal) connections scoped to a single admin (Phase 2 data model already supports `scope: user`).

### Filter seams already in place
- `gitwire_is_pro` — Pro plugin sets this to `true`.
- `gitwire_provider_factory_auth` — credential/connection resolution override.
- `gitwire_can_install_repo` — install/delete capability gate.

---

## Filter seams to land early (Phase 1–2)
So the Pro plugin hangs off stable hooks rather than forks (ties to the free/pro add-on split):

- `gitwire_providers` — provider registry, replaces hardcoded `if/elseif` in `Provider_Factory`.
- `gitwire_provider_factory_auth` — credential/connection resolution.
- `gitwire_can_install_repo` — install/delete capability gate.
- `gitwire_installed_record` — annotate records with `connection_id` / `auth_mode` / installer identity.

## Sequencing rationale
Phase 1 ships visible UX value with no migration risk. Phase 2 isolates the one change that can corrupt user data (credentials migration). Phase 3 is additive polish that needs Phase 2's metadata. Each phase is independently shippable.
