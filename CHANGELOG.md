# Changelog

## 1.0.0 - 2026-06-15

- Initial release.
- `LokiTarget` log target sends `info`/`warning`/`error` logs (and exceptions as per Craft's configured log level) 
  to Loki's HTTP push API, formatted with Monolog's `JsonFormatter`.
- Delivery runs in a Craft queue job (`PushLogsJob`) to keep logging off the
request path, with a reentrancy guard and bounded retries.
- Supports Env-driven config in Plugin Settings: `LOKI_API_KEY`, `LOKI_PUSH_URL`, `LOKI_SITE`.
- `loki-logger/test` console command to validate connectivity and the capture path.
