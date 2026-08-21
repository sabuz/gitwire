# Gitwire

WordPress plugin that installs GitHub/GitLab repositories as plugins or themes directly from the WP admin.

## Stack

- **PHP**: WordPress plugin, PSR-4 via `autoload.php`, namespace `Gitwire\`
- **JS/React**: `@wordpress/scripts` (webpack), plain JS with JSDoc, components in `src/`
- **Standards**: WordPress Coding Standards (WPCS) for PHP, `@wordpress/eslint-plugin` for JS

## Commands

```bash
npm run build          # production build
npm run start          # dev build with watch
npm run lint           # JS + SCSS + PHP
npm run lint:php:fix   # auto-fix PHP
npm run lint:js:fix    # auto-fix JS
npm run pre-pr-check   # lint + PHPStan + build (run before pushing)
composer phpunit       # PHP tests
```

## PHP conventions

- Short array syntax `[]`, PHP 8.0+
- `@since` tag on every method
- `@return void` explicit on void methods
- DocBlocks follow WPCS format: single-line description, blank line, tags
- Class files named `class-{slug}.php`, interface files `interface-{slug}.php`
- No inline comments unless the *why* is non-obvious. When needed, written like a human left a note, not a description of what the next line does

## JS conventions

- Functional React components, `@wordpress/element` (not `react` directly)
- `@wordpress/i18n` for all user-facing strings
- `.js` extension even for JSX files
- Lazy-load heavy panel components

## Comments, important

Write comments like a developer left a quick note, not like documentation. Prefer one line. Say the *why*, never the what.

**One line uses `//`. More than one line uses `/* */`. Never stack `//` lines.**

Bad, stacked `//`:
```php
// We need to check if the file exists before we attempt to read it
// because if it doesn't exist the file_get_contents call will fail
// and we'll get a PHP warning in the logs.
```

Good, when one line carries it:
```php
// file_get_contents warns on missing files.
```

Good, when it genuinely needs two:
```php
/*
 * Bitbucket 302s to an S3 host and WP_Http replays the header set,
 * so following the redirect would hand the API token to Amazon.
 */
```

WPCS notes: a block comment needs a blank line above it, and a `//` comment has to end in a full stop. Keep `// phpcs:` directives on their own single line, never inside a block.

If a comment wants four or more lines, it wants a doc instead. Cut it down or move it out.

## Repo detection logic (class-repository-detector.php)

Detection priority, highest confidence first:

1. `style.css` with `Theme Name:` header → confirmed theme
   - + `theme.json` → block theme (high)
   - + `templates/` dir → block theme (high)
   - else → classic theme
2. PHP file with `Plugin Name:` header → plugin (high)
3. `functions.php` alone → classic theme (medium)
4. Any PHP files → plugin (low)
5. Unknown

**`theme.json` alone does not indicate a block theme**, since plugins ship it for block styling. It only promotes to block after theme identity is confirmed via `style.css` + `Theme Name:`.

## Architecture notes

- `Repository_Detector::detect()` is provider-agnostic and takes callables for fetching contents so GitHub and GitLab share the same logic
- `class-installer.php` handles download, extract, backup, and WP hooks for cleanup on plugin/theme deletion
- REST endpoints in `class-rest.php`, provider abstraction via `interface-git-provider.php`
