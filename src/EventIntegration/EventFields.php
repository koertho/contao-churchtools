<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventIntegration;

/** Explicit conversion to the core's second-resolution, local-date representation. */
final class EventFields
{
    public static function dates(array $entry, \DateTimeZone $timezone): array
    {
        $start = (new \DateTimeImmutable($entry['sourceStart'], $timezone))->setTimezone($timezone);
        $end = (new \DateTimeImmutable($entry['sourceEnd'], $timezone))->setTimezone($timezone);
        if (!$entry['allDay'] && ($start->format('u') !== '000000' || $end->format('u') !== '000000' || $start == $end)) {
            // Core cannot represent fractions, and equal timed endpoints mean open-ended.
            throw new \DomainException('dates_not_representable');
        }
        $fields = [
            'addTime' => $entry['allDay'] ? '' : '1',
            'startDate' => $start->setTime(0, 0)->getTimestamp(),
            'endDate' => $end->setTime(0, 0)->getTimestamp(),
            'startTime' => $start->getTimestamp(),
            'endTime' => $entry['allDay'] ? $end->modify('+1 day')->setTime(0, 0)->getTimestamp() - 1 : $end->getTimestamp(),
        ];
        foreach (['startDate', 'endDate', 'startTime', 'endTime'] as $field) {
            if ($fields[$field] < 0 || $fields[$field] > 4294967295) {
                throw new \DomainException('dates_not_representable');
            }
        }

        return $fields;
    }

    public static function richText(string $text): string
    {
        if ($text === '') return '';
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Source text must not become Contao insert tags either.
        $text = str_replace(['{', '}'], ['&#123;', '&#125;'], $text);
        return '<p>'.str_replace("\n", '<br>', str_replace(["\r\n", "\r"], "\n", $text)).'</p>';
    }
}
