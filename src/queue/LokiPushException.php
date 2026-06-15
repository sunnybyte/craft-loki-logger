<?php

namespace sunnybyte\lokilogger\queue;

use RuntimeException;

/**
 * Thrown by PushLogsJob when a batch can't be delivered to Loki after all
 * retries, so the queue job is marked failed (recorded and retryable in the CP
 * Queue Manager) instead of being silently dropped.
 *
 * LokiTarget excepts this class by category: when the queue gives up on a job,
 * Craft logs the exception through the error handler, and without the carve-out
 * those error lines would be captured and re-enqueued as new push jobs, looping
 * for as long as Loki is unreachable.
 */
class LokiPushException extends RuntimeException
{
}