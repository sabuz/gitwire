# Gitwire — Homepage Copy

> Deploy WordPress plugins and themes from Git, with automatic recovery when code breaks your site.
> Positioning: admin-side **pull** + **Fatal Guard** safety — not push-to-deploy/webhooks (that's WP Pusher / Deployer territory).

**Page order:** Hero + video → 9-feature grid → Collapsible deep-dive (4 cards) → *(Blog — later)* → FAQ → CTA → Footer

---

## Navigation

**Primary (left)**

| Item | Behavior |
|------|----------|
| Features | Anchor to `#features` (or scroll to collapsible deep-dive) |
| Pricing | Anchor to `#pricing` when ready |
| FAQ | Anchor to `#faq` |
| Docs | External: `docs.gitwire.app` or `/docs` |

**Right side**

| Item | Behavior |
|------|----------|
| Sign in | Ghost/text button → app/dashboard |
| Get Gitwire | Primary CTA → WordPress.org or install flow |

*Your list (Features, Pricing, FAQ, Docs, Sign in) is solid — just add the one primary CTA beside Sign in so logged-out visitors still have a clear action.*

Optional later (don't overcrowd v1): **Blog**, **Changelog**, **Compare** (vs WP Pusher — only if you want competitive SEO).

---

## 1. Hero

**H1**
Deploy WordPress plugins and themes from Git

**Subhead**
No FTP, no ZIP uploads, no SSH. Plus automatic recovery when a commit breaks your site.

**Description** *(below subhead, above video)*
Gitwire connects GitHub, GitLab, and Bitbucket to your WordPress admin. Browse repos, install in one click, pull updates, and switch branches — while Fatal Guard backs up every change and rolls back fatals before your visitors notice.

**Video caption** *(optional, under embed)*
Watch: install a private repo, pull an update, and see Fatal Guard restore a broken deploy.

---

## 2. Features — 9 items with icons

**Section heading:** Everything you need to deploy from Git
**Section subcopy:** Built for agencies and developers on shared hosting — no Git binary required on the server.

| # | Title | Short description | Icon (Lucide) |
|---|-------|-------------------|---------------|
| 1 | **Fatal Guard** | Backups, auto-rollback, and a block on commits that already crashed your site | `shield-check` |
| 2 | **One-click install** | Install any repo as a plugin or theme from the admin — no ZIP upload | `download` |
| 3 | **GitHub, GitLab & Bitbucket** | Cloud and self-hosted GitLab; connect multiple providers at once | `git-branch` |
| 4 | **Smart detection** | Detects plugin, classic theme, or block theme and installs to the right folder | `scan-search` |
| 5 | **Browse your repos** | Search and filter every repo your token can access, including private | `search` |
| 6 | **Pull latest** | Update installed code to the newest commit on the current branch | `refresh-cw` |
| 7 | **Branch switching** | Point an install at another branch for staging and feature tests | `git-merge` |
| 8 | **Installed hub** | Activate plugins, switch themes, view commits, and remove tracked installs | `layout-dashboard` |
| 9 | **Deploy log** | A timestamped record of every install, update, branch switch, and rollback | `scroll-text` |

---

## 3. Collapsible cards on scroll (4 pillars × 4 sub-features)

*Suggested layout: sticky visual on one side, accordion on the other. Lead with Fatal Guard.*

### Card 1 — Fatal Guard  · badge: "Built-in safety"

**Intro:** Bad commits happen. Gitwire treats recovery as a first-class feature — not an afterthought.

| Sub-feature | Detail |
|-------------|--------|
| **Backup before every overwrite** | Before install or update, Gitwire copies the existing plugin or theme so you always have a restore point. |
| **Auto-rollback on PHP fatals** | If a fatal error occurs during install or update, the previous files are restored and broken plugins are deactivated automatically. |
| **Safe plugin activation** | Activation runs through WordPress core's sandbox, so parse and compile errors are caught before the plugin is marked active. |
| **Theme verification & known-fatal blocking** | Active-theme updates are verified with a loopback request to your site. Commits that already caused a fatal are flagged and won't be pulled again until you ship a fix. |

### Card 2 — Deploy from Git

**Intro:** From repository to `wp-content` without leaving WordPress.

| Sub-feature | Detail |
|-------------|--------|
| **One-click install** | Pick a repo and branch; Gitwire downloads and extracts into plugins or themes. |
| **Pull to latest** | Update an existing install to the HEAD of its branch from the Installed panel. |
| **No Git on the server** | Works on shared hosting — only WordPress and PHP 8.1+ required. |
| **Conflict-safe slugs** | If a folder name is already taken, Gitwire assigns a unique slug instead of overwriting another package. |

### Card 3 — Connect & discover

**Intro:** Your full catalog, inside the admin.

| Sub-feature | Detail |
|-------------|--------|
| **Multiple accounts, any provider** | Save several GitHub, GitLab, and Bitbucket connections at once — including two accounts on the same provider. Tokens are stored server-side only. |
| **Shared or private connections** | Save a connection for every admin on the site (the default), or keep it private to your own account. |
| **Unified browse list** | See repos from every connected account in one searchable list, cached so large accounts stay responsive. |
| **Smart Install** | Optional auto-detection of plugin vs theme while you browse; override anytime in the install modal. |

### Card 4 — Manage installations

**Intro:** One dashboard for everything you deployed from Git.

| Sub-feature | Detail |
|-------------|--------|
| **Installed table** | Sort, search, and see type, branch, HEAD, and update availability at a glance. |
| **Lifecycle actions** | Activate and deactivate plugins; switch themes; delete installs and clean up tracking. |
| **Branch picker** | Switch branches without reinstalling — ideal for staging before production. |
| **Activity & deploy log** | A timestamped record of every install, update, branch switch, and rollback — with the commits behind each change, including the SHA that caused a fatal. |

---

## 4. FAQ

**Do I need Git installed on my server?**
No. Gitwire uses your Git host's API and WordPress's own filesystem — the same model as other "deploy from Git" plugins, without SSH or a git binary.

**Which Git providers are supported?**
GitHub, GitLab (including self-hosted), and Bitbucket. Connect one or more in Settings.

**Can I use private repositories?**
Yes. Add a personal access token with read access to the repos you need. Tokens stay in your WordPress database and are only used for server-side API calls.

**What permissions do tokens need?**
GitHub: `repo` (read). GitLab: `read_api`. Bitbucket: repository read via app password or token — see Docs for exact scopes.

**Can I connect more than one account?**
Yes. Save multiple connections per provider — two GitHub accounts, for example — and browse repos from all of them in a single list.

**Can my team share connections?**
By default, a saved connection is available to every admin on the site, so your team can browse and install from it without each adding their own token. Other admins can use the connection but can't read the token itself — it stays server-side. Prefer to keep it to yourself? Save the connection as private to your own account.

**Does Gitwire keep a log of deployments?**
Yes. Every install, update, branch switch, and rollback is recorded with a timestamp and the commit behind it — so you can see exactly what changed, when, and which commit caused a fatal.

**Can I install themes as well as plugins?**
Yes. Gitwire detects classic themes, block themes, and plugins from repository contents. You can override the type during install.

**How does Fatal Guard work?**
Gitwire backs up before overwriting files. If a PHP fatal occurs during install or update, it restores the backup, deactivates a broken plugin when appropriate, and shows an admin notice with the error details. Active-theme updates are verified after deploy via loopback, and known-bad commits are blocked from being pulled again.

**Can I switch branches after install?**
Yes. Use the branch control in the Installed panel to point the site at another branch without a full reinstall.

**Does Gitwire auto-deploy on every git push?**
Not in the current release. You pull updates from the WordPress admin, so you stay in control of when code goes live. Push-to-deploy may come later — check the changelog.

**What WordPress and PHP versions are required?**
WordPress 6.9+ and PHP 8.1+.

**Is multisite supported?**
> ⚠️ TODO — verify in your own environment before publishing. Competitors claim multisite; test and write the real answer here.

**Where is my code stored?**
In the normal `wp-content/plugins` or `wp-content/themes` directories — not a separate sandbox. Uninstalling through WordPress removes the files, and Gitwire cleans up its install records when you delete through the core UI.

---

## 5. CTA section

**Headline**
Stop deploying over FTP

**Subcopy**
Connect your Git account, install your first repo in minutes, and let Fatal Guard handle the commits that don't go to plan.

**Primary button**
Get Gitwire

**Secondary button** *(optional)*
View documentation

**Trust line** *(small text)*
GPL-licensed · No Git on your server · Tokens never sent to gitwire.app

---

## 6. Footer — two columns

**Product**
- Features (`#features`)
- Pricing (`#pricing`)
- FAQ (`#faq`)
- Documentation
- Changelog
- Blog

**Legal & support**
- Privacy Policy
- Terms of Service
- Contact / Support
- GitHub (issues or repo, if public)
- WordPress.org plugin page

**Bottom bar**
© {year} Gitwire · Deploy plugins & themes from Git
Social: X / GitHub *(optional)*

---

## Positioning vs references

| Competitor angle | Gitwire angle (use on site) |
|------------------|------------------------------|
| **WP Pusher:** pain of FTP, push-to-deploy | Admin pull + Fatal Guard — control *when* you deploy, safety *when* code fails |
| **Deployer:** providers, logging, cache flush | Matches the deploy log, and adds recovery + branch switching + a fatal-aware history — don't claim cache flush unless you build it |

---

## Open TODOs before publish

- [ ] Test and rewrite the **multisite** FAQ answer.
- [ ] Confirm Bitbucket token scope wording for the Docs link.
- [ ] Decide the primary CTA destination (WordPress.org vs in-app "Start free").
- [ ] Decide whether **private (per-user) connections** are a Pro-tier feature; keep the copy tier-neutral until then.
- [ ] ⚠️ **Trust/security:** a *shared* connection lets every admin browse and install with your token (they can't read it, but they can act with it). Make this explicit in the Settings UI and Docs — and confirm the copy line in the "Can my team share connections?" FAQ matches the real behavior before publish.
- [ ] Only claim **cache flush** / **push-to-deploy** once those ship.
