<?php

namespace sunnybyte\lokilogger\queue;

use Craft;
use craft\queue\BaseJob;
use sunnybyte\lokilogger\log\LokiTarget;
use sunnybyte\lokilogger\Plugin;

/**
 * Delivers a batch of log lines to Loki's HTTP push API.
 *
 * Runs in Cloud's auto-processed queue worker, off the request path. Each batch
 * is retried a few times with backoff; if it still can't be delivered the job
 * throws LokiPushException so it's marked failed (recorded and retryable in the
 * CP Queue Manager) rather than silently dropped. LokiTarget excepts that
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
    public string $endpoint = '';

    /** Loki stream labels. */
    public array $labels = [];

    /** Array of [nanoTimestamp, jsonLine] tuples. */
    public array $values = [];

    /** Delivery attempts before giving up. */
    public int $attempts = 3;

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

        $client = Craft::createGuzzleClient();

        // Guard for the entire delivery so any log emitted in here (Guzzle,
        // exceptions) is dropped by LokiTarget instead of enqueueing more jobs.
        LokiTarget::$shipping = true;
        try {
            $lastError = null;
            for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
                try {
                    $client->post($this->endpoint, [
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'Content-Encoding' => 'gzip',
                            'X-Api-Key' => $apiKey,
                        ],
                        'body' => $body,
                        'connect_timeout' => 3,
                        'timeout' => 5,
                    ]);
                    return;
                } catch (\Throwable $e) {
                    $lastError = $e;
                    if ($attempt < $this->attempts) {
                        sleep($attempt); // simple linear backoff: 1s, 2s
                    }
                }
            }

            // Out of attempts: fail the job so it's recorded and retryable in
            // the CP Queue Manager rather than silently dropped. LokiTarget
            // excepts LokiPushException by category, so Craft logging this
            // failure isn't captured and re-enqueued into a delivery loop.
            throw new LokiPushException(sprintf(
                'Failed to push %d log lines to Loki after %d attempts: %s',
                count($this->values),
                $this->attempts,
                $lastError?->getMessage() ?? 'unknown error',
            ), 0, $lastError);
        } finally {
            LokiTarget::$shipping = false;
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Push logs to Loki';
    }
}
