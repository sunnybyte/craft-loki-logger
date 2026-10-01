<?php

namespace sunnybyte\lokilogger\queue;

use Craft;
use craft\queue\BaseJob;
use sunnybyte\lokilogger\log\LokiTarget;
use sunnybyte\lokilogger\Plugin;

/**
 * Delivers a batch of log lines to Loki's HTTP push API.
 *
 * Runs in Cloud's auto-processed queue worker, off the request path. Each job
 * makes a single delivery attempt. On failure it re-queues a copy of itself
 * with a delay (30s, then 60s longer for each further retry: 30s, 90s, ...)
 * instead of sleeping in the worker, so a Loki outage never blocks the other
 * jobs in the queue. Once the last attempt fails, the job throws
 * LokiPushException so it's marked failed (recorded and retryable in the CP
 * Queue Manager) rather than silently dropped. LokiTarget excepts that
 * exception's category, so the queue logging the failure can't re-enqueue
 * another push job and loop while Loki is unreachable. The LokiTarget::$shipping
 * guard is also held for the whole job so nothing logged in here gets
 * re-enqueued.
 *
 * The API key is intentionally NOT stored on the job: queued jobs are
 * serialized into the `queue` database table, so the key is looked up from the
 * plugin's config at delivery time instead. This keeps the secret out of the
 * DB and lets a rotated key take effect on the next attempt.
 */
class PushLogsJob extends BaseJob
{
    /** Delay before the first retry, in seconds. */
    private const FIRST_RETRY_DELAY = 30;

    /** Extra delay added for each retry after the first, in seconds. */
    private const RETRY_DELAY_STEP = 60;

    public string $endpoint = '';

    /** Loki stream labels. */
    public array $labels = [];

    /** Array of [nanoTimestamp, jsonLine] tuples. */
    public array $values = [];

    /** Total delivery attempts (this job plus its re-queued retries) before giving up. */
    public int $attempts = 3;

    /** Which attempt this job is, starting at 1. Incremented on each re-queued copy. */
    public int $attempt = 1;

    public function execute($queue): void
    {
        // Look the key up now rather than carrying it in the serialized payload.
        $apiKey = Plugin::getInstance()?->lokiApiKey() ?? '';
        if ($this->values === [] || $apiKey === '' || $this->endpoint === '') {
            return;
        }

        $body = gzencode((string)json_encode([
            'streams' => [[
                'stream' => $this->labels,
                'values' => $this->values,
            ]],
        ]), 6);

        // Guard for the entire delivery so any log emitted in here (Guzzle,
        // exceptions, the retry push) is dropped by LokiTarget instead of
        // enqueueing more jobs.
        LokiTarget::$shipping = true;
        try {
            try {
                Craft::createGuzzleClient()->post($this->endpoint, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Content-Encoding' => 'gzip',
                        'X-Api-Key' => $apiKey,
                    ],
                    'body' => $body,
                    'connect_timeout' => 5,
                    'timeout' => 5,
                ]);
                return;
            } catch (\Throwable $e) {
                $error = $e;
            }

            if ($this->attempt < $this->attempts) {
                // Re-queue a delayed copy rather than sleeping, so the worker
                // is free for other jobs while Loki is unreachable.
                $delay = self::FIRST_RETRY_DELAY + self::RETRY_DELAY_STEP * ($this->attempt - 1);
                $queue->delay($delay)->push(new self([
                    'endpoint' => $this->endpoint,
                    'labels' => $this->labels,
                    'values' => $this->values,
                    'attempts' => $this->attempts,
                    'attempt' => $this->attempt + 1,
                ]));
                Craft::warning(sprintf(
                    'Loki push attempt %d of %d failed, retrying in %ds: %s',
                    $this->attempt,
                    $this->attempts,
                    $delay,
                    $error->getMessage(),
                ), __METHOD__);
                return;
            }

            // Out of attempts: fail the job so it's recorded and retryable in
            // the CP Queue Manager rather than silently dropped. LokiTarget
            // excepts LokiPushException by category, so Craft logging this
            // failure isn't captured and re-enqueued into a delivery loop.
            throw new LokiPushException(sprintf(
                'Failed to push %d log lines to Loki after %d attempts: %s',
                count($this->values),
                $this->attempts,
                $error->getMessage(),
            ), 0, $error);
        } finally {
            LokiTarget::$shipping = false;
        }
    }

    protected function defaultDescription(): ?string
    {
        return $this->attempt > 1
            ? sprintf('Push logs to Loki (attempt %d of %d)', $this->attempt, $this->attempts)
            : 'Push logs to Loki';
    }
}