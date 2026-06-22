# Adding a New Git Provider

## DB schema — no changes needed

The `gitwire_connections` table is provider-agnostic by design:

| Column | Why it's flexible |
|---|---|
| `provider VARCHAR(20)` | Any short slug fits. See note on length below. |
| `identifier VARCHAR(255)` | Works for any login, username, or workspace slug. |
| `credentials TEXT NULL` | Encrypted JSON blob — whatever shape the provider needs goes here. |
| `scope VARCHAR(20)` | Provider-independent. |

Provider-specific config (instance URLs, org slugs, etc.) goes in `gitwire_connection_meta` as extra key-value pairs. GitLab's `gitlab_url` is already the established pattern. A self-hosted Gitea URL, an Azure DevOps org, a Codeberg instance — all go there without touching the main table.

### `provider` column length

`VARCHAR(20)` is tight by design — keep provider slugs short. Current slugs: `github` (6), `gitlab` (6), `bitbucket` (9). Foreseeable additions: `codeberg` (9), `gitea` (5), `azure` (5). If a slug would exceed 20 chars, shorten it by convention (`azure` not `azure-devops`).

---

## Code changes required per new provider

All additive, no existing code modified:

### Free plugin (`plugins/gitwire`)

| File | What to add |
|---|---|
| `class-{provider}-api.php` | New class implementing `interface-git-provider.php` |
| `class-public-connections.php` → `to_credentials()` | Branch for the new provider's public credential shape |
| `class-rest.php` | Rate-limit / profile fetch logic for public connections (if the provider exposes one) |

### Pro plugin (`plugins/gitwire-pro`)

| File | What to add |
|---|---|
| `class-rest.php` → `extract_credentials()` | Branch to pull credential fields from the REST request |
| `class-rest.php` → `run_credentials_test()` | Branch to instantiate the API class and return a normalized profile |
| `class-connections.php` → `to_public()` | Branch for masked credential preview fields (`token_set`, `token_preview`, etc.) |

### JS (`plugins/gitwire-pro/src`)

| File | What to add |
|---|---|
| `connections-section.js` | Form fields for the new provider's credentials |
| `shared.js` → `PROVIDER_LABELS` | Display name for the provider |

---

## Provider-specific meta keys

Document any meta keys the new provider writes to `gitwire_connection_meta` here so they don't drift:

| Provider | meta_key | Purpose |
|---|---|---|
| `gitlab` | `gitlab_url` | Self-hosted instance URL |
| _(new provider)_ | _(key)_ | _(purpose)_ |
