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

## Update behavior

Gitwire Free is updated through WordPress.org. Gitwire's separate `gitwire_update_check` cron
checks repositories that were explicitly installed through Gitwire and applies updates through the
configured GitHub, GitLab, or Bitbucket provider when repository auto-update is enabled.

Gitwire does not alter WordPress core plugin or theme update results or provide a replacement
updater for WordPress.org-hosted plugins. It protects installations explicitly tracked by Gitwire at
WordPress's native upgrader boundary: `upgrader_pre_download` rejects the package before it is
downloaded, and `upgrader_pre_install` rejects it again before files can replace the installation.
Untracked plugins and themes remain under core's normal update control.

`Update URI` is not a redirect from WordPress.org to GitHub. It is metadata that a plugin or theme
author places in the repository's own source to identify an externally hosted project. Gitwire does
not add or inject that header into repositories it installs; updates would overwrite such a local
modification anyway. Authors of externally hosted repositories should add the header to their own
plugin main file or theme `style.css` when appropriate.

For repositories without that header, WordPress core may still identify a matching directory-hosted
project by slug. Gitwire leaves that update offer visible, but blocks the native upgrade when the
plugin or theme is present in Gitwire's installation table. Repository owners should still choose a
non-colliding directory name or declare the repository's `Update URI` in its own source.

Issue #84 has the history, including the two approaches tried and abandoned before this one.

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
