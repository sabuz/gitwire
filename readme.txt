=== Gitwire ===
Contributors: nazsabuz
Tags: github, gitlab, bitbucket, git, deploy
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Install and update WordPress plugins and themes from GitHub, GitLab, or Bitbucket. Switch branches and recover safely from failed updates.

== Description ==

Gitwire installs and manages WordPress plugins and themes from connected GitHub, GitLab, or Bitbucket repositories. No manual ZIP uploads, FTP, SSH, or Git binary on the server. Everything runs from wp-admin.

For plugin and theme developers, or teams maintaining client sites with code in Git, Gitwire replaces the download, upload, and update cycle with a single click.

**Browse and install**

Connect a GitHub, GitLab, or Bitbucket account, browse accessible repositories, and install any of them as a plugin or theme. Gitwire inspects repository contents to identify plugins, classic themes, and block themes, then places each installation in the correct directory. Repositories can also be imported directly by URL.

**Smart Install**

Detection is more than a convenience. With Smart Install enabled by default, a repository that cannot be confidently identified as a WordPress plugin or theme cannot be installed. A documentation repository or mistyped URL therefore cannot be unpacked into wp-content.

**Pull updates and switch branches**

Already installed a repository? Pull the latest commit on the tracked branch with one click. Gitwire checks installed repositories on a schedule and flags those that have fallen behind. Switch an installation to another branch at any time, which is useful for testing a feature branch on staging or rolling a site back to a known-good release branch.

**Automatic updates**

Enable auto-update per repository and Gitwire applies new commits on a chosen schedule, from every five minutes to once a day, or never. Auto-update is off by default, so nothing deploys automatically until it is enabled.

**Fatal Guard: automatic fatal-error recovery**

Every install, activation, and update is guarded. Before overwriting an existing installation, Gitwire takes a backup and watches the request for a fatal PHP error:

* A failed install removes the broken files it downloaded.
* A failed activation deactivates the plugin or restores the previous theme.
* A failed update restores the previous working version from its backup.

An admin notice shows the real PHP error, and the site stays up. Once a commit is known to cause a fatal error, Gitwire stops retrying it and marks the repository "Update Blocked" until a fix is pushed. A scheduled job cannot redeploy the same broken commit in a loop.

For plugin activation, Gitwire delegates to WordPress core's `activate_plugin()`, which already sandboxes the plugin file for parse and compile errors. Updates to an active plugin or the active theme receive an additional live check because broken code is live as soon as it lands.

Backups are written to `wp-content/upgrade-temp-backup/`, the same location WordPress uses for rollback copies during updates. The directory is blocked from direct web access, and backups are deleted after an update is confirmed successful.

**Remove safely**

Removing a repository offers two distinct outcomes: unlink it from Gitwire and leave the files running as an ordinary plugin or theme, or delete the files from the server. Deleting a Gitwire-installed plugin from the normal Plugins screen also removes Gitwire's record, so no record points to files that no longer exist.

**Conflict resolution**

If a directory name is already taken, Gitwire refuses to overwrite it silently. Choose another name or explicitly confirm a replacement. A full backup is created before replacement.

**Activity log**

Turn on logging to record installs, updates, branch switches, activations, rollbacks, and connection changes, with a timestamp and the responsible user or process. Filter by user, level, and date range. Choose "Errors Only" to record failures only. Logging is especially useful with automatic updates enabled because scheduled jobs run without an open admin screen.

**Performance**

Repository lists and type-detection results are cached and refreshed in the background on a configurable interval. Browsing stays fast even for accounts with hundreds of repositories.

**Private repositories**

Gitwire works with public repositories without a token, including public repositories on a self-hosted GitLab instance. Private repositories and higher API rate limits require [Gitwire Pro](https://gitwire.app/pricing/), which stores an encrypted access token and authenticates with the provider API. An authenticated GitHub connection raises the limit from 60 requests per hour to a typical 5,000, which matters when browsing an account with many repositories or checking for updates often. Tokens are encrypted before storage and are sent only to the Git host they belong to.

Full documentation: [gitwire.app/docs](https://gitwire.app/docs/)

Source code, including the unminified JavaScript and SCSS used to compile the `build/` assets, is available at [github.com/sabuz/gitwire](https://github.com/sabuz/gitwire). Run `npm install && npm run build` to reproduce the bundle.

== External Services ==

Gitwire talks to the configured Git hosting service. Nothing is sent until a connection is added or a repository is installed, and no data is sent to Gitwire or an analytics service.

**GitHub**

Used to list accessible repositories, read repository contents for type detection, list branches and commits, and download branch archives. Requests go to `api.github.com`, and to `codeload.github.com` when downloading an archive. Sent: the repository owner, repository name, selected branch, and personal access token when configured through Gitwire Pro. Public repositories are read without a token.
Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

**GitLab**

Used for the same operations against `gitlab.com` or the configured self-hosted instance URL. Sent: the namespace, project path, selected branch, and personal access token when configured through Gitwire Pro.
Terms: https://about.gitlab.com/terms/
Privacy: https://about.gitlab.com/privacy/

**Bitbucket**

Used for the same operations against `api.bitbucket.org` and `bitbucket.org`. Sent: the workspace, repository slug, selected branch, Atlassian account email, and API token when configured through Gitwire Pro.
Terms: https://www.atlassian.com/legal/cloud-terms-of-service
Privacy: https://www.atlassian.com/legal/privacy-policy

**GitHub avatars**

When a GitHub account is connected, its profile picture is displayed in the Gitwire admin screens by loading an image from `avatars.githubusercontent.com`. Only the GitHub username is part of that URL. This happens in wp-admin only, never on the front end, and is covered by the GitHub policies linked above.

**Gravatar**

Bitbucket has no public avatar API, and GitLab's avatar is used when reachable, so both fall back to a generated identicon for the connection picture. An MD5 hash of the account username or workspace slug is sent to `www.gravatar.com`; no other data leaves the site for this request. This happens in wp-admin only, never on the front end.
Terms: https://wordpress.com/tos/
Privacy: https://automattic.com/privacy/

== Privacy ==

Gitwire stores connection details, installation records, and a cached repository list in the site's database. Access tokens added through Gitwire Pro are encrypted before storage and are sent only to the Git host they belong to.

When the activity log is enabled, Gitwire records the WordPress username responsible for each install, update, branch switch, activation, or removal, along with the repository name and a timestamp. The log is written to a file in the site's uploads directory with a name derived from the site's secret keys, is not linked from anywhere, and is blocked from direct web access. Disable logging in **Gitwire → Settings** to stop recording, and use **Clear log** to delete existing entries.

== Third-Party Libraries ==

Gitwire is licensed GPL-2.0-or-later, and all of its own PHP, JavaScript, and SCSS is original work. The full license text ships with the plugin in `license.txt`, in the same format WordPress core uses for its own.

Most WordPress packages used by the admin screens (`@wordpress/components`, `@wordpress/element`, `@wordpress/api-fetch`, and others) are loaded from WordPress core at runtime and are not bundled. The remainder, including `@wordpress/dataviews` and its dependency tree, is compiled into `build/index.js`. That includes 43 third-party packages licensed under MIT or 0BSD, all compatible with the GPL.

Every one of those licenses is reproduced in full at the end of `license.txt`, under the same "This program incorporates work covered by the following copyright and permission notices" heading used by WordPress core. That section is generated from the actual webpack module list rather than maintained by hand, so it cannot drift from the bundle. Notable entries include Sonner (MIT, Copyright (c) 2023 Emil Kowalski) for admin toasts, and the Ariakit, Floating UI, and date-fns families pulled in by DataViews.

Any license banner carried in the source of a bundled library is also preserved at `build/index.js.LICENSE.txt`.

== Installation ==

1. Upload the `gitwire` folder to `/wp-content/plugins/`, or install it from **Plugins → Add New Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Open **Gitwire** in the WordPress admin sidebar.
4. Open **Settings** and add a connection for each GitHub, GitLab, or Bitbucket account.
5. Open **Add Repository** to browse and install the first repository.
6. Manage installed repositories from the **Repositories** screen.

== Frequently Asked Questions ==

= Is Git required on the server? =

No. Gitwire uses the Git host's REST API and WordPress's own filesystem layer. There is no Git binary, SSH key, or deploy script involved.

= Is a personal access token required? =

No, not for public repositories. Add a username in Settings to browse and install public repositories.

Private repositories require Gitwire Pro and an access token with read access to the repository.

= Can private repositories be installed? =

Yes, with Gitwire Pro. The token is encrypted with AES-256-GCM before storage, using a key derived from the site's WordPress salts.

= Can a theme be installed? =

Yes. Gitwire detects whether a repository is a classic theme, a block theme, or a plugin by inspecting repository contents. A `theme.json` file alone does not make a repository a block theme because plugins can ship one for block styling. Theme identity must first be confirmed by `style.css` and a `Theme Name:` header.

= Can a theme be deactivated? =

No. WordPress does not support deactivating a theme the way it supports deactivating plugins. To stop using a theme, activate another one.

= What happens if new code causes a fatal error? =

Gitwire restores the previous version from its backup, deactivates the plugin when needed, and shows an admin notice with the error details. The site stays up. The repository is then marked "Update Blocked" so the same commit is not tried again until a fix is pushed.

= Does Gitwire deploy on every Git push? =

Not on push. Gitwire polls on a configured schedule. Updates can be pulled manually at any time or applied automatically when auto-update is enabled for a repository.

= Are automatic updates enabled by default? =

No. Auto-update is off for every repository until enabled individually.

= Can WordPress update a repository managed by Gitwire? =

No. Gitwire blocks native WordPress plugin and theme upgrades for tracked repositories. Update those repositories from the Gitwire **Repositories** screen instead. The native update offer may remain visible, but WordPress will not replace the tracked files.

= Can an update overwrite changes made on the server? =

Yes. An update replaces the installed directory with the branch archive. Changes made on the server should be committed and pushed instead.

= Does Gitwire run Composer or a build step? =

No. Gitwire installs what is committed to the branch. Projects that need a build should commit the built output to the deployment branch or use a dedicated release branch.

= Is self-hosted GitLab supported? =

Yes. Enter the instance URL when creating the connection. The URL must use HTTPS, and private, loopback, and cloud-metadata addresses are rejected. Public repositories on the instance work with the free plugin; private repositories require a token through Gitwire Pro.

= What WordPress and PHP versions are required? =

WordPress 7.0 or later, and PHP 8.0 or later.

= Does Gitwire work on multisite? =

Yes. Plugins and themes are shared across the network, so Gitwire runs in Network Admin and is limited to Super Admins. It does not appear in an individual site's dashboard, and a site Administrator cannot reach it.

= Where is installed code stored? =

In the normal `wp-content/plugins` and `wp-content/themes` directories, not a sandbox. To WordPress and every other plugin, a Gitwire-installed plugin is an ordinary plugin.

= What happens to stored data if Gitwire is deleted? =

By default, connections and installation records are kept, so reinstalling resumes the previous state, and installed plugins and themes keep running. To wipe everything, enable **Remove all data on uninstall** in **Gitwire → Tools** before deleting the plugin.

= Where is the activity log stored, and who can read it? =

It is a file in the uploads directory whose name is derived from the site's secret keys, so it cannot be guessed. Gitwire also writes deny rules for Apache and IIS alongside it. No extra server configuration is needed.

== Screenshots ==

1. Repositories: every plugin and theme Gitwire manages, with its branch, current commit, and status.
2. Add Repository: browse repositories from connected accounts, or import from a URL.
3. Install form: automatic type detection, branch selection, and directory name.
4. Settings: connect GitHub, GitLab, and Bitbucket accounts and tune detection and updates.
5. Logs: a timestamped record of installs, updates, and rollbacks.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
