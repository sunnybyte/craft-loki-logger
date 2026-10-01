<?php

namespace sunnybyte\lokilogger\models;

use craft\base\Model;

/**
 * Loki Logger settings.
 *
 * Each value may be a literal (e.g. "https://loki.example.com/loki/api/v1/push")
 * or an environment variable reference (e.g. "$LOKI_API_KEY"), which is resolved
 * at runtime via App::parseEnv(). Defaults point at the LOKI_* env vars, so a
 * site that already sets those keeps working without opening the settings page.
 */
class Settings extends Model
{
    /** API key sent as the X-Api-Key header. Empty disables sending. */
    public string $apiKey = '$LOKI_API_KEY';

    /** Loki push endpoint. Blank falls back to the plugin default. */
    public string $pushUrl = '$LOKI_PUSH_URL';

    /** Stream label identifying this site. Blank falls back to the site host. */
    public string $site = '$LOKI_SITE';

    /** Stream label identifying the host/server. Blank falls back to the machine hostname. */
    public string $host = '$LOKI_HOST';

    /**
     * Suppression rules: log lines matching any rule are never shipped. Each row
     * is ['category' => string, 'message' => string]; see LokiTarget::$suppress
     * for the matching semantics.
     *
     * Defaults to dropping two kinds of client-side bad requests that are noise,
     * not actionable errors (both removable in settings):
     *  - "Invalid token": a stale or malformed `token` param (bots, expired
     *    preview links).
     *  - "Unable to verify your data submission.": Yii's CSRF validation
     *    failure (expired sessions, stale cached forms, bots posting forms).
     *
     * @var array<int,array{category:string,message:string}>
     */
    public array $suppress = [
        ['category' => 'yii\web\HttpException:400', 'message' => 'Invalid token'],
        ['category' => 'yii\web\HttpException:400', 'message' => 'Unable to verify your data submission.'],
    ];

    public function rules(): array
    {
        return [
            [['apiKey', 'pushUrl', 'site', 'host'], 'trim'],
            [['apiKey', 'pushUrl', 'site', 'host'], 'string'],
            ['suppress', 'filter', 'filter' => [$this, 'normalizeSuppress']],
        ];
    }

    /**
     * Normalize editable-table input into a clean list of rules: trim both
     * fields, re-index the rows, and drop rows with both fields blank (a blank
     * row would otherwise match, and suppress, every line).
     *
     * @return array<int,array{category:string,message:string}>
     */
    public function normalizeSuppress(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            $category = trim((string)($row['category'] ?? ''));
            $message = trim((string)($row['message'] ?? ''));
            if ($category === '' && $message === '') {
                continue;
            }
            $normalized[] = ['category' => $category, 'message' => $message];
        }

        return $normalized;
    }
}
