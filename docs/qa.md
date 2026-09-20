# Interview Q&A: code snippet walkthrough

Prompt: "Pick 100-500 lines from one of your stronger WordPress projects, ideally
something showing architecture, hooks, OOP, REST API, blocks/React, or an SDK.
Explain the problem, design decisions, trade-offs, and why you chose that approach."

Snippet: `Gitwire\Error_Handler` in [includes/class-error-handler.php](../includes/class-error-handler.php)
(~250 lines of core logic; the rest of the file is bookkeeping helpers).

## Framing the problem

Gitwire lets you install a plugin or theme straight from a GitHub repo. The
obvious risk: the code you just pulled in might be broken (syntax error,
missing dependency, whatever) and a fatal PHP error on activation or update
takes down wp-admin, sometimes the whole site if it's a must-use path. Manual
recovery means SSH/SFTP to delete the plugin folder. Installs needed to fail
safe, automatically, with zero user intervention.

## The mechanism

Before touching the filesystem, `Error_Handler` writes a "guard" record to the
options table with the backup path, install path, and a timestamp. That
triggers `set_exception_handler()`, and separately registers a
`register_shutdown_function()` callback, which PHP guarantees runs even after
a raw fatal error that no normal try/catch could ever see. If the new code
dies mid-request, the shutdown handler wakes up, checks `error_get_last()`
against the fatal error types, sees the guard is still there and still fresh,
and rolls back: restores the backup, deactivates the broken plugin, writes a
notice for the admin UI. If nothing goes wrong, the guard gets cleared
normally and the shutdown handler is a no-op.

## Design decisions worth highlighting

1. **Two independent detection paths, not one.** `error_get_last()` catches
   native fatals (parse errors, `E_ERROR`), but debug plugins like Query
   Monitor sometimes install their own exception handler and can swallow an
   uncaught exception before it becomes a loggable fatal, or call `exit()`
   early. `Error_Handler` also installs its own `set_exception_handler()`,
   chained ahead of anyone else's, and flags a boolean the shutdown handler
   checks too. Relying on just one signal left a real gap (referenced in the
   code as issue #6).

2. **Time-boxed guard, not just presence-checked.** The guard option has to be
   both present and less than 15 minutes old (`GUARD_MAX_AGE`). Without that,
   an unrelated fatal on a completely different request (someone else's
   broken plugin crashing an hour later) would trigger a bogus rollback of an
   install that already finished successfully. That's a subtle bug class:
   stale flag causes wrong-target rollback.

3. **Deactivation is deferred to `admin_init`, not called from the shutdown
   function itself.** `deactivate_plugins()` touches the DB and isn't
   safe/reliable that late in the request lifecycle, so the shutdown handler
   just writes a "pending deactivate" flag and a normal hook picks it up on
   the very next admin page load.

4. **Raw SQL fallback for the option reads/writes in the shutdown path.** A
   shutdown function can technically fire before WordPress has fully
   bootstrapped `update_option()`/`delete_option()`. Rather than assume those
   functions exist, the class checks `function_exists()` first and falls back
   to direct `$wpdb->prepare()` queries. Trade-off: more code, duplicated
   logic, but the safety net doesn't itself depend on the thing that might
   have just crashed.

## Trade-offs / defending it under pushback

- **"Why not just try/catch around the activation call?"** Doesn't work for
  real fatals (parse errors, `E_ERROR`) since PHP doesn't throw those as
  catchable exceptions, and a fatal can also come from a completely separate
  file included later in the request. `register_shutdown_function()` is the
  only hook guaranteed to run regardless of how the process died.
- **"Isn't a raw SQL fallback a smell?"** Normally yes, but it's scoped
  narrowly to a code path that explicitly cannot trust WordPress to be
  loaded, and it degrades gracefully: it tries the WP API first and only
  drops to SQL if that's unavailable.
- **"What if the rollback itself fails?"** It's logged either way
  (`Logger::log`), and the notice payload records `restored: false` so the
  admin UI tells the user rather than silently pretending everything's fine.
