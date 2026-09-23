<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Storage;

/** Allowlisted persistence boundary, independent of HTTP and snapshot completeness. */
final class SourceOccurrence
{
    /** @return array<string, int|string|bool|null> */
    public static function fields(array $row): array
    {
        $base = $row['appointment']['base'] ?? null;
        $calculated = $row['appointment']['calculated'] ?? null;
        if (!is_array($base) || !is_array($calculated) || ($base['isInternal'] ?? null) !== false) {
            throw new \InvalidArgumentException('Only explicitly public occurrences may be stored.');
        }
        $uid = $calculated['iCalUid'] ?? null;
        if (!is_string($uid) || trim($uid) === '' || strlen($uid) > 2048) {
            throw new \InvalidArgumentException('Occurrence UID must contain 1–2048 bytes; it is never truncated.');
        }
        foreach ([$base['id'] ?? null, $base['calendar']['id'] ?? null] as $id) {
            if (!is_int($id) || $id < 1 || $id > 4294967295) {
                throw new \InvalidArgumentException('Source IDs must be positive unsigned integers.');
            }
        }
        if (!is_string($base['title'] ?? null) || !is_string($base['calendar']['name'] ?? null)
            || !is_string($base['description'] ?? '') || !is_bool($base['allDay'] ?? null)
            || !is_array($row['tags'] ?? null) || !array_is_list($row['tags'])) {
            throw new \InvalidArgumentException('Invalid source text, all-day flag or tags.');
        }
        $tags = [];
        foreach ($row['tags'] as $tag) {
            if (!is_array($tag) || !is_int($tag['id'] ?? null) || $tag['id'] < 1
                || !is_string($tag['name'] ?? null) || !array_key_exists('description', $tag)
                || ($tag['description'] !== null && !is_string($tag['description'])) || !is_string($tag['color'] ?? null)) {
                throw new \InvalidArgumentException('Invalid source tag.');
            }
            $tags[] = ['id' => $tag['id'], 'name' => $tag['name'], 'description' => $tag['description'], 'color' => $tag['color']];
        }
        $dates = [];
        foreach (['startDate', 'endDate'] as $key) {
            $value = $calculated[$key] ?? null;
            $pattern = $base['allDay'] ? '/^\d{4}-\d{2}-\d{2}$/D' : '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D';
            if (!is_string($value) || !preg_match($pattern, $value)) {
                throw new \InvalidArgumentException('Invalid occurrence date representation.');
            }
            try {
                $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            } catch (\Exception) {
                throw new \InvalidArgumentException('Invalid occurrence date.');
            }
            if (\DateTimeImmutable::getLastErrors() !== false || (int) $date->format('Y') < 1000) {
                throw new \InvalidArgumentException('Invalid occurrence date.');
            }
            $dates[$key] = $date;
        }
        if ($dates['endDate'] < $dates['startDate']) {
            throw new \InvalidArgumentException('Occurrence end precedes its start.');
        }

        $encodedTags = json_encode($tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ([$base['title'], $base['calendar']['name'], $base['description'] ?? '', $encodedTags] as $text) {
            if (!preg_match('//u', $text) || strlen($text) > 16777215) {
                throw new \InvalidArgumentException('Source text exceeds the supported UTF-8 storage limit.');
            }
        }

        return [
            'occurrenceUid' => $uid,
            'sourceAppointmentId' => $base['id'],
            'sourceCalendarId' => $base['calendar']['id'],
            'calendarName' => $base['calendar']['name'],
            'title' => $base['title'],
            'description' => $base['description'] ?? '',
            'allDay' => $base['allDay'],
            'sourceStart' => $calculated['startDate'],
            'sourceEnd' => $calculated['endDate'],
            'startTimestamp' => $base['allDay'] ? null : $dates['startDate']->getTimestamp(),
            'endTimestamp' => $base['allDay'] ? null : $dates['endDate']->getTimestamp(),
            'startDate' => $base['allDay'] ? $calculated['startDate'] : null,
            'endDate' => $base['allDay'] ? $calculated['endDate'] : null,
            'tags' => $encodedTags,
        ];
    }
}
