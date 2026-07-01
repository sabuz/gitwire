# Known Bugs

## Open

### Public connection silently hidden when a Pro private connection is added for the same account

**Status:** open
**Affects:** both
**Reported:** 2026-06-28

**Symptoms:**
A user adds a public connection (username only) for e.g. GitHub. Later they add a Pro private connection with a PAT for the same account. The public connection disappears from the connections list — it still exists in the DB but is no longer shown. When the private connection is removed or Pro is disabled, the public connection reappears unexpectedly. The user has likely forgotten it was ever there.

**Root cause:**
The UI (or the REST response) suppresses the public connection when a private connection exists for the same provider/identifier. The intent was to prevent showing duplicates, but the suppression is silent — no indication to the user that the public connection still exists and will resurface.

**Proposed fix:**
Show the public connection in the connections list with an "Inactive" badge, greyed out. It is clickable — opening it shows a stripped-down detail panel with only the essentials (provider, username) and a "Remove Connection" button. The user can disconnect from there.

No API usage data is shown for inactive connections: `rate_limit`, `rate_remaining`, `rate_reset`, `name`, `avatar_url`, `authenticated`, and `error` are all omitted from the panel. Those fields are only relevant when the connection is active.

**Implementation notes:**
- REST response returns the public connection with `"status": "inactive"` (or `"superseded"`) when a private connection exists for the same provider + identifier
- JS renders the row greyed out with the "Inactive" badge; clicking opens the detail panel
- Detail panel for inactive connections: shows provider, username, status explanation ("This connection is inactive because a private connection exists for this account"), and a Remove Connection button
- Detail panel does not render rate limit, avatar, authentication status, or error fields for inactive connections
- Remove Connection from an inactive panel deletes the row and closes the panel — no confirmation needed, no orphan warning. Public installations already store `provider`, `owner`, `full_name`, and `html_url` directly on `gitwire_installations`; the `connection_id` is a reference, not a hard dependency. The private connection (same provider + identifier) will serve those installations going forward, or the install/update logic can operate without a connection for public repos.
- Badge label: "Inactive" (Title Case)

---

### Email field inconsistency between public and Pro connections

**Status:** open
**Affects:** both
**Reported:** 2026-06-28

**Symptoms:**
Free (public) connections store `NULL` for the `email` field. Pro (private) connections store a blank string `""` instead of `NULL` when no email is provided. Inconsistent across the two plugins.

**Root cause:**
Pro migration adds the `email` column with no default or a blank default; the insert path likely passes `""` explicitly where the free path omits the field and lets DB default to `NULL`.

**Fix:**
Standardize to `NULL` for both. Pro insert path should pass `null` (not `""`) when email is not collected. Verify `column_exists` guard and `DEFAULT NULL` on the ALTER TABLE in Pro migration.

---

### "Repositories per Page" and "Max per Source" settings not respected

**Status:** open
**Affects:** both
**Reported:** 2026-06-28

**Symptoms:**
The "Repositories per Page" and "Max per Source" settings exist in plugin settings but are not applied when fetching or displaying repository lists.

**Root cause:**
Unknown — settings likely saved correctly but not read by the repository listing/pagination logic.

**Fix:**
Audit `class-repositories.php` and the repositories REST endpoint — verify settings are read via `get_option()` and applied to query limits and API request pagination. Cover with a test in the QA plan.

---

### Search in "Add Repository" panel breaks with unloaded repositories

**Status:** open
**Affects:** both
**Reported:** 2026-06-28

**Symptoms:**
Search in the "Add Repository" panel only searches the currently loaded/paginated set of repositories, not the full repository list. Repositories not yet fetched are invisible to search.

**Root cause:**
Search is likely client-side, filtering only what is in the JS state. Repositories beyond the current page are not loaded and therefore not searchable.

**Fix:**
Search needs to trigger a server-side request to the Git provider API (or the `gitwire_repositories` table if pre-cached) rather than filtering client-side state. Pagination and search must work together: searching should reset to page 1 of server-filtered results. Test cases: search for a repo on page 3 before scrolling/loading it; search across a connection with 200+ repos.

---

### Bitbucket workspace name displayed with @ prefix

**Status:** open
**Affects:** both
**Reported:** 2026-06-28

**Symptoms:**
Bitbucket workspace names are shown with a leading `@` in the UI (e.g. `@acme-org` instead of `acme-org`). The `@` was already removed from the Bitbucket email field — the same fix was not applied to the workspace name.

**Fix:**
Find where the workspace name is rendered in the JS (likely `shared.js` or the connections panel component) and strip the `@` prefix, matching the email field treatment.

---

## Tasks

### Plugin header — free vs Pro link area

**Status:** open
**Affects:** both
**Reported:** 2026-06-28

**Design:**

**Free plugin** — plain links in the top-right header area:
- Docs
- Support
- Any other relevant URLs

**Pro plugin** — a single dropdown button in the top-right (structure already exists). Clicking opens a dropdown menu with:

1. **Connect** / **Disconnect** — label toggles based on license state:
   - No license active: "Connect" → opens the license key input popup
   - License active: "Disconnect" → deactivates and removes the license
2. **Docs** — link to documentation
3. **Support** — link to Pro support page on the website
4. Additional URLs as needed (e.g. changelog, account portal)

**Implementation notes:**
- The Connect/Disconnect item is the only one that changes label; Docs and Support are always present
- "Connect" and "Disconnect" are Title Case; they are actions, not labels
- The license popup triggered by Connect should be self-contained — enter key, validate against the license server, show success/error inline
- Disconnecting shows a confirmation dialog before proceeding — something like: "Are you sure you want to disconnect your license? This will deactivate GitWire Pro on this site." with "Disconnect" and "Cancel" buttons. This matters because disconnecting may consume a license activation slot depending on the licensing model.
- On confirmation, clear the stored license key and update the dropdown label back to "Connect" without a page reload
- Free plugin header links are plain `<a>` tags, no dropdown needed

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
