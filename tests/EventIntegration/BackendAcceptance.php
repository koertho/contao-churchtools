<?php

// Included in the real step-5 login/CSRF harness; no alternate authorization path.
use Koertho\ChurchToolsBundle\Tests\Sync\RemoteFixture;
use Koertho\ChurchToolsBundle\Model\ChurchToolsEntryModel;

$db->update('tl_user', ['language'=>'en'], ['id'=>$users[0]]);
$login('admin');
$permissionsForm = $request('/contao?do=group&act=edit&id='.$groups[0]);
$check($permissionsForm[0] === 200 && str_contains($permissionsForm[1], 'tl_church_tools_entry::contaoEventId'), 'Separate local permission is selectable in actual core group editor');
$actionPath = '/contao/church-tools/entry/'.$entries[0];
$r = $request($actionPath);
$check($r[0] === 200, 'Action form HTTP '.$r[0]);
$check(!str_contains($r[1], 'value="create"') && str_contains($r[1], '4294967294'), 'Missing ID retained in form; no create');
$beforeCount = (int)$db->fetchOne('SELECT COUNT(*) FROM tl_calendar_events');
$check($request($actionPath, ['action'=>'create','target'=>$calendarId])[0] === 303, 'Repeated create with missing existing link is no-op');
$check((int)$db->fetchOne('SELECT COUNT(*) FROM tl_calendar_events') === $beforeCount, 'Create never replaces dangling reference');
$check($request($actionPath.'?open=1')[0] === 403, 'Missing target cannot open');
$check($request($actionPath, ['action'=>'unlink','REQUEST_TOKEN'=>'invalid'])[0] === 400, 'Invalid CSRF denied');
$request($actionPath.'?action=unlink&target='.$eventId);
$check((int)$db->fetchOne('SELECT contaoEventId FROM tl_church_tools_entry WHERE id=?', [$entries[0]]) === 4294967294, 'GET never mutates');
$check($request($actionPath, ['action'=>'unlink'])[0] === 303, 'Explicit unlink');
$check($db->fetchOne('SELECT contaoEventId FROM tl_church_tools_entry WHERE id=?', [$entries[0]]) === null, 'Only unlink clears missing ID');
$check($request($actionPath, ['action'=>'link','target'=>$eventId])[0] === 303, 'Existing target link');
$check($request($actionPath.'?open=1')[0] === 302, 'Authorized open redirects');
$check($request($actionPath, ['action'=>'link','target'=>$eventId])[0] === 303, 'Double link idempotent');
$check($request($actionPath, ['action'=>'link','target'=>4294967294])[0] === 409, 'Replace requires explicit unlink');
$request($actionPath, ['action'=>'unlink']);

// Existing reader/editor roles have no local link permission, even with calendar rights.
foreach (['reader','editor','denied'] as $role) {
    $login($role);
    $check($request($actionPath)[0] === 403, $role.' selection denied without local permission');
    $check($request($actionPath, ['action'=>'create','target'=>$calendarId])[0] === 403, $role.' mutation denied');
    $check($request($actionPath.'?open=1')[0] === 403, $role.' open denied');
}
$editorGroup = $groups[0];
$db->update('tl_user_group', ['modules'=>serialize(['church_tools_events','calendar']), 'calendars'=>serialize([$calendarId]),
    'alexf'=>serialize(['tl_church_tools_entry::contaoEventId','tl_calendar_events::teaser','tl_content::text']),
    'cud'=>serialize(['tl_calendar_events::create','tl_calendar_events::update','tl_content::create','tl_content::update']), 'elements'=>serialize(['text'])], ['id'=>$editorGroup]);
$db->update('tl_user_group', ['alexf'=>serialize(['tl_calendar_events::teaser','tl_content::text'])], ['id'=>$editorGroup]);
$login('editor');
$check($request($actionPath)[0] === 403, 'Calendar/editor rights alone do not grant local linking');
$db->update('tl_user_group', ['alexf'=>serialize(['tl_church_tools_entry::contaoEventId','tl_calendar_events::teaser','tl_content::text'])], ['id'=>$editorGroup]);
$db->insert('tl_calendar', ['title'=>$prefix.'ForbiddenCalendar']);
$foreignCalendar = (int)$db->lastInsertId();
$extraCalendars[] = $foreignCalendar;
$db->insert('tl_calendar_events', ['pid'=>$foreignCalendar,'title'=>$prefix.'ForbiddenTarget']);
$foreignEvent = (int)$db->lastInsertId();
$login('editor');
$r = $request($actionPath);
$check($r[0] === 200 && str_contains($r[1], $prefix.'Target'), 'Local link permission plus authorized calendar permits selection');
$check(!str_contains($r[1], $prefix.'Forbidden'), 'Foreign calendar/event titles absent from selection');
$check($request($actionPath, ['action'=>'link','target'=>$foreignEvent])[0] === 403, 'Forged target ID rejected');
$check($request($actionPath, ['action'=>'create','target'=>$foreignCalendar])[0] === 403, 'Forged calendar ID rejected');
$db->update('tl_church_tools_entry', ['contaoEventId'=>$foreignEvent], ['id'=>$entries[0]]);
$check($request($actionPath.'?open=1')[0] === 403, 'Foreign linked record cannot open');
$check(!str_contains($request($actionPath)[1], $prefix.'Forbidden'), 'Foreign linked record title never exposed');
$request($actionPath, ['action'=>'unlink']);
$check($request($actionPath, ['action'=>'create','target'=>$calendarId])[0] === 303, 'Restricted editor creates authorized target');
$createdId = (int)$db->fetchOne('SELECT contaoEventId FROM tl_church_tools_entry WHERE id=?', [$entries[0]]);
$createdRow = $db->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$createdId]);
$check($createdId > 0 && !$createdRow['published'] && $createdRow['source'] === 'default' && !$createdRow['addImage'] && !$createdRow['location'] && !$createdRow['url'] && !$createdRow['recurring'], 'Created target unpublished and reader-compatible');
$check((int)$db->fetchOne('SELECT COUNT(*) FROM tl_content WHERE pid=? AND ptable=?', [$createdId, 'tl_calendar_events']) === 1, 'Real reader detail text created');
$check(!str_contains($createdRow['teaser'], '<script>') && str_contains($createdRow['teaser'], '<br>'), 'Copied plaintext safe rich text with line breaks');
$check($request($actionPath, ['action'=>'create','target'=>$calendarId])[0] === 303 && (int)$db->fetchOne('SELECT contaoEventId FROM tl_church_tools_entry WHERE id=?', [$entries[0]]) === $createdId, 'Double create retains single target');
$check($follow($request($actionPath.'?open=1'))[0] === 200, 'Created event opens real core editor');
$db->update('tl_user_group', ['cud'=>serialize(['tl_calendar_events::create','tl_content::create'])], ['id'=>$editorGroup]);
$login('editor');
$check($request($actionPath.'?open=1')[0] === 403, 'Record update permission required to open');
$db->update('tl_user_group', ['modules'=>serialize(['church_tools_events'])], ['id'=>$editorGroup]);
$login('editor');
$check($request($actionPath.'?open=1')[0] === 403, 'Calendar module permission required to open');
$login('admin');
$request($actionPath, ['action'=>'unlink']);
$check($db->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$createdId]) === $createdRow, 'Unlink preserves core event');

$core6 = version_compare(\Contao\CoreBundle\ContaoCoreBundle::getVersion(), '6.0', '>=');
$dateTitle = 'Literal & <bold>Title</bold> **Markdown**'.($core6 ? '' : ' {{env::host}}');
$dateTargets = [];
$cases = [
    [true,'2026-03-29','2026-03-29','2026-03-29 00:00:00','2026-03-29 23:59:59'],
    [true,'2026-10-25','2026-10-25','2026-10-25 00:00:00','2026-10-25 23:59:59'],
    [true,'2026-03-28','2026-03-30','2026-03-28 00:00:00','2026-03-30 23:59:59'],
    [true,'2026-10-24','2026-10-26','2026-10-24 00:00:00','2026-10-26 23:59:59'],
    [false,'2026-03-29T00:30:00Z','2026-03-29T01:30:00Z','2026-03-29 01:30:00','2026-03-29 03:30:00'],
    [false,'2026-10-25T02:30:00+02:00','2026-10-25T02:30:00+01:00','2026-10-25 02:30:00','2026-10-25 02:30:00'],
    [false,'2026-09-26T08:00:00Z','2026-09-27T09:00:00Z','2026-09-26 10:00:00','2026-09-27 11:00:00'],
    [false,'2026-09-26T20:00:00Z','2026-09-26T22:00:00Z','2026-09-26 22:00:00','2026-09-27 00:00:00'],
    [false,'2026-09-26T08:00:00.000000Z','2026-09-26T09:00:00.000000Z','2026-09-26 10:00:00','2026-09-26 11:00:00'],
];
$zone = new DateTimeZone('Europe/Berlin');
foreach ($cases as $i => [$allDay,$start,$end,$expectedStart,$expectedEnd]) {
    $source = RemoteFixture::row($prefix.'-date-'.$i);
    $source['appointment']['base']['allDay'] = $allDay;
    $source['appointment']['base']['title'] = $dateTitle;
    $source['appointment']['base']['description'] = "<script>x</script>\n**literal** & {{env::host}}";
    $source['appointment']['calculated']['startDate'] = $start;
    $source['appointment']['calculated']['endDate'] = $end;
    $dateEntry = ChurchToolsEntryModel::saveSource($a, $source);
    $path = '/contao/church-tools/entry/'.$dateEntry->id;
    $r = $request($path, ['action'=>'create','target'=>$calendarId]);
    $check($r[0] === 303, 'Date case '.$i.' create HTTP '.$r[0]);
    $coreId = $db->fetchOne('SELECT contaoEventId FROM tl_church_tools_entry WHERE id=?', [$dateEntry->id]);
    $core = $db->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$coreId]);
    $check($core['title'] === ($core6 ? $dateTitle : str_replace(['{','}'], ['&#123;','&#125;'], htmlspecialchars($dateTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))), 'Version-specific title storage without silent changes '.$i);
    $dateTargets[] = [$coreId, $expectedStart, $expectedEnd, $allDay];
    $format = static fn ($v) => (new DateTimeImmutable('@'.$v))->setTimezone($zone)->format('Y-m-d H:i:s');
    $check($format($core['startTime']) === $expectedStart && $format($core['endTime']) === $expectedEnd, 'Core stored full time stamps '.$i);
    $check($format($core['startDate']) === substr($expectedStart,0,10).' 00:00:00' && $format($core['endDate']) === substr($expectedEnd,0,10).' 00:00:00' && (bool)$core['addTime'] === !$allDay, 'Core dates/addTime '.$i);
    $check(!$core['published'] && str_contains($core['teaser'], '&lt;script&gt;') && str_contains($core['teaser'], '**literal**') && !str_contains($core['teaser'], '{{'), 'Literal markup and unpublished '.$i);
    if (!$allDay) $check((int)$core['endTime'] - (int)$core['startTime'] === (new DateTimeImmutable($end))->getTimestamp() - (new DateTimeImmutable($start))->getTimestamp(), 'Offset instants preserved '.$i);
    else $check((int)$core['endTime'] === (new DateTimeImmutable($end, $zone))->modify('+1 day')->getTimestamp()-1, 'Inclusive local day boundary '.$i);
}
foreach ([['2026-09-26T08:00:00Z','2026-09-26T08:00:00Z'], ['2026-09-26T08:00:00.000001Z','2026-09-26T09:00:00Z'], ['2026-09-26T08:00:00Z','2026-09-26T08:00:00.000001Z']] as $i => [$start,$end]) {
    $source = RemoteFixture::row($prefix.'-unsupported-'.$i);
    $source['appointment']['calculated']['startDate']=$start;
    $source['appointment']['calculated']['endDate']=$end;
    $entry = ChurchToolsEntryModel::saveSource($a, $source);
    $n = (int)$db->fetchOne('SELECT COUNT(*) FROM tl_calendar_events');
    $check($request('/contao/church-tools/entry/'.$entry->id, ['action'=>'create','target'=>$calendarId])[0] === 422, 'Unrepresentable time rejected explicitly '.$i);
    $check($db->fetchOne('SELECT contaoEventId FROM tl_church_tools_entry WHERE id=?', [$entry->id]) === null && (int)$db->fetchOne('SELECT COUNT(*) FROM tl_calendar_events') === $n, 'No time coercion or partial create '.$i);
}

// Separate title boundary: no mutation or partial writes on Core 6 rejection.
foreach (['{{env::host}}', 'Literal {{ unfinished'] as $i => $probeTitle) {
    $source = RemoteFixture::row($prefix.'-title-boundary-'.$i);
    $source['appointment']['base']['title'] = $probeTitle;
    $entry = ChurchToolsEntryModel::saveSource($a, $source);
    $beforeSource = $db->fetchAssociative('SELECT * FROM tl_church_tools_entry WHERE id=?', [$entry->id]);
    $beforeEvents = (int)$db->fetchOne('SELECT COUNT(*) FROM tl_calendar_events');
    $beforeContent = (int)$db->fetchOne('SELECT COUNT(*) FROM tl_content');
    $response = $request('/contao/church-tools/entry/'.$entry->id, ['action'=>'create','target'=>$calendarId]);
    $check($response[0] === ($core6 ? 422 : 303), 'Version-specific title boundary status '.$i);
    if ($core6) {
        $messages = require dirname(__DIR__, 2).'/translations/church_tools_backend.en.php';
        $check(str_contains(html_entity_decode(strip_tags($response[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $messages['title_insert_tags_not_representable']), 'Exact translated title feedback '.$i);
        $check($db->fetchAssociative('SELECT * FROM tl_church_tools_entry WHERE id=?', [$entry->id]) === $beforeSource, 'Rejected title source unchanged including null link '.$i);
        $check((int)$db->fetchOne('SELECT COUNT(*) FROM tl_calendar_events') === $beforeEvents && (int)$db->fetchOne('SELECT COUNT(*) FROM tl_content') === $beforeContent, 'Rejected title creates neither event nor detail '.$i);
    } else {
        $titleId = $db->fetchOne('SELECT contaoEventId FROM tl_church_tools_entry WHERE id=?', [$entry->id]);
        $check($db->fetchOne('SELECT title FROM tl_calendar_events WHERE id=?', [$titleId]) === str_replace(['{','}'], ['&#123;','&#125;'], $probeTitle), 'Core 5 encoded title retained '.$i);
    }
}
