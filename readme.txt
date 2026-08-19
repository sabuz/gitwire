=== Gitwire ===
Contributors: nazsabuz
Tags: github, gitlab, bitbucket, git, deploy
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Install and update plugins and themes from GitHub, GitLab, or Bitbucket. Switch branches, and roll back automatically if an update breaks the site.

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

Enable auto-update per repository and Gitwire applies new commits on its own, on a schedule you choose (from every five minutes to once a day, or never). It is off by default for every repository, so nothing deploys automatically until you say so.

**Fatal Guard: automatic fatal-error recovery**

Every install, activation, and update is guarded. Before overwriting an existing installation Gitwire takes a backup, then watches the request for a fatal PHP error:

* A failed install removes the broken files it just downloaded.
* A failed activation deactivates the plugin, or restores your previous theme.
* A failed update restores the previous working version from its backup.

You get an admin notice with the real PHP error, and the site stays up. Once a commit is known to fatal, Gitwire stops retrying it and marks the repository "Update Blocked" until you push a fix, so a scheduled job cannot redeploy the same broken commit on a loop.

For plugin activation Gitwire delegates to WordPress core's `activate_plugin()`, which already sandboxes the plugin file for parse and compile errors. Updates to an already-active plugin or the active theme get an additional live check, because that is the case where broken code is live the moment it lands.

Backups are written to `wp-content/upgrade-temp-backup/`, the same place WordPress itself keeps rollback copies during an update, blocked from direct web access, and deleted as soon as an update is confirmed successful.

**Remove safely**

Removing a repository offers two distinct outcomes, spelled out before you choose: unlink it from Gitwire and leave the files running as an ordinary plugin or theme, or delete the files from the server. Deleting a Gitwire-installed plugin from the normal Plugins screen also cleans up Gitwire's own record, so you are never left with a row pointing at files that no longer exist.

**Conflict resolution**

If a directory name is already taken, Gitwire refuses to overwrite it silently. You either pick a different name or explicitly confirm a replacement, which takes a full backup first.

**Activity log**

Turn on logging to record installs, updates, branch switches, activations, rollbacks, and connection changes, with a timestamp and the user or process responsible. Filter by user, level, and date range. Choose "Errors Only" to record just the failures. This matters most with automatic updates enabled, since nobody is watching the screen when the scheduled job fires.

**Performance**

Repository lists and type-detection results are cached and refreshed in the background on an interval you control, so browsing stays fast even on accounts with hundreds of repositories.

**Private repositories**

Gitwire works with public repositories out of the box, with no token at all, including public repositories on a self-hosted GitLab instance. Private repositories and higher API rate limits require [Gitwire Pro](https://gitwire.app/pricing/), which stores an encrypted access token and authenticates with the provider API. An authenticated GitHub connection raises the ceiling from 60 requests per hour to a typical 5,000, which matters as soon as you browse an account with many repositories or check for updates often. Tokens are encrypted before being written to the database and are never sent anywhere except to the Git host they belong to.

Full documentation: [gitwire.app/docs](https://gitwire.app/docs/)

Source code, including the unminified JavaScript and SCSS this plugin's `build/` assets are compiled from, is at [github.com/sabuz/gitwire](https://github.com/sabuz/gitwire). Run `npm install && npm run build` to reproduce the bundle.

== External Services ==

Gitwire talks to the Git hosting service you connect it to. Nothing is sent anywhere until you add a connection or install a repository, and no data is sent to Gitwire or to any analytics service.

**GitHub**

Used to list your repositories, read repository contents for type detection, list branches and commits, and download branch archives. Requests go to `api.github.com`, and to `codeload.github.com` when downloading an archive. Sent: the repository owner, repository name, and branch you are working with, plus your personal access token when you have added one in Gitwire Pro. Public repositories are read without a token.
Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

**GitLab**

Used for the same operations against `gitlab.com`, or against the self-hosted instance URL you enter in Gitwire Pro. Sent: the namespace, project path, and branch you are working with, plus your personal access token when you have added one.
Terms: https://about.gitlab.com/terms/
Privacy: https://about.gitlab.com/privacy/

**Bitbucket**

Used for the same operations against `api.bitbucket.org` and `bitbucket.org`. Sent: the workspace, repository slug, and branch you are working with, plus your Atlassian account email and API token when you have added them in Gitwire Pro.
Terms: https://www.atlassian.com/legal/cloud-terms-of-service
Privacy: https://www.atlassian.com/legal/privacy-policy

**GitHub avatars**

When you connect a GitHub account, its profile picture is displayed in the Gitwire admin screens by loading an image from `avatars.githubusercontent.com`. Only the GitHub username is part of that URL. This happens in wp-admin only, never on the front end. It is covered by the GitHub policies linked above.

**Gravatar**

Bitbucket has no public avatar API, and GitLab's is used when reachable, so both fall back to a generated identicon for the connection's picture. An MD5 hash of the account's username or workspace slug is sent to `www.gravatar.com`; no other data leaves your site for this. This happens in wp-admin only, never on the front end.
Terms: https://wordpress.com/tos/
Privacy: https://automattic.com/privacy/

== Privacy ==

Gitwire stores connection details, installation records, and a cached list of your repositories in your own database. Access tokens added through Gitwire Pro are encrypted before being written and are only ever sent to the Git host they belong to.

When the activity log is enabled, Gitwire records the WordPress username of whoever performed each install, update, branch switch, activation, or removal, along with the repository name and a timestamp. The log is written to a file in your uploads directory with a name derived from your site's secret keys, is not linked from anywhere, and is blocked from direct web access. Turn logging off in **Gitwire → Settings** to stop recording, and use **Clear log** to delete what has already been recorded.

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

Yes. Enter your instance URL when creating the connection. The URL must use HTTPS, and private, loopback, and cloud-metadata addresses are rejected. Public repositories on your instance work with the free plugin; private ones need a token, which requires Gitwire Pro.

= What WordPress and PHP versions are required? =

WordPress 6.9 or later, and PHP 8.1 or later.

= Does Gitwire work on multisite? =

Yes. Plugins and themes are shared across a whole network, so Gitwire runs in the Network Admin only and is limited to Super Admins. It does not appear in an individual site's own dashboard, and a site Administrator cannot reach it.

= Where is my code stored? =

In the normal `wp-content/plugins` and `wp-content/themes` directories, not a sandbox. To WordPress, and to every other plugin, a Gitwire-installed plugin is an ordinary plugin.

= What happens to my data if I delete Gitwire? =

By default, your connections and installation records are kept, so reinstalling picks up where you left off, and the plugins and themes it installed keep running. To wipe everything, turn on **Remove all data on uninstall** in **Gitwire → Tools** before deleting the plugin.

= Where is the activity log stored, and can anyone read it? =

It is a file in your uploads directory whose name is derived from your site's own secret keys, so it cannot be guessed. Gitwire also writes deny rules for Apache and IIS alongside it. No extra server configuration is needed.

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
