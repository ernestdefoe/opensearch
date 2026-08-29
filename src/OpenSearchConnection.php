<?php

namespace Ernestdefoe\OpenSearch;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;

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
    private ?Client $http = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings
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
     */
    public function prefix(): string
    {
        $prefix = $this->setting('index_prefix');
        if ($prefix === '') {
            $prefix = parse_url((string) $this->settings->get('forum_url', ''), PHP_URL_HOST) ?: 'flarum';
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

        return $this->http = new Client([
            'base_uri' => $this->url().'/',
            'timeout' => 10,
            'verify' => $this->verifyTls(),
        ]);
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
