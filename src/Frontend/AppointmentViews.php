<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Frontend;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Presentation of already resolved occurrences; never makes visibility or UID decisions. */
final class AppointmentViews
{
    public function __construct(private readonly TranslatorInterface $translator) {}

    public function label(string $key, string $locale): string
    {
        return $this->translator->trans($key, [], 'church_tools_frontend', $locale);
    }

    public function listGroups(array $rows, \DateTimeImmutable $from, string $locale): array
    {
        $groups = [];
        foreach ($rows as $row) {
            // An occurrence spanning the first day is listed once, at its first visible day.
            $day = $row['start'] < $from ? $from : $row['start'];
            $key = $day->format('Y-m-d');
            $groups[$key] ??= ['date' => $this->localized($day, $locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE), 'items' => []];
            $groups[$key]['items'][] = $this->item($row, $locale);
        }
        return array_values($groups);
    }

    public function calendar(array $rows, \DateTimeImmutable $currentMonth, \DateTimeImmutable $requestedMonth, string $locale, Request $request, string $key): array
    {
        $firstMonth = $lastMonth = $currentMonth;
        $zone = $currentMonth->getTimezone();
        $minimum = new \DateTimeImmutable('1000-01-01 00:00:00', $zone);
        $maximum = new \DateTimeImmutable('9999-12-01 00:00:00', $zone);
        foreach ($rows as $row) {
            $start = $row['start']->setTimezone($zone)->modify('first day of this month')->setTime(0, 0);
            $end = $row['end']->setTimezone($zone)->modify('first day of this month')->setTime(0, 0);
            // Offset conversion at the four-digit boundary can cross into year 999 or 10000.
            if ($start < $minimum) $start = $minimum;
            if ($end > $maximum) $end = $maximum;
            if ($start < $firstMonth) $firstMonth = $start;
            if ($end > $lastMonth) $lastMonth = $end;
        }
        $month = $requestedMonth < $firstMonth ? $firstMonth : ($requestedMonth > $lastMonth ? $lastMonth : $requestedMonth);
        $next = $month->modify('+1 month');
        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['start'] < $next && $row['end'] >= $month));
        $days = [];
        for ($day = $month; $day < $next; $day = $day->modify('+1 day')) {
            $days[$day->format('Y-m-d')] = [
                'iso' => $day->format('Y-m-d'),
                'number' => (int) $day->format('j'),
                'date' => $this->localized($day, $locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE),
                'items' => [],
            ];
        }
        foreach ($rows as $row) {
            $first = $row['start'] < $month ? $month : $row['start']->setTime(0, 0);
            $last = $row['end'] >= $next ? $next->modify('-1 day') : $row['end']->setTime(0, 0);
            $item = $this->item($row, $locale);
            for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
                $days[$day->format('Y-m-d')]['items'][] = $item;
            }
        }
        $offset = (int) $month->format('N') - 1;
        $cells = array_merge(array_fill(0, $offset, null), array_values($days));
        while (count($cells) % 7 !== 0) $cells[] = null;
        $weekdays = [];
        for ($i = 0; $i < 7; ++$i) $weekdays[] = $this->format($month->modify('monday this week')->modify('+'.$i.' days'), $locale, 'EEEE');
        $previousMonth = $month->modify('-1 month');
        $hasPrevious = $month > $firstMonth;
        $hasNext = $month < $lastMonth;
        return [
            'heading' => $this->format($month, $locale, 'LLLL y'),
            'weeks' => array_chunk($cells, 7),
            'weekdays' => $weekdays,
            'hasItems' => (bool) $rows,
            'previous' => $hasPrevious ? $this->navigation($request, $key, $previousMonth) : null,
            'next' => $hasNext ? $this->navigation($request, $key, $next) : null,
            'previousLabel' => $hasPrevious ? $this->label('previous', $locale).' '.$this->format($previousMonth, $locale, 'LLLL y') : '',
            'nextLabel' => $hasNext ? $this->label('next', $locale).' '.$this->format($next, $locale, 'LLLL y') : '',
        ];
    }

    private function item(array $row, string $locale): array
    {
        $start = $row['start'];
        $end = $row['end'];
        $sameDay = $start->format('Y-m-d') === $end->format('Y-m-d');
        if ($row['allDay']) {
            $when = $this->label('all_day', $locale);
            if (!$sameDay) $when .= ' · '.$this->localized($start, $locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE).' – '.$this->localized($end, $locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE);
        } elseif ($sameDay) {
            $when = $this->localized($start, $locale, \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT);
            if ($start != $end) $when .= '–'.$this->localized($end, $locale, \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT);
        } else {
            $when = $this->localized($start, $locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT).' – '.$this->localized($end, $locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT);
        }
        $url = $row['url'];
        $urlParts = is_string($url) ? parse_url($url) : false;
        if (!is_string($url) || $url === '' || $urlParts === false || preg_match('/[\\x00-\\x20\\x7f\\\\]/', $url)
            || str_starts_with($url, '//')
            || (isset($urlParts['scheme']) && (!in_array(strtolower($urlParts['scheme']), ['http', 'https'], true) || empty($urlParts['host'])))) {
            $url = null;
        }
        return [
            'title' => $row['title'], 'when' => $when, 'description' => $row['description'],
            'descriptionIsHtml' => $row['descriptionIsHtml'], 'url' => $url,
        ];
    }

    private function navigation(Request $request, string $key, \DateTimeImmutable $month): string
    {
        $params = $request->query->all();
        $params[$key] = $month->format('Y-m');
        return $request->getPathInfo().'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function format(\DateTimeImmutable $date, string $locale, string $pattern): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $date->getTimezone(), \IntlDateFormatter::GREGORIAN, $pattern);
        return $formatter->format($date) ?: $date->format('Y-m-d H:i');
    }

    private function localized(\DateTimeImmutable $date, string $locale, int $dateStyle, int $timeStyle): string
    {
        $formatter = new \IntlDateFormatter($locale, $dateStyle, $timeStyle, $date->getTimezone());
        return $formatter->format($date) ?: $date->format('Y-m-d H:i');
    }
}
