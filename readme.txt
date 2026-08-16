=== Gitwire - Deploy WordPress Plugins & Themes from Git ===
Contributors: nazsabuz
Tags: github, gitlab, bitbucket, git, deploy
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Install and update WordPress plugins and themes from Git repositories, switch branches, and recover safely from a fatal error.

== Description ==

Gitwire installs and manages WordPress plugins and themes directly from your GitHub, GitLab, or Bitbucket repositories. No manual ZIP uploads, no FTP, no SSH, and no Git binary on the server. Everything runs from inside wp-admin.

If you build plugins or themes, or maintain client sites that run code you keep in Git, Gitwire replaces the download-a-ZIP-and-upload-it loop with a single click.

**Browse and install**

Connect a GitHub, GitLab, or Bitbucket account, browse every repository it can see, and install any of them as a plugin or theme. Gitwire inspects the repository contents to work out whether it is a plugin, a classic theme, or a block theme, and places it in the right directory. You can also paste a repository URL directly instead of browsing.

**Smart Install**

Detection is not just a convenience. With Smart Install enabled (the default), a repository that cannot be confidently identified as a WordPress plugin or theme cannot be installed at all, so a docs repository or a mistyped URL never gets unpacked into wp-content.

**Pull updates and switch branches**

Already installed something? Pull the latest commit on the tracked branch with one click. Gitwire checks your installed repositories on a schedule and flags any that have fallen behind. Switch an installation to another branch at any time, which is how you test a feature branch on staging before merging, or roll a site back to a known-good release branch.

**Automatic updates**

Enable auto-update per repository and Gitwire applies new commits on its own, on a schedule you choose (from every five minutes to once a day). It is off by default for every repository, so nothing deploys automatically until you say so.

**Fatal Guard: automatic fatal-error recovery**

Every install, activation, and update is guarded. Before overwriting an existing installation Gitwire takes a backup, then watches the request for a fatal PHP error:

* A failed install removes the broken files it just downloaded.
* A failed activation deactivates the plugin, or restores your previous theme.
* A failed update restores the previous working version from its backup.

You get an admin notice with the real PHP error, and the site stays up. Once a commit is known to fatal, Gitwire stops retrying it and marks the repository "Update Blocked" until you push a fix, so a scheduled job cannot redeploy the same broken commit on a loop.

For plugin activation Gitwire delegates to WordPress core's `activate_plugin()`, which already sandboxes the plugin file for parse and compile errors. Updates to an already-active plugin or the active theme get an additional live check, because that is the case where broken code is live the moment it lands.

Backups are written outside your webroot, so they are never reachable by a direct HTTP request, and are deleted as soon as an update is confirmed successful.

**Remove safely**

Removing a repository offers two distinct outcomes, spelled out before you choose: unlink it from Gitwire and leave the files running as an ordinary plugin or theme, or delete the files from the server. Deleting a Gitwire-installed plugin from the normal Plugins screen also cleans up Gitwire's own record, so you are never left with a row pointing at files that no longer exist.

**Conflict resolution**

If a directory name is already taken, Gitwire refuses to overwrite it silently. You either pick a different name or explicitly confirm a replacement, which takes a full backup first.

**Activity log**

Turn on logging to record installs, updates, branch switches, activations, rollbacks, and connection changes, with a timestamp and the user or process responsible. Filter by user, level, and date range. Choose "Errors Only" to record just the failures. This matters most with automatic updates enabled, since nobody is watching the screen when the scheduled job fires.

**Performance**

Repository lists and type-detection results are cached and refreshed in the background on an interval you control, so browsing stays fast even on accounts with hundreds of repositories.

**Private repositories**

Gitwire works with public repositories out of the box, with no token at all. Private repositories, higher API rate limits, and self-hosted GitLab require [Gitwire Pro](https://gitwire.app/pro), which stores an encrypted access token and authenticates with the provider API. Tokens are encrypted before being written to the database and are never sent anywhere except to the Git host they belong to.

Full documentation: [gitwire.app/docs](https://gitwire.app/docs/)

== Installation ==

1. Upload the `gitwire` folder to `/wp-content/plugins/`, or install it from **Plugins → Add New Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Gitwire** in the WordPress admin sidebar.
4. Open **Settings** and add a connection for your GitHub, GitLab, and/or Bitbucket account.
5. Go to **Add Repository** to browse and install your first repository.
6. Manage everything you have installed from the **Repositories** screen.

== Frequently Asked Questions ==

= Do I need Git installed on my server? =

No. Gitwire uses the Git host's REST API and WordPress's own filesystem layer. There is no Git binary, no SSH key, and no deploy script involved.

= Do I need a personal access token? =

No, not for public repositories. Add your username in Settings to browse and install anything public.

Private repositories require Gitwire Pro and an access token with read access to the repository.

= Can I install private repositories? =

Yes, with Gitwire Pro. The token is encrypted with AES-256-GCM before it is stored, using a key derived from your site's own WordPress salts.

= Can I install a theme? =

Yes. Gitwire detects whether a repository is a classic theme, a block theme, or a plugin by inspecting the repository contents. Note that a `theme.json` file alone does not make something a block theme, since plugins ship one for block styling too; theme identity has to be confirmed by `style.css` and a `Theme Name:` header first.

= Can I deactivate a theme? =

No. WordPress does not support deactivating a theme the way it does plugins. To stop using a theme, activate a different one.

= What happens if the new code causes a fatal error? =

Gitwire restores the previous version from its backup, deactivates the plugin if it needs to, and shows an admin notice with the error details. The site stays up. The repository is then marked "Update Blocked" so the same commit is not tried again until you push a fix.

= Does Gitwire deploy on every Git push? =

Not on push. Gitwire polls on a schedule you set. Updates can be pulled manually at any time, or applied automatically when auto-update is enabled for that repository.

= Are automatic updates on by default? =

No. Auto-update is off for every repository until you turn it on individually.

= Will an update overwrite changes I made on the server? =

Yes. An update replaces the installed directory with the branch archive. Do not edit Gitwire-installed files on the server; commit and push instead.

= Does Gitwire run composer install or a build step? =

No. It installs what is committed to the branch. If your project needs a build, commit the built output to the branch you deploy from, or deploy from a dedicated release branch.

= Is self-hosted GitLab supported? =

Yes, with Gitwire Pro. Enter your instance URL alongside the token when creating the connection. The URL must use HTTPS, and private, loopback, and cloud-metadata addresses are rejected.

= What WordPress and PHP versions are required? =

WordPress 6.9 or later, and PHP 8.1 or later.

= Where is my code stored? =

In the normal `wp-content/plugins` and `wp-content/themes` directories, not a sandbox. To WordPress, and to every other plugin, a Gitwire-installed plugin is an ordinary plugin.

= What happens to my data if I delete Gitwire? =

By default, your connections and installation records are kept, so reinstalling picks up where you left off, and the plugins and themes it installed keep running. To wipe everything, turn on **Remove all data on uninstall** in **Gitwire → Tools** before deleting the plugin.

= Does the activity log need extra server configuration? =

No. The log file is stored outside your site's webroot, the same way Gitwire stores installer backups, so it is never reachable by a direct request regardless of server software.

== Screenshots ==

1. Repositories — every plugin and theme Gitwire manages, with its branch, current commit, and status.
2. Add Repository — browse repositories from your connected accounts, or import from a URL.
3. Install form — automatic type detection, branch selection, and directory name.
4. Settings — connect GitHub, GitLab, and Bitbucket accounts and tune detection and updates.
5. Logs — a timestamped record of installs, updates, and rollbacks.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
