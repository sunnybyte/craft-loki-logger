# Changelog

## 1.0.2 - 2026-10-01

- Added a **Suppressed logs** setting to drop log lines by category (exact or `*` prefix) and/or message substring
  before they're sent to Loki. Defaults to suppressing `Invalid token` and CSRF
  (`Unable to verify your data submission.`) bad-request errors.
- `PushLogsJob` now makes one push attempt per job and, on failure, re-queues a delayed copy (after 30s, then 90s)
  instead of sleeping in the queue worker, so a Loki outage no longer blocks other queue jobs. Still 3 attempts in total.
- Increased the Loki connect timeout from 3s to 5s.

## 1.0.1 - 2026-06-16

- Changed log level to use Craft defaults based on dev mode rather than most verbose log target since some plugins 
aren't honoring dev mode configuration.

## 1.0.0 - 2026-06-15

- Initial release.
- `LokiTarget` log target sends `info`/`warning`/`error` logs (and exceptions as per Craft's configured log level) 
  to Loki's HTTP push API, formatted with Monolog's `JsonFormatter`.
- Delivery runs in a Craft queue job (`PushLogsJob`) to keep logging off the
request path, with a reentrancy guard and bounded retries.
- Supports Env-driven config in Plugin Settings: `LOKI_API_KEY`, `LOKI_PUSH_URL`, `LOKI_SITE`.
- `loki-logger/test` console command to validate connectivity and the capture path.
