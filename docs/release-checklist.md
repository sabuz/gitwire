# Release Checklist

Use this for every release of both Free and Pro.

---

## Pre-Release

### Code quality

- [ ] `npm run pre-pr-check` passes (lint + type-check + build)
- [ ] `composer phpunit` passes
- [ ] Zero PHPCS errors (`npm run lint:php`)
- [ ] Zero JS lint errors (`npm run lint:js`)
- [ ] No `console.log`, `console.warn`, `console.error` in committed code
- [ ] No commented-out code left behind

### WordPress standards

- [ ] All user-facing strings use `__()` / `_e()` with the `gitwire` text domain
- [ ] All output is properly escaped (`esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`)
- [ ] All user input is sanitized on save
- [ ] All AJAX/REST endpoints have nonce verification and capability checks
- [ ] All direct DB queries use `$wpdb->prepare()` or the model layer
- [ ] No PHP notices or warnings at `WP_DEBUG = true`

### Security

- [ ] Nonce on every form and AJAX action
- [ ] Capability checks on all admin actions
- [ ] REST endpoint permission callbacks set
- [ ] No unsafe direct file includes
- [ ] SQL prepared statements everywhere

### WordPress.org compliance (Free only)

- [ ] No obfuscated code
- [ ] No calls to external URLs not disclosed in readme
- [ ] No remote tracking or analytics without disclosure
- [ ] No trademarks in plugin slug or name
- [ ] readme.txt is valid and up to date (tested up to, stable tag, changelog)
- [ ] All assets (banner, icon) meet WP.org size requirements
- [ ] Plugin URI and Author URI point to valid pages

### Versioning

- [ ] Version bumped in main plugin file (`Version:` header)
- [ ] Version bumped in `package.json`
- [ ] Version bumped in `composer.json` (if applicable)
- [ ] `GITWIRE_VERSION` constant updated (or equivalent)
- [ ] `readme.txt` changelog updated with this version's changes
- [ ] `readme.txt` `Stable tag:` updated
- [ ] `readme.txt` `Tested up to:` updated to latest WordPress

### Build

- [ ] Production build run (`npm run build`)
- [ ] Build artifacts committed or ZIP generated
- [ ] ZIP generated with `npm run plugin-zip` (uses `git archive` — not `wp-scripts plugin-zip`)
- [ ] ZIP does not contain `node_modules/`, `src/`, `.env`, or dev config

### Database

- [ ] DB migration tested on a clean install (new activation)
- [ ] DB migration tested on an upgrade from previous version
- [ ] Multisite: tested on network activation (Free)

### QA

- [ ] Manual test plan completed (see `docs/qa/manual-test-plan.md`)
- [ ] All user roles tested (Administrator, Editor, Author)
- [ ] Free → Pro upgrade path tested
- [ ] Pro deactivation (with and without data removal) tested
- [ ] Plugin deactivation tested
- [ ] Plugin deletion tested (data removed if setting enabled)

---

## Release

- [ ] Tag created in git
- [ ] GitHub release published with changelog
- [ ] WordPress.org SVN commit (Free only)
- [ ] Pro ZIP uploaded to distribution platform

---

## Post-Release

- [ ] `docs/roadmap/` updated
- [ ] Known bugs list reviewed for any resolved items
