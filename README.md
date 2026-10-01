# Loki Logger for Craft CMS

Send Craft logs to a central [Loki](https://grafana.com/oss/loki/) instance via
its HTTP push API, using a Craft queue job so nothing happens on the request
path. The target captures the same severity levels Craft itself is configured to
log (`info` in devMode, `warning` and above otherwise, or whatever your
`config/app.php` sets).

Built for **Craft Cloud**, where the filesystem is ephemeral and an Alloy/Promtail
agent can't be installed, but it works on any Craft 5 site. Log lines are formatted
with Monolog's `JsonFormatter`, identical to the file-based JSON logs a
`MonologTarget` + Alloy would emit, so they query the same way in Grafana.

## How it works

```
Craft (web / console / queue)
  -> LokiTarget        captures Craft's configured levels, formats as JSON, enqueues a job
       -> PushLogsJob   gzips + POSTs to Loki (runs in the queue worker)
            -> Loki
```

- **No request latency**: the request only enqueues a job (a DB insert). The HTTP
  push runs later in the queue worker, which Craft Cloud processes automatically.
- **No log storms**: a reentrancy guard plus bounded retries mean a Loki outage
  fails the batch instead of recursively re-logging. Logs also stay in Cloud's
  native command/stdout history.
- **Retries don't block the queue**: each job makes one push attempt. On
  failure it re-queues a delayed copy of itself (retrying after 30s, then 90s)
  rather than sleeping in the worker. After the third failed attempt the job is
  marked failed in the Queue Manager, where it can be retried manually.
- **Low-cardinality labels**: `app="craft"`, `env`, `site`, `host`. Everything
  else (`level_name`, `message`, `context`, exception trace) lives in the JSON
  line.
- **Skips poller noise**: requests to the control panel's `queue/get-job-info`
  action (polled on a timer by the queue widget) are never shipped, so the CP
  doesn't flood Loki with near-identical lines.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Installation

```bash
composer require sunnybyte/craft-loki-logger
./craft plugin/install loki-logger
```

That's it. No `config/app.php` editing and no per-site log config: the plugin
registers the log target itself once installed.

## Configuration

Configure the plugin at **Settings -> Plugins -> Loki Logger**. Each field accepts
either a literal value or an environment variable reference (e.g. `$LOKI_API_KEY`)
via Craft's native env-var autosuggest, resolved at runtime with `App::parseEnv()`.

The fields default to the `LOKI_*` env var references below, so if you set those
env vars the plugin works without ever opening the settings page (useful on
ephemeral hosts like Craft Cloud, where settings are read-only in production):

| Setting | Default value | Required | Purpose                                                                                                                       |
|---------|---------------|----------|-------------------------------------------------------------------------------------------------------------------------------|
| API key | `$LOKI_API_KEY` | Yes | Sent as the `X-Api-Key` header. **Sending is disabled while this resolves empty**, so dev environments are no-ops by default. |
| Push URL | `$LOKI_PUSH_URL` | Yes | Loki HTTP push endpoint. Sending is disabled while this resolves empty.                                                       |
| Site label | `$LOKI_SITE` | No | `site` stream label identifying this website (e.g. `www.client.com`). Falls back to the primary site host.                    |
| Host label | `$LOKI_HOST` | No | `host` stream label identifying the server (e.g. `web1`). Falls back to the machine hostname. Keep it stable and low-cardinality. |

The `env` label is taken from `CRAFT_ENVIRONMENT`.

### Suppressing logs

The **Suppressed logs** table drops matching lines before they're queued. Each
row has two fields; a line is suppressed when it matches every non-blank field of
any row (rows with both fields blank are ignored):

| Field | Matches |
|-------|---------|
| Category | The Yii log category (the line's `channel` field in Grafana): exact, or by prefix when it ends in `*`. Exceptions are logged under their class name, except HTTP exceptions, which Yii logs under the generic `yii\web\HttpException:<status>` whatever their actual class (a `BadRequestHttpException` is `yii\web\HttpException:400`). |
| Message contains | A case-sensitive substring of the log message (the exception message for exceptions). |

By default the table has two rules, which drop routine client-side bad requests
while still shipping other 400s. Delete a row to ship those errors again:

| Category | Message contains | Cause |
|----------|------------------|-------|
| `yii\web\HttpException:400` | `Invalid token` | Stale or malformed `token` params (bots, expired preview links). |
| `yii\web\HttpException:400` | `Unable to verify your data submission.` | CSRF validation failures (expired sessions, stale cached forms, bots posting forms). |

## Verifying

```bash
./craft loki-logger/test
```

This pushes a line directly to Loki (immediate pass/fail on endpoint + key), then
emits a sample warning and error through Craft's logger to exercise the real
capture and queue path. Run `./craft queue/run` locally to deliver the queued
batch; on Craft Cloud the worker delivers it automatically.

## Querying in Grafana

Lines carry Monolog's field names, so filter on `level_name` (not `level`, which
is the numeric Monolog level):

```logql
{app="craft", site="www.client.com"} | json | level_name="ERROR"
{app="craft"} | json | level_name="WARNING" | channel="application"
```

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
