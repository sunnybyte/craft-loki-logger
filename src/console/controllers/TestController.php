<?php

namespace sunnybyte\lokilogger\console\controllers;

use Craft;
use craft\console\Controller;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use sunnybyte\lokilogger\log\LokiTarget;
use sunnybyte\lokilogger\Plugin;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Validates the Loki logging path on demand.
 *
 *   ./craft loki-logger/test
 *
 * Does two things:
 *   1. A direct, synchronous push to Loki so you get immediate pass/fail on the
 *      endpoint and API key (this is the real connectivity check).
 *   2. Emits a sample warning + error through Craft's logger, which the normal
 *      LokiTarget captures and enqueues for the queue worker to deliver, so you
 *      can confirm the production capture path too.
 */
class TestController extends Controller
{
    public function actionIndex(): int
    {
        $config = Plugin::getInstance()->lokiConfig();

        if ($config === null) {
            $this->stderr("Sending is disabled: set the API key and push URL in Settings -> Plugins -> Loki Logger (or the LOKI_* env vars).\n", Console::FG_YELLOW);
            return ExitCode::CONFIG;
        }

        $this->stdout("Endpoint: {$config['endpoint']}\n");
        $this->stdout('Labels:   ' . json_encode($config['labels']) . "\n\n");

        // 1. Direct synchronous connectivity check.
        $this->stdout("Pushing a test line directly to Loki...\n");
        $ok = $this->directPush($config);

        // 2. Exercise the real capture + enqueue path.
        $this->stdout("\nEmitting a sample warning and error through Craft's logger\n");
        $this->stdout("(captured by LokiTarget, delivered by the queue worker)...\n");
        Craft::warning('loki-logger test warning', 'loki-logger-test');
        Craft::error('loki-logger test error', 'loki-logger-test');
        $this->stdout("Done. Run `./craft queue/run` locally, or let Cloud's worker deliver it.\n");

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * @param array{endpoint:string,apiKey:string,labels:array} $config
     */
    private function directPush(array $config): bool
    {
        $formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true);
        $now = new \DateTimeImmutable();
        $line = rtrim($formatter->format(new LogRecord(
            datetime: $now,
            channel: 'loki-logger-test',
            level: Level::Warning,
            message: 'loki-logger direct connectivity test',
        )), "\n");
        $nano = (string)((int)$now->format('U') * 1_000_000_000 + (int)$now->format('u') * 1_000);

        $body = gzencode((string)json_encode([
            'streams' => [[
                'stream' => $config['labels'],
                'values' => [[$nano, $line]],
            ]],
        ]), 6);

        // Don't let this push re-enter the log target.
        LokiTarget::$shipping = true;
        try {
            $response = Craft::createGuzzleClient()->post($config['endpoint'], [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Content-Encoding' => 'gzip',
                    'X-Api-Key' => $config['apiKey'],
                ],
                'body' => $body,
                'connect_timeout' => 3,
                'timeout' => 5,
                'http_errors' => true,
            ]);
            $status = $response->getStatusCode();
            $this->stdout("Loki responded: HTTP $status (204 = accepted)\n", Console::FG_GREEN);
            return true;
        } catch (\Throwable $e) {
            $this->stderr('Push failed: ' . $e->getMessage() . "\n", Console::FG_RED);
            return false;
        } finally {
            LokiTarget::$shipping = false;
        }
    }
}
