<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Api;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Internal transport. Callers only use fixed API paths on the configured origin. */
final class Transport
{
    public function __construct(private readonly HttpClientInterface $http, private readonly string $instanceUrl)
    {
    }

    /** @return array{0: array, 1: array<string, list<string>>} */
    public function request(string $method, string $path, #[\SensitiveParameter] array $options = []): array
    {
        $url = parse_url($this->instanceUrl);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['query']) || isset($url['fragment'])
            || !in_array($url['path'] ?? '', ['', '/'], true)) {
            throw new ApiException('Configure a valid HTTPS ChurchTools origin without credentials, path, query or fragment.');
        }
        if (!str_starts_with($path, '/api/') || str_contains($path, '..') || str_contains($path, '?')) {
            throw new ApiException('Invalid API path.');
        }
        $response = null;
        try {
            $response = $this->http->request($method, rtrim($this->instanceUrl, '/').$path, array_replace($options, [
                'max_redirects' => 0, 'timeout' => 20, 'max_duration' => 60,
            ]));
            $status = $response->getStatusCode();
            if ($status !== 200) {
                throw new ApiException('ChurchTools request failed (HTTP '.$status.').', $status);
            }
            $content = '';
            foreach ($this->http->stream($response) as $chunk) {
                $part = $chunk->getContent();
                if (strlen($content) + strlen($part) > 8388608) {
                    throw new ApiException('ChurchTools response exceeds supported memory budget.');
                }
                $content .= $part;
            }
            $body = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($body) || !array_key_exists('data', $body)) {
                throw new ApiException('Invalid ChurchTools response envelope.');
            }

            return [$body, $response->getHeaders(false)];
        } catch (ExceptionInterface|\JsonException|\InvalidArgumentException) {
            throw new ApiException('ChurchTools transport or JSON response failed.');
        } finally {
            $response?->cancel();
        }
    }
}
