<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Calendar\Sync;

use Contao\CoreBundle\ContaoCoreBundle;

/** Maps allowlisted source data without touching editorial fields. */
final class CalendarEventMapper
{
    public function map(array $source, \DateTimeZone $timezone): array
    {
        $start = (new \DateTimeImmutable($source['sourceStart'], $timezone))->setTimezone($timezone);
        $end = (new \DateTimeImmutable($source['sourceEnd'], $timezone))->setTimezone($timezone);
        if (!$source['allDay'] && ($start->format('u') !== '000000' || $end->format('u') !== '000000' || $start == $end)) {
            throw new \DomainException('Source dates cannot be represented by native events.');
        }
        $title = $source['title'];
        if (version_compare(ContaoCoreBundle::getVersion(), '6.0', '<')) {
            $title = str_replace(['{', '}'], ['&#123;', '&#125;'], htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        } elseif (str_contains($title, '{{')) {
            throw new \DomainException('Source title cannot be represented literally by native event lists.');
        }
        if (trim($source['title']) === '' || mb_strlen($title) > 255) {
            throw new \DomainException('Source title cannot be represented by native events.');
        }
        $fields = [
            'title' => $title,
            'addTime' => $source['allDay'] ? '' : '1',
            'startDate' => $start->setTime(0, 0)->getTimestamp(),
            'endDate' => $end->setTime(0, 0)->getTimestamp(),
            'startTime' => $start->getTimestamp(),
            'endTime' => $source['allDay'] ? $end->modify('+1 day')->setTime(0, 0)->getTimestamp() - 1 : $end->getTimestamp(),
            'location' => $source['location'] ?? '',
            'address' => $source['address'] ?? '',
            'source' => 'default',
            'recurring' => '',
            'ct_sync_key' => hash('sha256', $source['occurrenceUid']),
            'ct_calendar_id' => $source['sourceCalendarId'],
            'ct_appointment_id' => $source['sourceAppointmentId'],
            'ct_description' => $source['description'],
        ];
        foreach (['startDate', 'endDate', 'startTime', 'endTime'] as $key) {
            if ($fields[$key] < 0 || $fields[$key] > 4294967295) {
                throw new \DomainException('Source dates exceed the supported native event range.');
            }
        }
        foreach (['location', 'address'] as $key) {
            if (version_compare(ContaoCoreBundle::getVersion(), '6.0', '<')) {
                $fields[$key] = str_replace(['{', '}'], ['&#123;', '&#125;'], htmlspecialchars($fields[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            }
            if (mb_strlen($fields[$key]) > 255) {
                throw new \DomainException('Source location exceeds native event limits.');
            }
        }
        $fields['ct_hash'] = hash('sha256', json_encode($fields + ['repeatId' => $source['repeatId'] ?? 0], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $fields['_repeatId'] = $source['repeatId'] ?? 0;
        $fields['_localDate'] = $start->format('Y-m-d');
        $fields['_sourceTitle'] = $source['title'];

        return $fields;
    }
}
