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

    public function rules(): array
    {
        return [
            [['apiKey', 'pushUrl', 'site', 'host'], 'trim'],
            [['apiKey', 'pushUrl', 'site', 'host'], 'string'],
        ];
    }
}
