<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Tests\Storage;

use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\Model;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Koertho\ChurchToolsBundle\Model\ChurchToolsArchiveModel as Archive;
use Koertho\ChurchToolsBundle\Model\ChurchToolsEntryModel as Entry;

/** Executed by tools/storage-test.php against a real isolated Contao database. */
final class StorageAcceptance
{
    public static function run(Connection $db, callable $assertSchema): int
    {
        $count = 0;
        $check = static function (bool $condition, string $message) use (&$count): void {
            ++$count;
            if (!$condition) {
                throw new \RuntimeException($message);
            }
        };
        $check(Model::getClassFromTable('tl_church_tools_archive') === Archive::class, 'Archive registration');
        $check(Model::getClassFromTable('tl_church_tools_entry') === Entry::class, 'Entry registration');
        $archiveDca = $GLOBALS['TL_DCA']['tl_church_tools_archive'];
        $entryDca = $GLOBALS['TL_DCA']['tl_church_tools_entry'];
        $check($archiveDca['config']['ctable'] === ['tl_church_tools_entry'] && $entryDca['config']['ptable'] === 'tl_church_tools_archive', 'Parent/child DCA');
        $check($archiveDca['list']['operations']['entries']['href'] === 'table=tl_church_tools_entry', 'Child navigation');
        $check($entryDca['config']['notEditable'] && $entryDca['config']['closed'] && array_keys($entryDca['list']['operations']) === ['show'], 'Read-only entry DCA');
        foreach ($entryDca['fields'] as $field) {
            $check(!isset($field['inputType']), 'No source field widget');
        }
        $a = new Archive();
        $a->name = 'Controlled archive A';
        $a->setCalendarIds([110, 2, 110]);
        $a->save();
        $b = new Archive();
        $b->name = 'Controlled archive B';
        $b->setCalendarIds([]);
        $b->save();
        $check(json_decode($db->fetchOne('SELECT calendarIds FROM tl_church_tools_archive WHERE id=?', [$a->id]), true, flags: JSON_THROW_ON_ERROR) === [110, 2], 'Calendar IDs roundtrip');
        $check($db->fetchOne('SELECT calendarIds FROM tl_church_tools_archive WHERE id=?', [$b->id]) === '[]', 'Empty calendar IDs');
        $check(Archive::findByPk($a->id)->name === $a->name, 'Archive model roundtrip');
        $check((int) $a->lastSuccessfulSync === 0 && (int) $a->removedLinkedEntries === 0 && $a->lastError === null, 'Initial archive state');
        $calendar = new CalendarModel();
        $calendar->title = 'Controlled target calendar';
        $calendar->save();
        $event = new CalendarEventsModel();
        $event->pid = $calendar->id;
        $event->title = 'Independent Contao event';
        $event->save();
        $eventBefore = $db->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$event->id]);
        $fixture = json_decode(file_get_contents(dirname(__DIR__, 2).'/.docs/church_tools/test-metadata.json'), true, flags: JSON_THROW_ON_ERROR);
        $description = json_decode(file_get_contents(dirname(__DIR__, 2).'/.docs/church_tools/description-observation.json'), true, flags: JSON_THROW_ON_ERROR)['value'];
        $rows = [];
        foreach ($fixture['occurrences'] as $source) {
            $row = self::row($source, $description);
            $rows[] = $row;
            $stored = Entry::saveSource($a->id, $row);
            $data = $db->fetchAssociative('SELECT * FROM tl_church_tools_entry WHERE id=?', [$stored->id]);
            $check($data['description'] === $description && $data['title'] === $source['title'], 'Plaintext preserved');
            $check(json_decode($data['tags'], true, flags: JSON_THROW_ON_ERROR) === $source['tags'], 'Tags allowlist roundtrip');
            $check($data['contaoEventId'] === null, 'Local link cannot be imported');
            $check(!str_contains(json_encode($data), 'DO_NOT_STORE'), 'Private remote fields excluded');
            $check($data['sourceStart'] === $source['calculated']['startDate'] && $data['sourceEnd'] === $source['calculated']['endDate'], 'Source dates preserved');
            $check($source['allDay'] ? $data['startTimestamp'] === null && $data['endDate'] === '2026-09-25' : $data['startDate'] === null && (int) $data['startTimestamp'] === strtotime($source['calculated']['startDate']), 'Instant/calendar date distinction');
        }
        $row = $rows[0];
        $entry = Entry::saveSource($a->id, $row);
        $id = $entry->id;
        $entry->contaoEventId = $event->id;
        $entry->save();
        $other = Entry::saveSource($b->id, $row);
        $other->contaoEventId = 4294967294;
        $other->save();
        $check($other->id !== $id && $other->contaoEventId !== $entry->contaoEventId, 'Independent overlapping archives');
        $row['appointment']['base']['id'] = 900;
        $row['appointment']['base']['calendar']['id'] = 2;
        $row['appointment']['calculated']['startDate'] = '2026-09-28T10:00:00+02:00';
        $row['appointment']['calculated']['endDate'] = '2026-09-28T11:00:00+02:00';
        $moved = Entry::saveSource($a->id, $row);
        $check($moved->id === $id && $moved->contaoEventId === $event->id && $moved->sourceAppointmentId === 900 && $moved->sourceCalendarId === 2, 'Move retains row and link');
        $check(Entry::saveSource($a->id, $row)->id === $id, 'Repeated save');
        $check(Entry::saveSource($b->id, $row)->contaoEventId === 4294967294, 'Unresolvable target retained');
        $check($db->fetchOne('SELECT COUNT(*) FROM tl_church_tools_entry WHERE pid=? AND occurrenceUid=?', [$a->id, $moved->occurrenceUid]) == 1, 'One row per identity');
        $duplicate = $db->fetchAssociative('SELECT * FROM tl_church_tools_entry WHERE id=?', [$id]);
        unset($duplicate['id']);
        try {
            $db->insert('tl_church_tools_entry', $duplicate);
            $check(false, 'Database must reject duplicate');
        } catch (UniqueConstraintViolationException) {
            $check(true, 'Database rejects duplicate');
        }
        $check($moved->getRelated('pid')->id === $a->id, 'Model parent relation');
        $check(Entry::findByPid($b->id)->count() === 1, 'Archive query');
        $before = $db->fetchAllAssociative('SELECT * FROM tl_church_tools_entry ORDER BY id');
        $assertSchema();
        $check($before === $db->fetchAllAssociative('SELECT * FROM tl_church_tools_entry ORDER BY id'), 'Second schema comparison preserves rows');
        $row['appointment']['base']['isInternal'] = true;
        try {
            Entry::saveSource($a->id, $row);
            $check(false, 'Internal occurrence must be refused');
        } catch (\InvalidArgumentException) {
            $check($db->fetchOne('SELECT contaoEventId FROM tl_church_tools_entry WHERE id=?', [$id]) == $event->id, 'Internal visibility neither overwrites nor deletes here');
        }
        $row['appointment']['base']['isInternal'] = false;
        $moved->delete();
        $check($db->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$event->id]) === $eventBefore, 'Deleting local row preserves core event');
        $check(Entry::saveSource($a->id, $row)->contaoEventId === null, 'Recreated UID does not recover old link');
        $row['appointment']['calculated']['iCalUid'] .= '-new';
        $check(Entry::saveSource($a->id, $row)->contaoEventId === null, 'New UID does not match by title/date');
        // Full key, no prefix collisions, case folding or trailing-space folding.
        $keyIds = [];
        foreach ([str_repeat('x', 2047).'a', str_repeat('x', 2047).'b', 'Case', 'case', 'case '] as $uid) {
            $row['appointment']['calculated']['iCalUid'] = $uid;
            $key = Entry::saveSource($a->id, $row);
            $keyIds[] = $key->id;
            $check($db->fetchOne('SELECT occurrenceUid FROM tl_church_tools_entry WHERE id=?', [$key->id]) === $uid, 'Full byte-exact UID roundtrip');
        }
        $check(count(array_unique($keyIds)) === 5, 'UID identities stay distinct');
        $row['appointment']['calculated']['iCalUid'] = str_repeat('x', 2049);
        try {
            Entry::saveSource($a->id, $row);
            $check(false, 'Oversize UID must fail without truncation');
        } catch (\InvalidArgumentException) {
            $check(true, 'Oversize UID refused');
        }
        foreach ([
            [false, '2026-03-29T01:30:00+01:00', '2026-03-29T03:30:00+02:00', 3600],
            [false, '2026-10-25T02:30:00+02:00', '2026-10-25T02:30:00+01:00', 3600],
            [false, '2026-09-26T08:00:00.123456Z', '2026-09-27T09:00:00.654321Z', 90000],
            [true, '2026-03-29', '2026-03-29', null],
            [true, '2026-03-28', '2026-03-30', null],
        ] as $i => [$allDay, $start, $end, $seconds]) {
            $row['appointment']['base']['allDay'] = $allDay;
            $row['appointment']['calculated'] = ['iCalUid' => 'date-'.$i, 'startDate' => $start, 'endDate' => $end];
            $dateEntry = Entry::saveSource($a->id, $row);
            $data = $db->fetchAssociative('SELECT * FROM tl_church_tools_entry WHERE id=?', [$dateEntry->id]);
            $check($data['sourceStart'] === $start && $data['sourceEnd'] === $end, 'DST/source offset/fractions preserved');
            $check($allDay ? $data['endDate'] === $end && $data['startTimestamp'] === null : (int) $data['endTimestamp'] - (int) $data['startTimestamp'] === $seconds, 'DST calendar/instant semantics');
        }
        $linked = Entry::saveSource($a->id, $rows[0]);
        $linked->contaoEventId = $event->id;
        $linked->save();
        $deletedEventId = $event->id;
        $event->delete();
        $check(Entry::saveSource($a->id, $rows[0])->contaoEventId === $deletedEventId, 'Deleted actual target ID survives source updates');
        $check($db->fetchOne('SELECT contaoEventId FROM tl_church_tools_entry WHERE id=?', [$linked->id]) == $deletedEventId, 'No SET NULL foreign key');
        foreach ([[0], ['110'], [-1], [1 => 110]] as $invalidIds) {
            try {
                $a->setCalendarIds($invalidIds);
                $check(false, 'Invalid calendar selection must fail');
            } catch (\InvalidArgumentException) {
                $check(json_decode($a->calendarIds, true) === [110, 2], 'Invalid selection preserves previous IDs');
            }
        }
        $check($db->fetchOne('SELECT contaoEventId FROM tl_church_tools_entry WHERE id=?', [$other->id]) == 4294967294, 'Other missing link still retained');
        $check($db->fetchOne('SELECT COUNT(*) FROM tl_church_tools_archive') >= 2, 'Archives remain');

        return $count;
    }

    private static function row(array $source, string $description): array
    {
        $tags = $source['tags'];
        foreach ($tags as &$tag) {
            $tag['count'] = 123;
            $tag['meta'] = 'DO_NOT_STORE';
        }
        unset($tag);

        return [
            'appointment' => ['base' => [
                'id' => $source['appointmentId'], 'calendar' => ['id' => $source['calendarId'], 'name' => 'Controlled calendar', 'meta' => 'DO_NOT_STORE'],
                'title' => $source['title'], 'description' => $description, 'allDay' => $source['allDay'], 'isInternal' => false,
                'meta' => 'DO_NOT_STORE', 'onBehalfOfPid' => 'DO_NOT_STORE', 'signup' => 'DO_NOT_STORE', 'image' => 'DO_NOT_STORE',
            ], 'calculated' => $source['calculated']],
            'tags' => $tags, 'bookings' => 'DO_NOT_STORE', 'meetingRequests' => 'DO_NOT_STORE', 'contaoEventId' => 123, 'pid' => 123,
        ];
    }
}
