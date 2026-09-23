<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Tests;

use Koertho\ChurchToolsBundle\Api\ApiException;
use Koertho\ChurchToolsBundle\Api\ChurchToolsClient;
use Koertho\ChurchToolsBundle\Api\Transport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClientTest extends TestCase
{
    public function testAuthenticationAndCalendarResponse(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('GET', $method);
            self::assertSame('https://example.org/api/calendars', $url);
            self::assertContains('Authorization: Login test-secret', $options['headers']);
            self::assertSame(0, $options['max_redirects']);
            self::assertArrayNotHasKey('cookie', $options['normalized_headers']);

            return new MockResponse('{"data":[{"id":2,"name":"Calendar"}]}');
        });
        self::assertSame([['id' => 2, 'name' => 'Calendar']], (new ChurchToolsClient(new Transport($http, 'https://example.org'), 'test-secret'))->calendars());
    }

    public static function failures(): iterable
    {
        foreach ([401, 403, 429, 500, 302] as $status) {
            yield 'HTTP '.$status => ['private-body test-secret', ['http_code' => $status]];
        }
        yield 'JSON' => ['private-body test-secret', []];
        yield 'envelope' => ['{"private":"test-secret"}', []];
        yield 'list' => ['{"data":{"foo":"test-secret"}}', []];
        yield 'row' => ['{"data":[{"id":"test-secret"}]}', []];
        yield 'partial' => ['{"data":[],"meta":{"count":3}}', []];
        yield 'pagination' => ['{"data":[],"meta":{"pagination":{}}}', []];
        yield 'timeout' => [(static function () { yield ''; })(), []];
    }

    #[DataProvider('failures')]
    public function testSafeFailures(string|\Generator $body, array $info): void
    {
        $http = new MockHttpClient(new MockResponse($body, $info));
        try {
            (new ChurchToolsClient(new Transport($http, 'https://example.org'), 'test-secret'))->calendars();
            self::fail('Expected failure');
        } catch (ApiException $e) {
            self::assertStringNotContainsString('test-secret', (string) $e);
            self::assertStringNotContainsString('private-body', (string) $e);
            self::assertNull($e->getPrevious());
        }
        self::assertSame(1, $http->getRequestsCount(), 'Never retry with credentials.');
    }

    public function testMissingTokenMakesNoRequest(): void
    {
        $http = new MockHttpClient();
        try {
            (new ChurchToolsClient(new Transport($http, 'https://example.org'), ''))->calendars();
            self::fail('Expected missing-token failure');
        } catch (ApiException) {
            self::assertSame(0, $http->getRequestsCount());
        }
    }

    public static function unsafeOrigins(): iterable
    {
        foreach (['http://example.org', 'https://secret@example.org', 'https://example.org/private', 'https://example.org?token=x', 'https://example.org/#x', ''] as $url) {
            yield [$url];
        }
    }

    #[DataProvider('unsafeOrigins')]
    public function testOriginValidation(string $url): void
    {
        $this->expectException(ApiException::class);
        (new ChurchToolsClient(new Transport(new MockHttpClient(), $url), 'test-secret'))->calendars();
    }

    public function testAppointmentQueryAndDuplicateIdentity(): void
    {
        $row = self::occurrence();
        $http = new MockHttpClient(function ($method, $url) use ($row) {
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame(['2'], $query['calendar_ids']);
            self::assertSame(['tags'], $query['include']);
            self::assertSame('2026-09-01', $query['from']);
            self::assertSame('2026-10-01', $query['to']);

            return new MockResponse(json_encode(['data' => [$row, $row], 'meta' => ['count' => 2]], JSON_THROW_ON_ERROR));
        });
        self::assertSame([$row], (new ChurchToolsClient(new Transport($http, 'https://example.org'), 'secret'))->appointments([2], new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-10-01')));
    }

    public static function invalidOccurrences(): iterable
    {
        $row = self::occurrence();
        $changed = $row;
        $changed['appointment']['base']['title'] = 'Changed';
        yield 'conflicting UID' => [[$row, $changed]];
        $changed = $row;
        $changed['appointment']['calculated']['iCalUid'] = '';
        yield 'missing UID' => [[$changed]];
        $changed = $row;
        $changed['appointment']['calculated']['startDate'] = 'invalid';
        yield 'invalid date' => [[$changed]];
        $changed = $row;
        $changed['appointment']['base']['calendar']['id'] = 99;
        yield 'unexpected calendar' => [[$changed]];
        yield 'legacy fields only' => [[['base' => $row['appointment']['base'], 'calculated' => $row['appointment']['calculated']]]];
    }

    #[DataProvider('invalidOccurrences')]
    public function testInvalidOccurrences(array $rows): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(['data' => $rows], JSON_THROW_ON_ERROR)));
        $this->expectException(ApiException::class);
        (new ChurchToolsClient(new Transport($http, 'https://example.org'), 'secret'))->appointments([2], new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-10-01'));
    }

    private static function occurrence(): array
    {
        return ['appointment' => [
            'base' => ['id' => 1, 'calendar' => ['id' => 2], 'title' => 'Synthetic', 'allDay' => false, 'isInternal' => false],
            'calculated' => ['iCalUid' => 'synthetic@example.org', 'startDate' => '2026-09-03T08:00:00Z', 'endDate' => '2026-09-03T09:00:00Z'],
        ], 'tags' => []];
    }
}
