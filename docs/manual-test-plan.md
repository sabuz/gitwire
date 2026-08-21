# Manual Test Plan

## Environment

- Site: http://gw.test/ (Laravel Valet)
- Plugins: User Switching installed
- Accounts: Administrator, Editor, Author (use User Switching to switch roles)

---

## Installation Tests

### Fresh Install

- [ ] Activate GitWire Free — no PHP errors at `WP_DEBUG=true`
- [ ] DB tables created: `gitwire_connections`, `gitwire_installations`, `gitwire_repositories`, `gitwire_commits`
- [ ] Admin menu item appears
- [ ] Welcome / onboarding screen shown

### Upgrade Install

- [ ] Install older version, then update to current — no PHP errors
- [ ] DB migration runs without data loss
- [ ] Existing connections and installations preserved

### Deactivation

- [ ] Deactivate — no PHP errors, no notices
- [ ] Reactivate — works cleanly

### Uninstall (data removal)

- [ ] Enable "Remove data on uninstall" setting
- [ ] Delete plugin — all `gitwire_*` tables removed, options cleared
- [ ] Disable "Remove data on uninstall"
- [ ] Delete plugin — tables remain

---

## Connection Tests

### Public Connection (GitHub)

- [ ] Add public GitHub connection with username
- [ ] Repositories load
- [ ] Rate limit displayed correctly
- [ ] Repository type detection shows "plugin" / "theme" badges correctly
- [ ] Invalid username shows error, no PHP warning

### Public Connection (GitLab)

- [ ] Add public GitLab connection
- [ ] Self-hosted GitLab URL accepted
- [ ] Repositories load

### Public Connection (Bitbucket)

- [ ] Add public Bitbucket workspace connection
- [ ] Repositories load

### Delete Connection

- [ ] Delete connection — removes connection and associated repositories from DB
- [ ] Installations from deleted connection remain (orphaned, marked as such)

---

## Installation Tests

### Install Plugin from GitHub

- [ ] Select a plugin repo, install latest commit on default branch
- [ ] Plugin appears in WP Plugins list
- [ ] Install log shows correct commit SHA

### Install Theme from GitHub

- [ ] Select a theme repo (classic or block), install
- [ ] Theme appears in WP Themes

### Install from URL

- [ ] Paste a GitHub/GitLab/Bitbucket repo URL directly
- [ ] Installs correctly without a saved connection

### Branch Selection

- [ ] Switch active branch for an installation
- [ ] Correct commit SHA recorded after branch change

### Update Check

- [ ] Push a commit to the remote
- [ ] Update available indicator appears
- [ ] Update installs correctly, SHA updated in DB

### Auto-Update

- [ ] Set auto-update to "current branch"
- [ ] WP cron triggers update on new commit
- [ ] Set auto-update to "disabled" — no automatic update

---

## Role Tests (switch via User Switching)

### Administrator

- [ ] Full access to all GitWire screens
- [ ] Can add/delete connections
- [ ] Can install/update/delete plugins and themes

### Editor

- [ ] No access to GitWire admin pages (403 or no menu item)

### Author

- [ ] No access to GitWire admin pages

---

## Pro Tests (requires GitWire Pro active)

### Private Connection

- [ ] Add GitHub private connection with PAT
- [ ] Private repos appear in repository list
- [ ] Credentials test passes / fails correctly
- [ ] Masked token preview shown (not full token)

### Credential Encryption

- [ ] Token stored encrypted in DB (not plain text in `gitwire_connections.credentials`)

### Per-User Scoping

- [ ] Create user-scoped connection
- [ ] Connection only visible to that WP user

### Pro Deactivation (keep data)

- [ ] Deactivate Pro — Free works normally
- [ ] `email`, `credentials`, `scope` columns remain in DB

### Pro Deactivation (remove data)

- [ ] Enable "Remove Pro data on deactivation"
- [ ] Deactivate — columns dropped from `gitwire_connections`
- [ ] Free continues to work normally

---

## UI / UX Tests

### Responsive Layout

- [ ] Admin pages usable at 1280px, 1024px, 768px widths
- [ ] No horizontal overflow

### Loading States

- [ ] Repository list shows loading spinner while fetching
- [ ] Install button shows loading state during install
- [ ] No blank screens during async operations

### Error Handling

- [ ] Network error during install shows user-friendly message
- [ ] Invalid API credentials show clear error (not raw API response)
- [ ] Rate limit exceeded shows helpful message with reset time

### Accessibility

- [ ] All interactive elements reachable by keyboard
- [ ] Focus visible on all interactive elements
- [ ] No color-only status indicators
- [ ] Screen reader: admin page heading structure logical

### Wording Review

- [ ] All labels, buttons, and headings in Title Case
- [ ] All help text and toast notifications in Sentence case
- [ ] No em-dashes in UI copy; use plain alternatives

---

## Settings Tests

### Repositories per Page

- [ ] Set "Repositories per Page" to 10 in Settings > Browse & Detection
- [ ] Go to Add Repository tab — confirm first page shows exactly 10 repos (assuming cache has 10+)
- [ ] Click "Load More" — confirm the next 10 repos append correctly (offset = 10)
- [ ] Change to 50 — refresh the page, confirm first page shows up to 50 repos
- [ ] `has_more` flag drives "Load More" visibility: present when total cached rows exceed per-page value, absent when all rows fit on one page

### Max per Source

- [ ] Set "Max per Source" to 100 in Settings > Browse & Detection
- [ ] Trigger a cron refresh (WP-CLI: `wp cron event run gitwire_repos_refresh` or use the Refresh button which calls clear-cache + reload)
- [ ] Confirm total repos shown for a single connection does not exceed 100
- [ ] Set to 250 — trigger refresh — confirm cap rises to 250
- [ ] Set to "No limit" — trigger refresh — confirm all repos are fetched and shown
- [ ] Confirm that `max_repos_per_source` does not affect the initial single-page cache warm (only full cron refresh is capped)

### Repository Type Refresh Frequency

- [ ] Settings > Browse & Detection shows "Repository Type Refresh Frequency" below "Repository Refresh Frequency", defaulting to Weekly
- [ ] Change it, then confirm `wp cron event list` shows `gitwire_refresh_repository_types` on the new recurrence
- [ ] Set to "Never" and confirm `gitwire_refresh_repository_types` is gone from the cron list
- [ ] Run `wp cron event run gitwire_refresh_repositories` and confirm the repo list updates while stored types are left alone
- [ ] Run `wp cron event run gitwire_refresh_repository_types` and confirm installed repos are typed again

### Refresh Repositories vs Refresh Types

- [ ] Add Repository tab: "Refresh Repositories" relists repos and leaves stored types alone, so badges stay put with no detect-batch calls in the network tab
- [ ] Add a repo on the provider side, then "Refresh Repositories" again: the new repo appears with no stored type and gets a detect-batch call, while every already-typed repo is untouched
- [ ] The chevron next to it opens a menu with "Refresh Repositories & Types" and "Refresh Types Only"
- [ ] "Refresh Repositories & Types" relists repos and drops stored types, so badges clear and then repopulate from fresh detect-batch calls
- [ ] "Refresh Types Only" leaves the list untouched (no fetch_repositories calls) but drops stored types the same way, so badges clear and repopulate without the list itself changing
- [ ] All three modes keep the current list on screen when a connection errors, and surface a toast per failing connection
