# Security Audit

## Scope

Both GitWire Free and GitWire Pro. Updated with each major release.

---

## Checklist

### Nonces

- [ ] Every admin form has a nonce field
- [ ] Every AJAX handler verifies the nonce with `check_ajax_referer()` or `wp_verify_nonce()`
- [ ] REST endpoints that mutate state use nonce verification where appropriate (or rely on cookie auth + `permission_callback`)

### Capability Checks

- [ ] All admin page callbacks check `current_user_can( 'manage_options' )` or appropriate capability
- [ ] All REST `permission_callback` functions return `false` for unauthorized users (never `__return_true` on sensitive endpoints)
- [ ] AJAX handlers check capability before processing

### SQL Safety

- [ ] All user-supplied values in queries go through `$wpdb->prepare()`
- [ ] Model layer column whitelist prevents arbitrary column injection
- [ ] No raw string concatenation into SQL

### Input Sanitization

- [ ] `sanitize_text_field()` for plain text
- [ ] `absint()` / `intval()` for integer inputs
- [ ] `esc_url_raw()` for URLs stored in DB
- [ ] Arrays and JSON validated before use

### Output Escaping

- [ ] `esc_html()` for text in HTML context
- [ ] `esc_attr()` for HTML attribute values
- [ ] `esc_url()` for URLs in HTML
- [ ] `wp_kses_post()` for HTML content that allows some tags
- [ ] `wp_json_encode()` for data printed into JS

### REST API

- [ ] Every endpoint has a `permission_callback`
- [ ] Input validated via `args` schema with `sanitize_callback` and `validate_callback`
- [ ] No sensitive data returned to unauthenticated requests

### File Operations

- [ ] Plugin install/extract runs inside `WP_CONTENT_DIR` only
- [ ] No user-supplied paths used in `file_get_contents()` or `include()`
- [ ] ZIP extraction uses WP's `WP_Filesystem` API or `ZipArchive` with path validation

### Credentials Storage (Pro)

- [ ] API tokens stored encrypted (not plain text)
- [ ] Tokens not logged or exposed in error messages
- [ ] Token preview returns masked value only

### External Requests

- [ ] All outbound HTTP uses `wp_remote_get()` / `wp_remote_post()` (not `curl` directly)
- [ ] Request timeouts set explicitly
- [ ] Error responses from providers handled gracefully (no token leak in error output)

---

## Findings Log

| Date | Severity | Description | Status |
|---|---|---|---|
| — | — | — | — |

## Severity Levels

- **Critical** — exploitable without authentication; immediate fix required
- **High** — exploitable by authenticated users; fix before next release
- **Medium** — requires specific conditions; fix in next release
- **Low** — defense in depth; fix when convenient
- **Info** — not a vulnerability; recommendation only
