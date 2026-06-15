<?php

namespace sunnybyte\lokilogger\log;

use Craft;
use craft\helpers\Json;
use craft\helpers\Template;
use craft\queue\QueueLogBehavior;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Psr\Log\LogLevel;
use sunnybyte\lokilogger\queue\LokiPushException;
use sunnybyte\lokilogger\queue\PushLogsJob;
use yii\i18n\PhpMessageSource;
use yii\log\Logger;
use yii\log\Target;
use yii\web\HttpException;

/**
 * Ships logs to Loki's HTTP push API.
 *
 * Ships the same severity levels as Craft's default policy: the plugin passes
 * the effective PSR-3 log level in as $minLevel (info in devMode, warning
 * otherwise), and init() translates it to the matching Yii severity levels.
 *
 * Records are formatted through Monolog's JsonFormatter, so the log lines share
 * the same JSON shape a MonologTarget + Alloy would emit and query the same way
 * in Grafana. Each shipped line is additionally enriched, via the "extra"
 * object, with request context (environment, method, path, route, content type,
 * user agent, redacted body) and, for Twig render errors, the offending
 * template path and line. Instead of pushing over HTTP on the request path,
 * each batch is handed to a Craft queue job (PushLogsJob), which Cloud's
 * auto-processed queue worker delivers. This keeps logging off the hot path.
 */
class LokiTarget extends Target
{
    /**
     * Reentrancy guard. Set true while the queue job is delivering a batch, so
     * any log the sending path itself emits (Guzzle, our own errors) is
     * dropped here rather than enqueueing another job, preventing a log storm
     * when Loki is unreachable.
     */
    public static bool $shipping = false;

    /** Loki push endpoint, e.g. https://yourloki.com/loki/api/v1/push */
    public string $endpoint = '';

    /** Shared API key sent as the X-Api-Key header. */
    public string $apiKey = '';

    /** Loki stream labels (kept low-cardinality): app, env, site, host. */
    public array $labels = [];

    /**
     * Categories to drop. The first two mirror Craft's own MonologTarget so we
     * ship the same set Craft logs: missing-translation messages and 404s
     * (which Craft logs as an HttpException but treats as routine noise, not an
     * error).
     *
     * The last two break the delivery-failure loop. When a push job gives up it
     * throws, and Craft logs that failure two ways: the exception itself via the
     * error handler (category = LokiPushException) and a summary line from
     * QueueLogBehavior::afterError. Both are error-level and would otherwise be
     * captured on the queue worker route and re-enqueued as another push job,
     * looping while Loki is unreachable. Excepting the afterError category drops
     * only the summary line for all jobs; other jobs' real exceptions still ship
     * via their own (exception-class) category.
     *
     * @var array
     */
    public $except = [
        PhpMessageSource::class . ':*',
        HttpException::class . ':404',
        LokiPushException::class,
        QueueLogBehavior::class . '::afterError',
    ];

    /** Max log lines per queue job; larger batches are chunked. */
    public int $batchSize = 500;

    /**
     * Routes treated as routine noise: for these, only error-level lines are
     * sent and everything below is dropped. Entries ending in `*` match by
     * prefix. All of these re-emit Craft's bootstrap logging (DB open, session,
     * module/plugin init) on every hit with nothing request-specific worth
     * keeping:
     *  - CP background and asset requests fired while a CP tab is open: session
     *    keepalive (users/session-info), the Queue Manager utility
     *    (utilities/show-utility), the queue HUD, SVG icons, update checks, etc.
     *    (queue/*, app/*). Without this they'd flood Loki continuously.
     *  - The queue worker itself (queue/run, queue/exec, under queue/*). Locally
     *    Craft runs the queue over web, on Cloud over the console. Without this
     *    the worker ships its own bootstrap logs as a new PushLogsJob, which the
     *    worker then runs, booting and re-logging again: a feedback loop.
     * A genuine error on any of these routes still gets through.
     */
    private const EXCLUDED_ACTION_ROUTES = [
        'users/session-info',
        'utilities/show-utility',
        'queue/*',
        'app/*',
    ];

    /**
     * Minimum PSR-3 level to ship, mirroring Craft's own configured log level.
     * The plugin injects Craft's effective level; the default matches Craft's
     * non-devMode default so the target is still sensible if constructed alone.
     */
    public string $minLevel = LogLevel::WARNING;

    private ?JsonFormatter $formatter = null;

    public function init(): void
    {
        parent::init();

        // Capture the same severity levels Craft logs (derived from $minLevel).
        // Exceptions are logged by Craft at error level, so they're covered too.
        $this->setLevels($this->yiiLevelsForPsrLevel($this->minLevel));

        // Don't append $_SERVER/$_POST dumps to every flush.
        $this->logVars = [];

        $this->formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true);
        $this->formatter->includeStacktraces(true);
    }

    /**
     * Build a Loki push payload from the buffered messages and enqueue delivery.
     */
    public function export(): void
    {
        // Never ship logs generated by the sending path itself.
        if (self::$shipping || $this->apiKey === '' || $this->endpoint === '') {
            return;
        }

        // On the queue worker and the CP polling route, ship only errors: these
        // requests re-emit Craft's bootstrap logging on every run, and the
        // worker would otherwise re-ship its own logs in an endless loop.
        $errorsOnly = $this->isExcludedRequest();

        // Request context (method/path/route) attached to every line in this
        // batch; identical for all of them since a flush covers one request.
        $requestExtra = $this->requestExtra();

        $values = [];
        foreach ($this->messages as $message) {
            if ($errorsOnly && $message[1] !== Logger::LEVEL_ERROR) {
                continue;
            }
            $values[] = $this->toLokiValue($message, $requestExtra);
        }

        if ($values === []) {
            return;
        }

        // Chunk so a noisy request can't produce one oversized job.
        foreach (array_chunk($values, $this->batchSize) as $chunk) {
            Craft::$app->getQueue()->push(new PushLogsJob([
                'endpoint' => $this->endpoint,
                'labels' => $this->labels,
                'values' => $chunk,
            ]));
        }
    }

    /**
     * Convert a Yii log message tuple to a Loki [nanoTimestamp, line] value.
     *
     * @param array $message [text, level, category, timestamp, traces, memory]
     * @param array<string,string> $extra Request context merged into the line's
     *     Monolog "extra" object (method/path/route).
     * @return array{0:string,1:string}
     */
    private function toLokiValue(array $message, array $extra = []): array
    {
        [$text, $level, $category, $timestamp] = $message;

        $context = [];
        if ($text instanceof \Throwable) {
            $context['exception'] = $text;
            $line = $text->getMessage();
            // Add the offending template (path + line) for Twig render errors.
            $extra += $this->twigTemplate($text);
        } elseif (is_string($text)) {
            $line = $text;
        } else {
            $line = \yii\helpers\VarDumper::export($text);
        }

        $datetime = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $timestamp))
            ?: (new \DateTimeImmutable())->setTimestamp((int)$timestamp);

        $record = new LogRecord(
            datetime: $datetime,
            channel: (string)$category,
            level: $this->monologLevel($level),
            message: $line,
            context: $context,
            extra: $extra,
        );

        $jsonLine = rtrim($this->formatter->format($record), "\n");

        // Loki requires nanosecond timestamps as strings. Compute with integer
        // math to avoid float precision loss at epoch-nanosecond magnitudes.
        $seconds = (int)$datetime->format('U');
        $micros = (int)$datetime->format('u');
        $nano = (string)($seconds * 1_000_000_000 + $micros * 1_000);

        return [$nano, $jsonLine];
    }

    /**
     * Template path and line for a Twig render error, as "extra" fields. Two
     * sources, tried in order:
     *  1. The Twig error's own source context. Works for syntax/loader errors,
     *     which Craft logs intact.
     *  2. Compiled-template stack frames mapped back to source via Craft's
     *     Template helper. Needed for runtime errors: Craft's ErrorHandler
     *     unwraps Twig RuntimeErrors to the underlying cause before logging, so
     *     the source context is gone and only the stack frames remain.
     * Returns [] for non-template errors, so the fields only appear when a
     * template is actually involved.
     *
     * @return array<string,string|int>
     */
    private function twigTemplate(\Throwable $e): array
    {
        // 1. Source context still attached (syntax/loader errors).
        for ($ex = $e; $ex !== null; $ex = $ex->getPrevious()) {
            if (!$ex instanceof \Twig\Error\Error) {
                continue;
            }
            $source = $ex->getSourceContext();
            $path = $source?->getPath() ?: $source?->getName();
            if ($path === null || $path === '') {
                continue;
            }
            $template = ['template' => $path];
            if ($ex->getTemplateLine() > 0) {
                $template['templateLine'] = $ex->getTemplateLine();
            }
            return $template;
        }

        // 2. Recover from compiled-template stack frames (runtime errors).
        for ($ex = $e; $ex !== null; $ex = $ex->getPrevious()) {
            $frames = $ex->getTrace();
            array_unshift($frames, ['file' => $ex->getFile(), 'line' => $ex->getLine()]);
            foreach ($frames as $frame) {
                if (empty($frame['file'])) {
                    continue;
                }
                $resolved = Template::resolveTemplatePathAndLine($frame['file'], $frame['line'] ?? null);
                if ($resolved === false || $resolved[0] === null) {
                    continue;
                }
                $template = ['template' => $resolved[0]];
                if ($resolved[1] !== null) {
                    $template['templateLine'] = $resolved[1];
                }
                return $template;
            }
        }

        return [];
    }

    /**
     * Request context attached to every shipped line as Monolog "extra" fields:
     * HTTP method, full requested path (including query string), Craft's
     * resolved route, content type, user agent, and the (redacted) request body.
     * Queryable in Grafana via `| json` (e.g. extra_path=~"/bad"). Deliberately
     * kept out of the Loki stream labels: request paths and the like are
     * high-cardinality and would explode the label index. (Environment isn't
     * included here; it's already a stream label.)
     *
     * @return array<string,string>
     */
    private function requestExtra(): array
    {
        $request = Craft::$app->getRequest();

        // Console requests (queue worker, commands) have no HTTP path; the
        // resolved route is the only meaningful locator.
        if ($request->getIsConsoleRequest()) {
            return ['route' => (string)Craft::$app->requestedRoute];
        }

        $extra = [];

        $path = $request->getFullPath();
        $queryString = $request->getQueryStringWithoutPath();
        if ($queryString !== '') {
            $path .= '?' . $queryString;
        }

        $extra['method'] = $request->getMethod();
        $extra['path'] = $path;
        $extra['route'] = (string)Craft::$app->requestedRoute;

        $headers = $request->getHeaders();
        if (($contentType = $headers->get('Content-Type')) !== null) {
            $extra['contentType'] = $contentType;
        }
        if (($userAgent = $headers->get('User-Agent')) !== null) {
            $extra['userAgent'] = $userAgent;
        }
        if (($body = $this->requestBody($request)) !== null) {
            $extra['body'] = $body;
        }

        return $extra;
    }

    /**
     * The request body for the log line, with sensitive values redacted the same
     * way Craft's own logging does (the security component's sensitive-keyword
     * list). Parsed body params are preferred so form and JSON payloads are both
     * redactable; a raw body is the fallback (itself redacted when it's JSON).
     * Returns null when there's no body.
     */
    private function requestBody(\yii\web\Request $request): ?string
    {
        try {
            $params = $request->getBodyParams();
        } catch (\Throwable) {
            $params = [];
        }

        if ($params !== []) {
            return Json::encode(Craft::$app->getSecurity()->redactIfSensitive('', $params));
        }

        $raw = $request->getRawBody();
        if ($raw === '') {
            return null;
        }

        try {
            $decoded = Json::decode($raw);
            if (is_array($decoded)) {
                return Json::encode(Craft::$app->getSecurity()->redactIfSensitive('', $decoded));
            }
        } catch (\Throwable) {
            // Non-JSON raw body; ship as-is.
        }

        return $raw;
    }

    /**
     * Whether the current request targets one of the excluded routes, matching
     * exact entries and `*`-suffixed prefixes in EXCLUDED_ACTION_ROUTES.
     *
     * Matches on Craft's resolved route (requestedRoute), which is populated for
     * both web and console requests by the time export() runs at shutdown. This
     * is more reliable than getActionSegments(), which is null for CP routes
     * like utilities/show-utility. Console requests are matched too, so the
     * queue worker (console queue/run|exec on Cloud, under queue/*) is excluded;
     * other console commands don't match and keep logging normally.
     */
    private function isExcludedRequest(): bool
    {
        $route = (string)Craft::$app->requestedRoute;
        if ($route === '') {
            return false;
        }

        foreach (self::EXCLUDED_ACTION_ROUTES as $pattern) {
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($route, substr($pattern, 0, -1))) {
                    return true;
                }
            } elseif ($route === $pattern) {
                return true;
            }
        }

        return false;
    }

    private function monologLevel(int $yiiLevel): Level
    {
        return match ($yiiLevel) {
            Logger::LEVEL_ERROR => Level::Error,
            Logger::LEVEL_WARNING => Level::Warning,
            Logger::LEVEL_INFO => Level::Info,
            Logger::LEVEL_TRACE => Level::Debug,
            default => Level::Info,
        };
    }

    /**
     * Translate a PSR-3 level into the set of Yii severity levels at or above
     * it, so a Craft level of "warning" ships error+warning, "info" adds info,
     * and "debug" also adds trace. Yii has no level below trace, so anything
     * more verbose than debug behaves the same as debug.
     *
     * @return string[] Yii Target level names accepted by setLevels()
     */
    private function yiiLevelsForPsrLevel(string $psrLevel): array
    {
        $threshold = MonologLogger::toMonologLevel($psrLevel)->value;

        // Yii's loggable severities mapped to their Monolog equivalents.
        $candidates = [
            'error' => Level::Error,
            'warning' => Level::Warning,
            'info' => Level::Info,
            'trace' => Level::Debug,
        ];

        $levels = [];
        foreach ($candidates as $name => $level) {
            if ($level->value >= $threshold) {
                $levels[] = $name;
            }
        }

        // A threshold above Yii's highest severity (error) can't be represented
        // exactly; ship error, the closest level, so we never go fully silent.
        return $levels ?: ['error'];
    }
}
