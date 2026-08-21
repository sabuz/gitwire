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
- [ ] A public repo that is not a WordPress project (e.g. `https://github.com/wintercms/winter.git`) resolves and reports what it is, rather than claiming it cannot be found
- [ ] With Smart Install on, that same repo is blocked with the Smart Install explanation, not an access error
- [ ] Exhaust the anonymous GitHub limit (`wp transient set gitwire_gh_rl_anon 0`, then load Browse a few times), paste any public URL, and confirm the message names the rate limit — "not found or you don't have access" must not be shown for a repo the site simply could not reach
- [ ] A genuinely missing repo (`https://github.com/wintercms/does-not-exist`) still shows the not-found/private path with the connect-an-account prompt

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

### Settings Save Failures

- [ ] Block `POST /gitwire/v1/settings` in devtools, then change Repositories per Page and Excluded Repositories in turn: each reverts to its previous value and shows an error toast, rather than displaying a value the server never accepted
- [ ] Changing Repository Refresh Frequency does **not** empty the Browse tab: the cached list stays on screen and only `wp cron event list` shows the new recurrence

### Repositories per Page

- [ ] Set "Repositories per Page" to 10 in Settings > Browse & Detection
- [ ] Go to Add Repository tab — confirm first page shows exactly 10 repos (assuming cache has 10+)
- [ ] Click "Load More" — confirm the next 10 repos append correctly (offset = 10)
- [ ] Change to 50 — refresh the page, confirm first page shows up to 50 repos
- [ ] `has_more` flag drives "Load More" visibility: present when total cached rows exceed per-page value, absent when all rows fit on one page

### Repository Activity Dates

- [ ] Add Repository tab: a repo pushed to minutes ago reads "just now", not a whole number of hours, on a browser whose timezone is not UTC (check in both a positive and a negative offset — `TZ='Asia/Dhaka'` and `TZ='America/Los_Angeles'`)
- [ ] Hovering the clock icon shows a local-time tooltip matching the provider's own "updated" timestamp for that repo
- [ ] A repo whose provider reports no usable date shows no clock row at all, rather than a date in 1970

### Repository Type Refresh Frequency

- [ ] Settings > Browse & Detection shows "Repository Type Refresh Frequency" below "Repository Refresh Frequency", defaulting to Weekly
- [ ] Change it, then confirm `wp cron event list` shows `gitwire_refresh_repository_types` on the new recurrence
- [ ] Set to "Never" and confirm `gitwire_refresh_repository_types` is gone from the cron list
- [ ] Run `wp cron event run gitwire_refresh_repositories` and confirm the repo list updates while stored types are left alone
- [ ] Run `wp cron event run gitwire_refresh_repository_types` and confirm installed repos are typed again

### Type Settings Follow Auto-Detect

- [ ] With Smart Install on and `wp option patch update gitwire_settings auto_detect_type 0` applied behind the UI, the Settings screen still shows Auto-Detect as on **and** the Browse tab still shows type badges — the two must not disagree, since Smart Install implies detection
- [ ] Set Repository Type Refresh Frequency to Daily, then turn off Smart Install followed by Auto-Detect Repository Type: the control greys out but keeps reading Daily, alongside Background Type Pre-Detection and Shallow Detection switching off
- [ ] Confirm `gitwire_refresh_repository_types` disappears from `wp cron event list`, so the greyed-out control isn't quietly still running
- [ ] Run `wp cron event run gitwire_refresh_repository_types` by hand in that state and confirm no repos are typed, since the callback rechecks the setting rather than trusting the schedule
- [ ] Turn Auto-Detect back on: the control re-enables still reading Daily, and `gitwire_refresh_repository_types` returns to the cron list on that same recurrence without needing to be set again
- [ ] Repeat with Smart Install as the switch being toggled instead of Auto-Detect, since either one keeps detection alive
- [ ] With Auto-Detect off, the Add Repository tab's chevron next to "Refresh Repositories" is gone entirely, so stored types can't be dropped with nothing left to rebuild them
- [ ] "Refresh Repositories" itself stays enabled in that state and renders with normal rounded corners, not the squared-off edge it uses when paired with the chevron
- [ ] With Auto-Detect off, repo cards show no type badge and no "Detecting…" spinner, since nothing will ever resolve it
- [ ] Cards for already-installed repos still show their Plugin/Theme badge in that state, since it comes from the install record rather than detection
- [ ] With Auto-Detect off, the Filter popover lists only the source options, with the Plugin / Block Theme / Classic Theme / Unknown group gone
- [ ] Set a type filter first, then turn Auto-Detect off: the list is not silently filtered by it, and the filter dot and Clear Filters label ignore it
- [ ] With Auto-Detect off and only one provider connected, the Filter button and the divider beside it are gone entirely rather than opening an empty popover
- [ ] With Auto-Detect off, clicking Install opens the modal with no detection badge, no "Detecting project type…" spinner, and an "Install As" dropdown defaulting to Plugin — and the network tab shows no `/detect` call, since the answer would only be discarded
- [ ] Installing a known theme repo in that state as "Theme" lands in Themes, not Plugins: the dropdown choice is what ships, not a detected type
- [ ] Import from URL behaves the same with Auto-Detect off — no badge, "Install As" shown — while still routing a private repo to the connect step, since that resolve call proves reachability rather than type
- [ ] Turn Smart Install on (which forces Auto-Detect on) and confirm the badge and the automatic type selection both come back
- [ ] Turn Auto-Detect back on and confirm the chevron, the badges, the spinner, and the type filters all return

### Background Type Pre-Detection

- [ ] `wp cron event list` shows `gitwire_background_type_detection` scheduled every 30 minutes regardless of either refresh frequency setting, including when Repository Type Refresh Frequency is "Never"
- [ ] With the toggle off, `wp cron event run gitwire_background_type_detection` makes no detect_type_for_repo calls
- [ ] Force the stored flag back on behind the UI (`wp option patch update gitwire_settings background_type_detection 1`) while Smart Install and Auto-Detect are both off, then run the cron and confirm it still detects nothing
- [ ] With the toggle on and some cache rows untyped, `wp cron event run gitwire_background_type_detection` types a batch of them without touching `gitwire_refresh_repositories` or `gitwire_refresh_repository_types`
- [ ] Deactivating the plugin clears `gitwire_background_type_detection` from the cron list; reactivating restores it
- [ ] With a GitHub connection whose `gitwire_gh_rl_{connection_id}` transient reads 3000+, run `wp cron event run gitwire_background_type_detection` and confirm it pulls up to 100 untyped rows instead of 25 (check `gitwire_detection_cursor` advancement or add `error_log` to `Repository::get_untyped_batch()` temporarily)
- [ ] With that transient reading below 3000, or no GitHub connection configured at all, confirm the batch stays at 25
- [ ] Confirm a GitLab-only or Bitbucket-only site always uses the 25 batch, since neither reports a reading a half-hourly tick can act on
- [ ] Setting the `gitwire_detection_batch_size` filter still overrides all of the above
- [ ] Setting `gitwire_detection_time_budget` to 1 makes a tick stop after roughly one repository and leave the rest for the next run
- [ ] Confirm a GitLab row in the batch still aborts the loop early once its connection's cached remaining drops below 50, mirroring the existing GitHub per-row guard

### Refresh Repositories vs Refresh Types

- [ ] Add Repository tab: "Refresh Repositories" relists repos and leaves stored types alone, so badges stay put with no detect-batch calls in the network tab
- [ ] With a connection holding more than 100 repositories (one API page), "Refresh Repositories" leaves all of them in the list — repos past the first page must not disappear until the next cron sweep
- [ ] With `add_filter( 'gitwire_refresh_time_budget', fn() => 1 )` in an mu-plugin, a refresh of that same connection returns early, leaves the existing rows in place, and `wp option get gitwire_refresh_state` shows a parked cursor that the next `wp cron event run gitwire_refresh_repositories` resumes from
- [ ] Make one connection fail (revoke access or point it at a bad host): its stored types survive a "Refresh Repositories & Types", while the connections that answered lose theirs
- [ ] Add a repo on the provider side, then "Refresh Repositories" again: the new repo appears with no stored type and gets a detect-batch call, while every already-typed repo is untouched
- [ ] The chevron next to it opens a menu with "Refresh Repositories & Types" and "Refresh Types Only"
- [ ] "Refresh Repositories & Types" relists repos and drops stored types, so badges clear and then repopulate from fresh detect-batch calls
- [ ] "Refresh Types Only" leaves the list untouched (no fetch_repositories calls) but drops stored types the same way, so badges clear and repopulate without the list itself changing
- [ ] All three modes keep the current list on screen when a connection errors, and surface a toast per failing connection
- [ ] With Auto-Detect off, `curl -X DELETE '.../gitwire/v1/repos/cache?mode=types'` with a valid nonce clears nothing — the server refuses to drop types nothing would rebuild, not just the hidden menu
