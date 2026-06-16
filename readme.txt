=== Gitwire - Deploy WordPress Plugins & Themes from Git ===
Contributors: nazsabuz
Tags: github, gitlab, bitbucket, git, plugins, themes, deploy
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Install and update WordPress plugins and themes from Git repositories. Switch branches when needed, and recover safely if an update causes a fatal error.

== Description ==

Gitwire lets you install and manage plugins and themes directly from your GitHub, GitLab, or Bitbucket repositories — no manual ZIP uploads, no FTP.

**Browse and install**

Connect your GitHub, GitLab, or Bitbucket account, browse every repository you own or have access to, and install any of them as a plugin or theme with one click. Gitwire auto-detects the repository type (plugin, theme, or block theme) and places it in the right directory.

**Keep up to date**

Already installed something? Pull the latest commit from any branch without leaving the WordPress admin. Switch branches at any time — useful for testing a feature branch on staging before merging to main.

**Activate, deactivate, and delete**

Manage installed items directly from the Installed panel. Activate or deactivate plugins, switch the active theme, and remove repositories you no longer need.

**Fatal-error protection**

Before overwriting an existing installation Gitwire backs it up. A PHP shutdown handler watches for fatal errors introduced by new code: if a fatal fires during the install request the backup is automatically restored, the plugin is deactivated, and an admin notice explains what happened — all without touching the site's public-facing pages.

For plugin activation Gitwire delegates to WordPress core's `activate_plugin()`, which already sandboxes the plugin file for parse and compile errors before marking it active.

**Conflict resolution**

If a slug is already taken by a different installation, the incoming repository is given a unique slug automatically so nothing is overwritten without permission.

**Performance**

Repository lists are cached for 30 minutes and type-detection results for 24 hours, so browsing stays fast even with large accounts.

== Installation ==

1. Upload the `gitwire` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Gitwire** in the WordPress admin sidebar.
4. Open the **Settings** tab and connect your GitHub, GitLab, and/or Bitbucket account.
5. Switch to the **Browse** tab to start installing repositories.

== Frequently Asked Questions ==

= Do I need a personal access token? =

Yes. GitHub requires a classic or fine-grained personal access token with at least `repo` (read) scope. GitLab requires a personal access token with `read_api` scope. Bitbucket requires an App Password (or Atlassian API token) with `Repositories: Read` permission. Tokens are stored in the WordPress database and are only used server-side.

= Can I install private repositories? =

Yes, as long as your token has access to the repository.

= Can I install a theme? =

Yes. Gitwire detects whether a repository is a classic theme, a block theme, or a plugin by inspecting the repository contents. You can also override the detection manually during install.

= Can I deactivate a theme? =

No — WordPress does not support deactivating a theme the way it does plugins. To stop using a theme, activate a different one.

= What happens if the new code causes a fatal error? =

If a fatal error fires during the install or update request, Gitwire automatically restores the previous version from its backup, deactivates the plugin (if it was a plugin), and shows an admin notice with the error details.

= What WordPress version is required? =

WordPress 6.9 or later. PHP 8.1 or later is also required.

= Is GitLab self-hosted supported? =

Yes. Enter your self-hosted GitLab instance URL in the Settings tab alongside your token.

== Screenshots ==

1. Browse panel — repositories listed from your connected accounts.
2. Installed panel — manage installed plugins and themes.
3. Settings panel — connect GitHub, GitLab, and Bitbucket accounts.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
