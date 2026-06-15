<?php

namespace sunnybyte\lokilogger;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use Psr\Log\LogLevel;
use sunnybyte\lokilogger\log\LokiTarget;
use sunnybyte\lokilogger\models\Settings;

/**
 * Loki Logger plugin.
 *
 * Sends logs from Craft to a central Loki instance via its HTTP push API,
 * using a Craft queue job so nothing happens on the request path. Ships the
 * same severity levels as Craft's default policy: info in devMode, warning
 * otherwise.
 *
 * Built for Craft Cloud, where the filesystem is ephemeral and an Alloy/Promtail
 * agent can't be installed, but works on any Craft 5 site. Log lines are
 * formatted with Monolog's JsonFormatter, identical to the file-based JSON logs
 * a MonologTarget + Alloy would emit, so they query the same way in Grafana.
 *
 * Configured via Settings -> Plugins -> Loki Logger. Each setting can be a
 * literal value or an env var reference (e.g. "$LOKI_API_KEY"), resolved at
 * runtime via App::parseEnv(). Defaults point at the LOKI_* env vars.
 *
 * @method Settings getSettings()
 * @method static Plugin getInstance()
 */
class Plugin extends \craft\base\Plugin
{
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        $config = $this->lokiConfig();
        if ($config === null) {
            // Sending not configured (no key/endpoint): don't attach the target.
            return;
        }

        Craft::$app->getLog()->targets[] = Craft::createObject([
            'class' => LokiTarget::class,
            'minLevel' => $this->craftLogLevel(),
        ] + $config);
    }

    /**
     * The PSR-3 level the Loki target ships at, mirroring Craft's own default
     * policy: info in devMode, warning otherwise.
     *
     * Deliberately derived from devMode rather than by scanning Craft's log
     * targets. Plugins (e.g. Verbb via verbb/base, Retour) inject their own
     * per-category MonologTargets pinned to `info` regardless of devMode, so
     * picking the most verbose registered target would let those drag the whole
     * Loki feed down to info in production.
     */
    private function craftLogLevel(): string
    {
        return App::devMode() ? LogLevel::INFO : LogLevel::WARNING;
    }

    /**
     * Resolved Loki sending config, or null if sending is disabled (no key).
     * Shared by the log target and the loki-logger/test console command so they
     * always agree on endpoint, key, and labels.
     *
     * @return array{endpoint:string,apiKey:string,labels:array}|null
     */
    public function lokiConfig(): ?array
    {
        $settings = $this->getSettings();

        $apiKey = $this->resolve($settings->apiKey);
        $endpoint = $this->resolve($settings->pushUrl);
        if ($apiKey === '' || $endpoint === '') {
            return null;
        }

        return [
            'endpoint' => $endpoint,
            'apiKey' => $apiKey,
            'labels' => [
                'app' => 'craft',
                'env' => App::env('CRAFT_ENVIRONMENT') ?: 'unknown',
                'site' => $this->resolve($settings->site) ?: $this->defaultSiteLabel(),
                'host' => $this->resolve($settings->host) ?: $this->defaultHostLabel(),
            ],
        ];
    }

    /**
     * Resolve just the Loki API key (settings value or $LOKI_API_KEY env var).
     * Used by PushLogsJob so the key is looked up at delivery time instead of
     * being stored in the serialized job payload (and the queue DB table).
     */
    public function lokiApiKey(): string
    {
        return $this->resolve($this->getSettings()->apiKey);
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('loki-logger/settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * Resolve a setting through App::parseEnv so literal values pass through and
     * "$ENV_VAR" references expand (to '' when the env var is unset).
     */
    private function resolve(string $value): string
    {
        $parsed = App::parseEnv($value);

        return is_string($parsed) ? trim($parsed) : '';
    }

    /**
     * Best-effort site label from the primary site URL host when LOKI_SITE
     * isn't set, e.g. "https://www.example.com" -> "example.com".
     */
    private function defaultSiteLabel(): string
    {
        $url = App::env('PRIMARY_SITE_URL') ?: App::env('SITE_URL') ?: '';
        $host = parse_url((string)$url, PHP_URL_HOST) ?: '';

        return $host !== '' ? $host : 'craft';
    }

    /**
     * Machine hostname for the `host` label when LOKI_HOST isn't set, matching
     * the per-server hostname Alloy reports (e.g. "clientname-dev"). Keep host
     * names stable and low-cardinality; ephemeral/autoscaled hosts are better
     * left unlabeled. Falls back to "unknown" if the hostname can't be read.
     */
    private function defaultHostLabel(): string
    {
        $host = gethostname();

        return ($host !== false && $host !== '') ? $host : 'unknown';
    }
}
