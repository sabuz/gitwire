# WordPress.org Compliance

Reference for submitting and maintaining GitWire Free on the WordPress.org plugin directory.

---

## Plugin Submission Requirements

### Naming and Identity

- Plugin slug: `gitwire` (must match directory name)
- No trademark terms in the plugin name or slug
- Plugin name must not imply WordPress endorsement
- Author name must match WordPress.org account

### Code Requirements

- No obfuscated or encrypted PHP
- No minified code without unminified source included (or source linked)
- No calls to external services without user disclosure and opt-in
- No tracking, analytics, or telemetry without explicit disclosure
- No data sent to remote servers during normal plugin operation without disclosure

### External Services

Document every remote request the plugin makes:

| Service | Endpoint | Purpose | Data sent | Disclosure location |
|---|---|---|---|---|
| GitHub API | `api.github.com` | Repo listing, install | Connection token (Pro), repo slug | Plugin settings page |
| GitLab API | `gitlab.com/api/v4` or self-hosted | Repo listing, install | Connection token (Pro), repo slug | Plugin settings page |
| Bitbucket API | `api.bitbucket.org` | Repo listing, install | Connection token (Pro), repo slug | Plugin settings page |

### Freemium / Upsell Rules

- No persistent admin notices promoting Pro
- No checkout links inside plugin settings
- Upsell links are allowed on a dedicated upgrade page but must not nag users
- Feature restrictions are allowed; access restrictions to already-working features are not

### readme.txt Structure

Required sections:
- `=== Plugin Name ===`
- `Contributors:`, `Tags:`, `Requires at least:`, `Tested up to:`, `Stable tag:`, `License:`
- `== Description ==`
- `== Installation ==`
- `== Frequently Asked Questions ==`
- `== Screenshots ==` (optional but recommended)
- `== Changelog ==`
- `== Upgrade Notice ==`

### Assets

These live in the `/assets/` directory at the **root of the SVN repository**, a sibling of `/trunk/` and `/tags/`. They are not part of the plugin and never ship in the zip, so nothing here belongs in this git repo's own `assets/` directory.

```
gitwire/            <- SVN root
├── assets/         <- these files
├── trunk/          <- the plugin itself
└── tags/
```

| Asset | Size | Location |
|---|---|---|
| Plugin icon (1x) | 128×128 px | `/assets/icon-128x128.png` |
| Plugin icon (2x) | 256×256 px | `/assets/icon-256x256.png` |
| Banner (1x) | 772×250 px | `/assets/banner-772x250.png` |
| Banner (2x) | 1544×500 px | `/assets/banner-1544x500.png` |
| Screenshots | any | `/assets/screenshot-1.png`, etc. |

None of these exist yet. Without them the directory listing falls back to a generic placeholder icon and no banner.

---

## Known Plugin Check failure: expect a question about it

Plugin Check raises one **error** against the submitted zip, and it is deliberate:

```
Plugin Updater detected. These are not permitted in WordPress.org hosted plugins.
Detected: site_transient_update_plugins
```

It comes from `Plugin_Updater_Check::look_for_plugin_updaters()`, which greps PHP for
`#site_transient_update_plugins#`. It is a string match, so it cannot see what the code does
with the transient.

Our CI ignores this one code so a permanently red pipeline does not mask real regressions
(`.github/workflows/code-check.yml`). That changes nothing about the submission: Plugin Check run
by the reviewer will still flag it. Do not try to hide it, and do not build the filter name at
runtime to dodge the grep. That is evasion and it is worse than the finding.

### The explanation to give

> Gitwire installs plugins and themes from a user's own Git repository, at their explicit request.
> It does not update itself from anywhere except WordPress.org, and it bundles no updater library.
>
> The flagged code does the opposite of what the check is looking for. It **removes** entries from
> `site_transient_update_plugins`; it never adds one. The reason is data loss: WordPress asks
> api.wordpress.org about every installed directory name, and if a user's repository happens to
> live at a directory name a directory-hosted plugin also uses, WordPress offers that unrelated
> project's release and, with auto-updates on, installs it over the user's own code without asking.
> The filter removes only the rows Gitwire itself installed and tracks, which are never
> WordPress.org plugins.
>
> The relevant code is `Installer::suppress_plugin_updates()` and `suppress_theme_updates()` in
> `includes/class-installer.php`. Both take the transient, drop the entries matching directories in
> Gitwire's own installation table, and return it. There is no remote call, no alternative update
> source, and no code path that puts an update into the transient.
>
> We also ship the non-invasive half of the fix: the Repositories screen warns when a managed
> install has no `Update URI` header, since that header is the mechanism WordPress added in 5.8 for
> a plugin to claim its slug. We cannot add that header ourselves, because it belongs in the user's
> repository and the next pull would overwrite it, which is why the filter exists as well.
>
> If suppression is not acceptable, we will remove the filter. Please confirm which you prefer.

### If the reviewer says no

Remove the four `add_filter` calls and the six methods in `includes/class-installer.php`, delete
`tests/InstallerUpdateSuppressionTest.php`, and drop `ignore-codes` from the workflow. Commit
`546407b` did exactly that and can be reapplied. The `Update URI` warning stays either way, and the
residual risk goes back to being the user's to manage. Tracked in issue #84.

## Review Red Flags to Avoid

Based on common WordPress.org rejection reasons:

- Direct DB queries without `$wpdb->prepare()`
- Missing nonces on form submissions
- Missing capability checks (`current_user_can()`) on admin actions
- Output not escaped before echoing to HTML
- Using `$_GET`/`$_POST` without sanitization
- Functions or classes without the `gitwire_` prefix (namespace collision risk)
- Enqueueing scripts/styles without unique handles
- Using jQuery outside of the WP dependency system
- Hardcoding URLs or paths that should be dynamic
- `eval()` or `base64_decode()` of executable code

---

## Submission Process

1. Create a WordPress.org account (if not already done)
2. Submit via https://wordpress.org/plugins/developers/add/
3. Allow 1-2 weeks for initial review
4. Respond to reviewer feedback promptly (reviewers give 2 weeks to respond before closing)
5. Once approved, SVN commit is the publication method

### SVN Workflow (post-approval)

```bash
# Check out the SVN repo (one time)
svn co https://plugins.svn.wordpress.org/gitwire/ /path/to/svn/gitwire

# Copy files to trunk/
rsync -av --exclude='.git' /path/to/plugin/ /path/to/svn/gitwire/trunk/

# Tag a release
svn cp trunk/ tags/1.0.0/
svn ci -m "Tagging 1.0.0"
```
