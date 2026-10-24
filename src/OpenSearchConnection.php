<?php

namespace Ernestdefoe\OpenSearch;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

/**
 * Every call to the cluster goes through here, so the rest of the extension
 * never touches HTTP and connection settings are read in exactly one place.
 *
 * There is deliberately no OpenSearch SDK dependency. The handful of endpoints
 * a search driver needs — _bulk, _search, _delete_by_query, index create/delete
 * — are stable REST that has not changed shape across major versions, and the
 * official client pins its own transport stack, which is a large dependency to
 * take on for that. It also means this works unmodified against Elasticsearch.
 *
 * The password is a secret: it is read server-side only and never reaches the
 * forum payload.
 */
class OpenSearchConnection
{
    /**
     * How long one outage is reported as one log line.
     */
    protected const DOWN_TTL = 60;

    private ?Client $http = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Config $config,
        protected Cache $cache,
        protected LoggerInterface $log
    ) {
    }

    protected function setting(string $key, string $default = ''): string
    {
        return trim((string) $this->settings->get('ernestdefoe-opensearch.'.$key, $default));
    }

    public function url(): string
    {
        return rtrim($this->setting('url'), '/');
    }

    public function username(): string
    {
        return $this->setting('username');
    }

    public function password(): string
    {
        return $this->setting('password');
    }

    /**
     * Clusters are commonly reached over a container network with a self-signed
     * certificate, so verification is a setting rather than a given. It
     * defaults ON: a driver that silently accepted any certificate would be a
     * worse default than one that makes you tick a box.
     */
    public function verifyTls(): bool
    {
        return (bool) $this->settings->get('ernestdefoe-opensearch.verify_tls', true);
    }

    /**
     * Keeps indices separate when several forums share one cluster. Falls back
     * to a slug of the forum host so two installs never clobber each other.
     * OpenSearch index names must be lowercase.
     *
     * 🚨 The host comes from config.php: Flarum 2 has no `forum_url` setting,
     * and reading one gave every forum the same "flarum_" prefix. Installs
     * that were already indexed under it keep it — a migration stores
     * "flarum" as their prefix, so their indices are not orphaned.
     */
    public function prefix(): string
    {
        $prefix = $this->setting('index_prefix');
        if ($prefix === '') {
            $prefix = $this->config->url()->getHost() ?: 'flarum';
        }

        return preg_replace('/[^a-z0-9_]/', '_', strtolower($prefix)).'_';
    }

    public function indexName(string $index): string
    {
        return $this->prefix().$index;
    }

    public function configured(): bool
    {
        return $this->url() !== '';
    }

    protected function client(): Client
    {
        if ($this->http !== null) {
            return $this->http;
        }

        // A cluster that is down or stuck must fail a visitor's request fast:
        // 2 s to connect, 2 s for the answer, then the search falls back to
        // the database. The CLI (rebuilds, queue workers) gives a big _bulk
        // longer to be processed.
        return $this->http = new Client([
            'base_uri' => $this->url().'/',
            'timeout' => PHP_SAPI === 'cli' ? 30 : 2,
            'connect_timeout' => 2,
            'verify' => $this->verifyTls(),
        ]);
    }

    /**
     * Logs a failed call to the cluster once per outage, not once per
     * request: the first failure sets a short-lived flag and later ones stay
     * quiet until it expires. Only reached on failure, so it costs nothing
     * while the cluster is up.
     */
    public function unreachable(string $what, string $reason): void
    {
        if ($this->cache->add('ernestdefoe-opensearch.down', true, self::DOWN_TTL)) {
            $this->log->warning("[opensearch] $what failed: $reason. Until the cluster answers, searches use the database and changes are not indexed; `php flarum opensearch:index` catches the index up.");
        }
    }

    /**
     * Issue a request against the cluster.
     *
     * @param  string  $body  Raw body — used for _bulk, which is NDJSON and not JSON.
     * @return array{status: int, body: array<string, mixed>}
     *
     * @throws \RuntimeException on transport failure (a refused connection, DNS,
     *                           timeout). HTTP error *statuses* are returned, not
     *                           thrown, because callers treat "index missing" and
     *                           "query failed" very differently.
     */
    public function request(string $method, string $path, ?array $json = null, ?string $body = null, ?string $contentType = null): array
    {
        $options = [
            'http_errors' => false,
            'headers' => ['Content-Type' => $contentType ?? 'application/json'],
        ];

        if ($this->username() !== '') {
            $options['auth'] = [$this->username(), $this->password()];
        }

        if ($body !== null) {
            $options['body'] = $body;
        } elseif ($json !== null) {
            $options['json'] = $json;
        }

        $response = $this->client()->request($method, ltrim($path, '/'), $options);

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getBody(), true) ?: [],
        ];
    }

    /** A short human-readable status for the admin "Test connection" button. Never throws. */
    public function ping(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'error' => 'not_configured'];
        }

        try {
            $res = $this->request('GET', '/');

            if ($res['status'] === 401 || $res['status'] === 403) {
                return ['ok' => false, 'error' => 'authentication failed'];
            }

            if ($res['status'] >= 300) {
                return ['ok' => false, 'error' => 'HTTP '.$res['status']];
            }

            $v = $res['body']['version'] ?? [];

            return [
                'ok' => true,
                'distribution' => (string) ($v['distribution'] ?? 'elasticsearch'),
                'version' => (string) ($v['number'] ?? '?'),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
