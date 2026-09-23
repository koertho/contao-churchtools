<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Api;

final class ChurchToolsClient
{
    public function __construct(private readonly Transport $transport, #[\SensitiveParameter] private readonly string $token)
    {
    }

    /** Transient API data; persistence/publication mapping belongs to a later step. */
    public function calendars(): array
    {
        $rows = $this->getList('/api/calendars');
        foreach ($rows as $row) {
            if (!is_array($row) || !is_int($row['id'] ?? null) || $row['id'] < 1 || !is_string($row['name'] ?? null)) {
                throw new ApiException('Invalid ChurchTools calendar.');
            }
        }

        return $rows;
    }

    public function appointments(array $calendarIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($calendarIds === [] || $from->format('Y-m-d') >= $to->format('Y-m-d')) {
            throw new ApiException('Select calendars and an increasing date window.');
        }
        foreach ($calendarIds as $id) {
            if (!is_int($id) || $id < 1) {
                throw new ApiException('Calendar IDs must be positive integers.');
            }
        }
        $rows = $this->getList('/api/calendars/appointments', [
            'calendar_ids' => array_values(array_unique($calendarIds)), 'include' => ['tags'],
            'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'),
        ]);
        $seen = [];
        foreach ($rows as $row) {
            $base = $row['appointment']['base'] ?? null;
            $calculated = $row['appointment']['calculated'] ?? null;
            if (!is_array($base) || !is_array($calculated) || !is_int($base['id'] ?? null)
                || !is_int($base['calendar']['id'] ?? null) || !in_array($base['calendar']['id'], $calendarIds, true)
                || !is_string($base['title'] ?? null) || !is_bool($base['allDay'] ?? null)
                || !is_bool($base['isInternal'] ?? null) || !is_string($calculated['iCalUid'] ?? null)
                || trim($calculated['iCalUid']) === '' || !is_string($calculated['startDate'] ?? null)
                || !is_string($calculated['endDate'] ?? null) || !is_array($row['tags'] ?? null)) {
                throw new ApiException('Invalid ChurchTools appointment.');
            }
            foreach (['startDate', 'endDate'] as $field) {
                $value = $calculated[$field];
                $pattern = $base['allDay'] ? '/^\d{4}-\d{2}-\d{2}$/' : '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/';
                if (!preg_match($pattern, $value)) {
                    throw new ApiException('Invalid ChurchTools occurrence date.');
                }
                try {
                    new \DateTimeImmutable($value);
                    $errors = \DateTimeImmutable::getLastErrors();
                    if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
                        throw new ApiException('Invalid ChurchTools occurrence date.');
                    }
                } catch (\DateMalformedStringException) {
                    throw new ApiException('Invalid ChurchTools occurrence date.');
                }
            }
            if (new \DateTimeImmutable($calculated['endDate']) < new \DateTimeImmutable($calculated['startDate'])) {
                throw new ApiException('Invalid ChurchTools occurrence interval.');
            }
            $uid = $calculated['iCalUid'];
            if (isset($seen[$uid]) && $seen[$uid] !== $row) {
                throw new ApiException('Conflicting ChurchTools occurrence identity.');
            }
            $seen[$uid] = $row;
        }

        return array_values($seen);
    }

    private function getList(string $path, array $query = []): array
    {
        if ($this->token === '' || preg_match('/[\x00-\x20\x7f]/', $this->token)) {
            throw new ApiException('Configure a valid ChurchTools login token.');
        }
        [$body, $headers] = $this->transport->request('GET', $path, [
            'headers' => ['Authorization' => 'Login '.$this->token, 'Accept' => 'application/json'], 'query' => $query,
        ]);
        if (!is_array($body['data']) || !array_is_list($body['data'])) {
            throw new ApiException('Invalid ChurchTools list response.');
        }
        if (array_key_exists('meta', $body) && !is_array($body['meta'])) {
            throw new ApiException('Invalid ChurchTools response metadata.');
        }
        if (array_key_exists('count', $body['meta'] ?? []) && (!is_int($body['meta']['count']) || $body['meta']['count'] !== count($body['data']))) {
            throw new ApiException('Incomplete ChurchTools list response.');
        }
        if (array_diff(array_keys($body), ['data', 'meta']) || array_diff(array_keys($body['meta'] ?? []), ['count'])
            || isset($headers['link']) || isset($headers['content-range'])) {
            throw new ApiException('Unsupported paginated ChurchTools response.');
        }

        return $body['data'];
    }
}
