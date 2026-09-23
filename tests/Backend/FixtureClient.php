<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Tests\Backend;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FixtureClient
{
    public static function create(): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url): MockResponse {
            if ($method !== 'GET' || !str_ends_with($url, '/api/calendars')) {
                throw new \RuntimeException('Backend must only discover calendars.');
            }
            $state = trim(file_get_contents(getenv('CHURCHTOOLS_BACKEND_STATE')));
            if ($state === 'offline') {
                return new MockResponse('{}', ['http_code' => 503]);
            }
            $rows = [['id' => 2, 'name' => '<script>calendar-probe</script>']];
            if ($state !== 'missing') {
                $rows[] = ['id' => 77, 'name' => 'Saved calendar'];
            }

            return new MockResponse(json_encode(['data' => $rows, 'meta' => ['count' => count($rows)]], JSON_THROW_ON_ERROR));
        });
    }
}
