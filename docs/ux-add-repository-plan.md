# Gitwire — Add repository / Installed-first build plan

Implementation plan for the UX in [`ux-add-repository-flow.md`](ux-add-repository-flow.md). The driving idea: the redesign quietly bundles **three** large changes — a UX shell rewrite, a credentials **data-model migration**, and badge + delete-guard. Landing them together is how it slips. Ship in three phases so the high-value, low-risk UX wins don't wait on the migration.

> Effort markers are relative: ● small, ●● medium, ●●● large.

---

## Phase 1 — UX shell (no data-model change)

Pure front-end + one new read-only endpoint. Free tier. No change to how credentials are stored, so zero migration risk. This is where most of the perceived improvement lives.

### Scope

1. **Remove the top-level Browse tab.** ●
   - Drop `browse` from `TABS` and `PATH_TO_TAB` in [`app.js`](../src/app.js); remove the submenu item in `class-admin.php`.
   - Redirect `?path=browse` → open the Add repository panel on the Browse sub-tab (back-compat for bookmarks/submenu links).
2. **Replace the Connect wall with an action-oriented empty state.** ●
   - Retire `connect-prompt.js` as the gate. Empty Installed shows **Add repository** (single label everywhere) + secondary line "You can paste a public link without connecting an account."
3. **Add repository surface — full-width slide-over / route, not a nested modal.** ●●
   - Two sub-tabs: **Browse my repositories** (lift current `browse-panel.js` behavior in as-is) and **Import from URL**.
   - Default sub-tab by connection state: no connection → Import from URL; connected → Browse. Remember last-used.
   - Avoid modal-in-modal: resolve type/branch/slug/connection sub-states inline.
4. **Import from URL flow.** ●●
   - URL field + **Check repository** button (not "Check connection").
   - New endpoint `POST /repos/resolve` — parses URL → `(provider, owner, repo, branch?)`, attempts anonymous read, returns public/private + detected type. Reuses existing `detect_type` / `Repo_Detector`.
   - URL parser handles: `.git` suffix, `/tree/<branch>`, trailing slashes, GitLab nested namespaces, self-hosted GitLab hosts (reuse `Settings::is_allowed_gitlab_url`).
   - Public → type / branch / slug-conflict / Install (same rules as current install modal, Smart Install respected).
   - Private/404 → connection picker using the **single existing** connection only (multi comes in Phase 2). Treat 404 as ambiguous: "not found, private, or no access."

### Out of scope for Phase 1
Multiple connections, per-user connections, badges, delete guard. Private URL import works only against the one site-wide connection per provider that already exists.

### Done when
A fresh install with no credentials can paste a public GitHub/GitLab/Bitbucket URL and install it; Browse still works for a connected account; no Browse tab in nav; empty state never shows a "Connect" wall.

---

## Phase 2 — Connections data model + migration

The real engineering lift. Do it on its own so a migration bug can't take down Phase 1's UX. Pro tier features ride on top.

### Scope

1. **New credential model.** ●●●
   - `gitwire_connections`: collection of `{ id, provider, label, scope: site|user, user_id?, credentials, is_default }`.
   - **Encrypt `credentials` at rest** (key derived from `wp_salt()`) — applies to free single-connection too.
   - `Provider_Factory::make()` resolves a connection (by id, or provider default) instead of reading the single token field. Filter seam: `gitwire_provider_factory_auth`.
2. **Migration from `gitwire_settings`.** ●●
   - Convert existing `token`/`gitlab_token`/`bitbucket_*` into one site-scoped default connection per provider. Idempotent, reversible-safe. Keep reading old keys until migrated.
3. **`connection_id` on installed records.** ●●
   - Stamp the connection used at install time onto each `gitwire_installed` record so updates/sync stay on the right account. `sync_installed()` / `fetch_remote_head()` resolve via `connection_id`, fall back to provider default.
4. **Pro UX.** ●●
   - Multiple connections per provider with a default; Browse account switcher ("Showing: Personal (default) ▾").
   - Per-user (personal) vs site connections — pickers show current user's personal + permitted site connections.
   - **Loud honesty copy:** personal = "kept out of colleagues' pickers," not "private/secure." (See review note in flow doc.)
5. **Orphaned-connection state.** ●●
   - Per-row "Needs connection — reconnect" when an install's `connection_id` no longer resolves. Decide re-own rules.

### Done when
A user can save multiple accounts per provider, mark a default, scope a connection to themselves, install a private repo with a chosen connection, and have updates keep using it. Old single-token configs migrate transparently.

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

## Filter seams to land early (Phase 1–2)
So the Pro plugin hangs off stable hooks rather than forks (ties to the free/pro add-on split):

- `gitwire_providers` — provider registry, replaces hardcoded `if/elseif` in `Provider_Factory`.
- `gitwire_provider_factory_auth` — credential/connection resolution.
- `gitwire_can_install_repo` — install/delete capability gate.
- `gitwire_installed_record` — annotate records with `connection_id` / `auth_mode` / installer identity.

## Sequencing rationale
Phase 1 ships visible UX value with no migration risk. Phase 2 isolates the one change that can corrupt user data (credentials migration). Phase 3 is additive polish that needs Phase 2's metadata. Each phase is independently shippable.
