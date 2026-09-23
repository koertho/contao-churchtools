<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Tests\Sync;

use Koertho\ChurchToolsBundle\Api\ChurchToolsClient;
use Koertho\ChurchToolsBundle\Api\Transport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Synthetic responses only. No stored remote payloads or personal metadata. */
final class RemoteFixture
{
    public array $rows = [];
    public array $calendars = [['id' => 110, 'name' => 'Synthetic']];
    public string $failure = '';
    public int $requests = 0;
    public ?\Closure $onRequest = null;

    public function client(): ChurchToolsClient
    {
        return new ChurchToolsClient(new Transport(new MockHttpClient(function ($method, $url) {
            ++$this->requests;
            ($this->onRequest ?? static fn () => null)();
            $calendar = parse_url($url, PHP_URL_PATH) === '/api/calendars';
            if (!$calendar && in_array($this->failure, ['401', '403', '500'], true)) {
                return new MockResponse('private-body', ['http_code' => (int) $this->failure]);
            }
            if (!$calendar && $this->failure === 'malformed') {
                return new MockResponse('{private-body');
            }
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            $rows = $calendar ? $this->calendars : array_values(array_filter($this->rows, static function ($row) use ($query) {
                $base = $row['appointment']['base'];
                $date = $row['appointment']['calculated'];

                return in_array((string) $base['calendar']['id'], $query['calendar_ids'], true)
                    && substr($date['endDate'], 0, 10) >= $query['from'] && substr($date['startDate'], 0, 10) <= $query['to'];
            }));
            if (($calendar && $this->failure === 'missing') || (!$calendar && $this->failure === 'empty')) {
                $rows = [];
            }
            if ($calendar && $this->failure === 'lost-after-fetch' && $this->requests > 1) {
                $rows = [];
            }
            if ($calendar && $this->failure === 'duplicate-calendar') {
                $rows = array_merge($rows, $rows);
            }
            if (!$calendar && $this->failure === 'split-mismatch' && $query['from'] !== '2026-08-17') {
                $rows = [];
            }
            $body = ['data' => $rows, 'meta' => ['count' => count($rows)]];
            if (!$calendar && $this->failure === 'partial') {
                ++$body['meta']['count'];
            }
            if (!$calendar && $this->failure === 'pagination') {
                $body['meta']['total'] = 100;
            }
            if (!$calendar && $this->failure === 'next') {
                $body['links'] = ['next' => 'https://example.org/private'];
            }

            return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR));
        }), 'https://example.org'), 'synthetic');
    }

    public static function row(string $uid = 'synthetic', string $start = '2026-09-20T08:00:00Z', string $end = '2026-09-20T09:00:00Z', bool $allDay = false): array
    {
        return ['appointment' => ['base' => ['id' => 1, 'calendar' => ['id' => 110, 'name' => 'Synthetic'],
            'title' => 'Synthetic', 'description' => "Plain <b>text</b>\n**literal**", 'allDay' => $allDay, 'isInternal' => false,
            'onBehalfOfPid' => 'PRIVATE_SENTINEL', 'signup' => 'PRIVATE_SENTINEL'],
            'calculated' => ['iCalUid' => $uid, 'startDate' => $start, 'endDate' => $end]], 'tags' => [], 'contaoEventId' => 42];
    }

    public static function saved(string $name): array
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__, 2).'/.docs/church_tools/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_map(static function ($source) {
            $row = self::row();
            $row['appointment']['base']['id'] = $source['appointmentId'];
            $row['appointment']['base']['calendar']['id'] = $source['calendarId'];
            $row['appointment']['base']['exceptions'] = $source['exceptions'] ?? [];
            $row['appointment']['calculated'] = $source['calculated'];

            return $row;
        }, $fixture['occurrences']);
    }
}
