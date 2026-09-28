<?php

// Included in the isolated HTTP harness. Every fixture below is removed in finally.
use Koertho\ChurchToolsBundle\Model\ChurchToolsEntryModel;
use Koertho\ChurchToolsBundle\Tests\Sync\RemoteFixture;

$ownedContents = [];
$ownedArchives = [];
$ownedEvents = [];
$testTheme = $testLayout = null;
try {
    $zone = new DateTimeZone(date_default_timezone_get());
    $today = new DateTimeImmutable('today', $zone);
    $utc = static fn (DateTimeImmutable $date): string => $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    foreach (['FrontendA', 'FrontendB'] as $name) {
        $db->insert('tl_church_tools_archive', ['name'=>$prefix.$name, 'calendarIds'=>'[110]']);
        $ownedArchives[] = (int) $db->lastInsertId();
    }
    [$firstArchive, $secondArchive] = $ownedArchives;
    $row = RemoteFixture::row($prefix.'-frontend', $utc($today->setTime(9, 0)), $utc($today->setTime(10, 0)));
    $row['appointment']['base']['title'] = 'Source & <safe> appointment';
    $row['appointment']['base']['description'] = "Plain <b>text</b>\n{{env::host}}";
    $first = ChurchToolsEntryModel::saveSource($firstArchive, $row);
    $second = ChurchToolsEntryModel::saveSource($secondArchive, $row);
    $db->insert('tl_calendar_events', ['pid'=>$calendarId, 'title'=>'Editorial visible', 'teaser'=>'<p><strong>Reviewed</strong> teaser</p><script>unsafe</script>',
        'source'=>'default', 'startDate'=>$today->getTimestamp(), 'endDate'=>$today->getTimestamp(),
        'startTime'=>$today->setTime(11, 0)->getTimestamp(), 'endTime'=>$today->setTime(12, 0)->getTimestamp(), 'addTime'=>1, 'published'=>1]);
    $ownedEvents[] = $target = (int) $db->lastInsertId();
    $db->update('tl_church_tools_entry', ['contaoEventId'=>$target], ['id'=>$second->id]);
    $span = RemoteFixture::row($prefix.'-span', $today->modify('-1 day')->format('Y-m-d'), $today->modify('+1 day')->format('Y-m-d'), true);
    $span['appointment']['base']['title'] = 'All day span';
    ChurchToolsEntryModel::saveSource($firstArchive, $span);
    $point = RemoteFixture::row($prefix.'-point', $utc($today->setTime(14, 0)), $utc($today->setTime(14, 0)));
    $point['appointment']['base']['title'] = 'Point appointment';
    ChurchToolsEntryModel::saveSource($firstArchive, $point);
    $db->insert('tl_article', ['pid'=>$readerPage, 'title'=>$prefix.'Frontend', 'alias'=>$prefix.'frontend', 'published'=>1]);
    $articles[] = $testArticle = (int) $db->lastInsertId();
    $db->insert('tl_theme', ['name'=>$prefix.'FrontendTheme']);
    $testTheme = (int) $db->lastInsertId();
    $db->insert('tl_layout', ['pid'=>$testTheme, 'name'=>$prefix.'FrontendLayout', 'template'=>'fe_page',
        'modules'=>serialize([['mod'=>0, 'col'=>'main', 'enable'=>1]])]);
    $testLayout = (int) $db->lastInsertId();
    $db->update('tl_page', ['useSSL'=>0, 'includeLayout'=>1, 'layout'=>$testLayout], ['id'=>$rootPage]);
    $db->update('tl_page', ['includeCache'=>1, 'cache'=>3600, 'alwaysLoadFromCache'=>1], ['id'=>$readerPage]);
    foreach (['church_tools_list', 'church_tools_calendar'] as $type) {
        $db->insert('tl_content', ['pid'=>$testArticle, 'ptable'=>'tl_article', 'type'=>$type,
            'churchToolsArchives'=>serialize($ownedArchives), 'churchToolsDays'=>7, 'tstamp'=>time()]);
        $ownedContents[] = (int) $db->lastInsertId();
    }
    [$listId, $calendarElementId] = $ownedContents;
    $db->insert('tl_church_tools_archive', ['name'=>$prefix.'FrontendEmpty', 'calendarIds'=>'[110]']);
    $ownedArchives[] = $emptyArchive = (int) $db->lastInsertId();
    $db->insert('tl_content', ['pid'=>$testArticle, 'ptable'=>'tl_article', 'type'=>'church_tools_list',
        'churchToolsArchives'=>serialize([$emptyArchive]), 'churchToolsDays'=>7, 'tstamp'=>time()]);
    $ownedContents[] = $emptyListId = (int) $db->lastInsertId();
    $listPath = '/ct-resolver?content='.$listId.'&page='.$readerPage;
    $calendarPath = '/ct-resolver?content='.$calendarElementId.'&page='.$readerPage;
    $pagePath = parse_url(\Contao\PageModel::findByPk($readerPage)->getFrontendUrl(), PHP_URL_PATH);
    $pageResponse = $follow($request($pagePath));
    $check($pageResponse[0] === 200, 'Actual temporary frontend page HTTP '.$pageResponse[0].'; path '.$pagePath.'; redirect '.($pageResponse[2]['location'][0] ?? 'none'));
    $check(str_contains($pageResponse[1], 'Editorial visible') && str_contains(implode(',', $pageResponse[2]['cache-control'] ?? []), 'no-store'), 'Actual frontend page combines both elements with no-store');
    $html = static function (string $path) use ($request, $check): string {
        $r = $request($path);
        $check($r[0] === 200, 'Content element frontend HTTP '.$r[0]);
        $control = implode(',', $r[2]['cache-control'] ?? []);
        $check(str_contains($control, 'no-store') && !str_contains($control, 'public'), 'Main HTTP response is never shared-cacheable: '.$control);
        return $r[1];
    };
    $cookies = []; $csrf = null;
    $check(str_contains($html('/ct-resolver?content='.$emptyListId.'&page='.$readerPage), 'No appointments'), 'Empty selected archive renders a clear empty state');
    $view = $html($listPath);
    $check(substr_count($view, 'Editorial visible') === 1 && !str_contains($view, 'Source &amp; &lt;safe&gt; appointment'), 'Visible linked target wins once over duplicate source');
    $check(str_contains($view, '<strong>Reviewed</strong>') && !str_contains($view, '<script>unsafe</script>'), 'Editorial teaser sanitized as rich text');
    $check(substr_count($view, 'All day span') === 1 && substr_count($view, 'Point appointment') === 1, 'List shows span and point once');
    $check(!str_contains($view, $prefix.'Target'), 'Independent core event excluded');
    $view = $html($calendarPath.'&unrelated=keep');
    if (getenv('CHURCHTOOLS_VISUAL_CAPTURE') === '1') {
        $css = getenv('CHURCHTOOLS_VISUAL_STYLESHEET') ?: '';
        $stylesheet = $css === '' ? '' : '<link rel="stylesheet" href="'.htmlspecialchars($css, ENT_QUOTES).'">';
        file_put_contents(dirname(__DIR__, 2).'/.step7-preview.html', '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'.$stylesheet.'</head><body><main class="container py-4"><h1>Isolated ChurchTools preview</h1>'.$html($listPath).$view.'</main></body></html>');
    }
    $check(str_contains($view, 'Editorial visible') && substr_count($view, 'All day span') >= 2, 'Calendar repeats span on relevant days');
    $spansPreviousMonth = $today->modify('-1 day')->format('Y-m') !== $today->format('Y-m');
    $spansNextMonth = $today->modify('+1 day')->format('Y-m') !== $today->format('Y-m');
    $check(str_contains($view, 'rel="prev"') === $spansPreviousMonth && str_contains($view, 'rel="next"') === $spansNextMonth,
        'Initial calendar links follow the local months actually reached by its span');
    $invalid = $html($calendarPath.'&ct_month_'.$calendarElementId.'=9999-99');
    $check(str_contains($invalid, 'Editorial visible'), 'Invalid month falls back to current month');
    $nextMonth = $today->modify('first day of next month')->format('Y-m');
    $empty = $html($calendarPath.'&ct_month_'.$calendarElementId.'='.$nextMonth);
    $check(($spansNextMonth ? str_contains($empty, 'All day span') && !str_contains($empty, 'Editorial visible') : str_contains($empty, 'Editorial visible'))
        && !str_contains($empty, 'rel="next"'), 'Next-month request renders only reachable span or clamps to current bound');
    $db->update('tl_calendar', ['protected'=>1, 'groups'=>serialize([$memberGroup])], ['id'=>$calendarId]);
    $cookies = []; $csrf = null;
    $view = $html($listPath);
    $check(str_contains($view, 'Source &amp; &lt;safe&gt; appointment') && !str_contains($view, 'Editorial visible') && !str_contains($view, '<b>text</b>') && str_contains($view, '&lt;b&gt;text&lt;/b&gt;') && str_contains($view, '{{env::host}}'), 'Anonymous source fallback stays escaped plaintext without insert tags');
    $check(!str_contains($html($pagePath), 'Editorial visible'), 'Cached-config page stays anonymous after earlier editorial response');
    $memberLogin('other');
    $view = $html($listPath);
    $check(!str_contains($view, 'Editorial visible') && str_contains($view, 'Source &amp; &lt;safe&gt; appointment'), 'Other member cannot read editorial target');
    $check(!str_contains($html($pagePath), 'Editorial visible'), 'Cached-config page stays source-only for other member');
    $memberLogin('allowed');
    $view = $html($listPath);
    $check(str_contains($view, 'Editorial visible') && !str_contains($view, 'Source &amp; &lt;safe&gt; appointment'), 'Allowed member reads editorial target');
    $check(str_contains($html($pagePath), 'Editorial visible'), 'Cached-config page shows editorial target to allowed member');
    $cookies = []; $csrf = null;
    $view = $html($listPath);
    $check(!str_contains($view, 'Editorial visible'), 'Anonymous after allowed member has no target leak');
    $check(!str_contains($html($pagePath), 'Editorial visible'), 'Cached-config page returns to anonymous source after allowed member');
    $db->update('tl_calendar', ['protected'=>0], ['id'=>$calendarId]);
    $db->update('tl_calendar_events', ['title'=>'Editorial edited'], ['id'=>$target]);
    $check(str_contains($html($listPath), 'Editorial edited'), 'Repeated request reflects core edit');
    $check(str_contains($html($pagePath), 'Editorial edited'), 'Repeated cached-config page reflects core edit');
    $db->update('tl_calendar_events', ['start'=>time()+300], ['id'=>$target]);
    $check(!str_contains($html($listPath), 'Editorial edited'), 'Future publication start falls back immediately');
    $db->update('tl_calendar_events', ['start'=>\Contao\Date::floorToMinute(), 'stop'=>''], ['id'=>$target]);
    $check(str_contains($html($listPath), 'Editorial edited'), 'Current-minute publication starts immediately');
    $db->update('tl_calendar_events', ['stop'=>\Contao\Date::floorToMinute()], ['id'=>$target]);
    $check(!str_contains($html($listPath), 'Editorial edited'), 'Current-minute stop falls back immediately');
    $db->update('tl_calendar_events', ['stop'=>''], ['id'=>$target]);
    $saved = $db->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$target]);
    $db->delete('tl_calendar_events', ['id'=>$target]);
    $check(!str_contains($html($listPath), 'Editorial edited'), 'Deleted target falls back on repeated HTTP');
    $check(!str_contains($html($pagePath), 'Editorial edited'), 'Repeated cached-config page reflects target deletion');
    $db->insert('tl_calendar_events', $saved);
    $check(str_contains($html($listPath), 'Editorial edited'), 'Same-ID restore appears on repeated HTTP');
    $db->update('tl_church_tools_entry', ['contaoEventId'=>null], ['id'=>$second->id]);
    $row['appointment']['base']['title'] = 'Source updated after sync write';
    ChurchToolsEntryModel::saveSource($firstArchive, $row);
    $check(str_contains($html($listPath), 'Source updated after sync write'), 'Unlink and source upsert appear on repeated HTTP');
    $check(str_contains($html($pagePath), 'Source updated after sync write'), 'Repeated cached-config page reflects source upsert and unlink');
    $db->update('tl_church_tools_entry', ['contaoEventId'=>$target], ['id'=>$second->id]);
    $check(str_contains($html($listPath), 'Editorial edited'), 'Relink appears on repeated HTTP');

    // Navigation fixtures are separate from the preceding resolver/cache tests.
    $db->insert('tl_content', ['pid'=>$testArticle, 'ptable'=>'tl_article', 'type'=>'church_tools_calendar',
        'churchToolsArchives'=>serialize([$emptyArchive]), 'tstamp'=>time()]);
    $ownedContents[] = $emptyCalendarId = (int) $db->lastInsertId();
    $emptyCalendarPath = '/ct-resolver?content='.$emptyCalendarId.'&page='.$readerPage;
    $emptyCalendar = $html($emptyCalendarPath.'&ct_month_'.$emptyCalendarId.'=9999-12');
    $check(str_contains($emptyCalendar, 'No appointments') && !str_contains($emptyCalendar, 'rel="prev"') && !str_contains($emptyCalendar, 'rel="next"'), 'Empty archive clamps to current month without navigation');
    $check(str_contains($emptyCalendar, 'datetime="'.$today->format('Y-m-01').'"'), 'Empty archive displays the current local month after clamping');
    $check(str_contains($html($emptyCalendarPath.'&ct_month_'.$emptyCalendarId.'=invalid'), 'No appointments'), 'Invalid month keeps empty archive at current month');

    $db->insert('tl_church_tools_archive', ['name'=>$prefix.'Navigation', 'calendarIds'=>'[110]']);
    $ownedArchives[] = $navigationArchive = (int) $db->lastInsertId();
    $currentMonth = $today->modify('first day of this month');
    $pastMonth = $currentMonth->modify('-2 months');
    $futureMonth = $currentMonth->modify('+3 months');
    $previousMonth = $currentMonth->modify('-1 month');
    $pastRow = RemoteFixture::row($prefix.'-nav-past', $pastMonth->modify('+14 days')->format('Y-m-d'), $pastMonth->modify('+14 days')->format('Y-m-d'), true);
    $pastRow['appointment']['base']['title'] = 'Navigation past';
    ChurchToolsEntryModel::saveSource($navigationArchive, $pastRow);
    $futureRow = RemoteFixture::row($prefix.'-nav-future', $futureMonth->modify('+3 days')->format('Y-m-d'), $futureMonth->modify('+3 days')->format('Y-m-d'), true);
    $futureRow['appointment']['base']['title'] = 'Navigation future';
    ChurchToolsEntryModel::saveSource($navigationArchive, $futureRow);
    $edgeRow = RemoteFixture::row($prefix.'-nav-edge', $currentMonth->modify('-1 day')->format('Y-m-d'), $currentMonth->format('Y-m-d'), true);
    $edgeRow['appointment']['base']['title'] = 'Navigation edge span';
    ChurchToolsEntryModel::saveSource($navigationArchive, $edgeRow);
    $db->insert('tl_content', ['pid'=>$testArticle, 'ptable'=>'tl_article', 'type'=>'church_tools_calendar',
        'churchToolsArchives'=>serialize([$navigationArchive]), 'tstamp'=>time()]);
    $ownedContents[] = $navigationId = (int) $db->lastInsertId();
    $navigationPath = '/ct-resolver?content='.$navigationId.'&page='.$readerPage;
    $monthKey = 'ct_month_'.$navigationId;
    $gap = $html($navigationPath.'&unrelated=a%26b');
    $check(str_contains($gap, 'Navigation edge span') && !str_contains($gap, 'Navigation past') && !str_contains($gap, 'Navigation future'), 'Current month shows only overlapping winners across a gap');
    $check(str_contains($gap, 'rel="prev"') && str_contains($gap, 'rel="next"') && str_contains($gap, 'unrelated=a%26b'), 'Bounded month links preserve encoded unrelated GET values');
    $between = $html($navigationPath.'&'.$monthKey.'='.$currentMonth->modify('+1 month')->format('Y-m'));
    $check(str_contains($between, 'No appointments') && str_contains($between, 'rel="prev"') && str_contains($between, 'rel="next"'), 'Empty month inside retained range remains traversable');
    $edge = $html($navigationPath.'&'.$monthKey.'='.$previousMonth->format('Y-m'));
    $check(str_contains($edge, 'Navigation edge span') && !str_contains($edge, 'Navigation past'), 'Multi-day occurrence appears in previous local month');
    $past = $html($navigationPath.'&'.$monthKey.'=1000-01');
    $check(str_contains($past, 'Navigation past') && !str_contains($past, 'rel="prev"') && str_contains($past, 'rel="next"'), 'Valid early request clamps to first effective month');
    $future = $html($navigationPath.'&'.$monthKey.'=9999-12');
    $check(str_contains($future, 'Navigation future') && !str_contains($future, 'rel="next"') && str_contains($future, 'rel="prev"'), 'Valid late request clamps to last effective month');
    $invalid = $html($navigationPath.'&'.$monthKey.'=2026-99');
    $check(str_contains($invalid, 'Navigation edge span') && !str_contains($invalid, 'Navigation future'), 'Invalid month falls back to anchored current month');

    $db->insert('tl_church_tools_archive', ['name'=>$prefix.'Moved', 'calendarIds'=>'[110]']);
    $ownedArchives[] = $movedArchive = (int) $db->lastInsertId();
    $movedRow = RemoteFixture::row($prefix.'-nav-moved', $utc($today->setTime(9, 0)), $utc($today->setTime(10, 0)));
    $movedRow['appointment']['base']['title'] = 'Source current only';
    $movedEntry = ChurchToolsEntryModel::saveSource($movedArchive, $movedRow);
    $movedDay = $currentMonth->modify('+4 months')->modify('+4 days');
    $db->insert('tl_calendar_events', ['pid'=>$calendarId, 'title'=>'Restricted future target', 'source'=>'default',
        'startDate'=>$movedDay->getTimestamp(), 'endDate'=>$movedDay->getTimestamp(),
        'startTime'=>$movedDay->setTime(9, 0)->getTimestamp(), 'endTime'=>$movedDay->setTime(10, 0)->getTimestamp(), 'addTime'=>1, 'published'=>1]);
    $ownedEvents[] = $movedTarget = (int) $db->lastInsertId();
    $db->update('tl_church_tools_entry', ['contaoEventId'=>$movedTarget], ['id'=>$movedEntry->id]);
    $db->insert('tl_content', ['pid'=>$testArticle, 'ptable'=>'tl_article', 'type'=>'church_tools_calendar',
        'churchToolsArchives'=>serialize([$movedArchive]), 'tstamp'=>time()]);
    $ownedContents[] = $movedCalendarId = (int) $db->lastInsertId();
    $movedPath = '/ct-resolver?content='.$movedCalendarId.'&page='.$readerPage;
    $movedKey = 'ct_month_'.$movedCalendarId;
    $db->update('tl_calendar', ['protected'=>1, 'groups'=>serialize([$memberGroup])], ['id'=>$calendarId]);
    $cookies = []; $csrf = null;
    $anonymous = $html($movedPath);
    $check(str_contains($anonymous, 'Source current only') && !str_contains($anonymous, 'Restricted future target') && !str_contains($anonymous, 'rel="next"'), 'Anonymous source date sets current-only bound without target leak');
    $memberLogin('other');
    $other = $html($movedPath);
    $check(str_contains($other, 'Source current only') && !str_contains($other, 'Restricted future target') && !str_contains($other, 'rel="next"'), 'Member without rights retains source-only bound');
    $memberLogin('allowed');
    $allowed = $html($movedPath);
    $check(!str_contains($allowed, 'Source current only') && !str_contains($allowed, 'Restricted future target') && str_contains($allowed, 'rel="next"'), 'Visible moved target expands bound while current month remains empty');
    $allowedPage = $html($pagePath);
    $check(str_contains($allowedPage, $movedKey.'=') && !str_contains($allowedPage, 'Source current only'), 'Full page exposes moved-month navigation only to allowed member');
    $allowedFuture = $html($movedPath.'&'.$movedKey.'='.$movedDay->format('Y-m'));
    $check(str_contains($allowedFuture, 'Restricted future target') && !str_contains($allowedFuture, 'rel="next"'), 'Visible effective date determines last navigable month');
    $cookies = []; $csrf = null;
    $anonymousAgain = $html($movedPath.'&'.$movedKey.'='.$movedDay->format('Y-m'));
    $check(str_contains($anonymousAgain, 'Source current only') && !str_contains($anonymousAgain, 'Restricted future target') && !str_contains($anonymousAgain, 'rel="next"'), 'Later anonymous request clamps without revealing target or its month');
    $anonymousPage = $html($pagePath);
    $check(!str_contains($anonymousPage, $movedKey.'=') && !str_contains($anonymousPage, 'Restricted future target') && str_contains($anonymousPage, 'Source current only'), 'Repeated full page does not retain allowed-member navigation');
} finally {
    foreach ($ownedContents as $id) $db->delete('tl_content', ['id'=>$id]);
    foreach ($ownedEvents as $id) $db->delete('tl_calendar_events', ['id'=>$id]);
    foreach ($ownedArchives as $id) {
        $db->delete('tl_church_tools_entry', ['pid'=>$id]);
        $db->delete('tl_church_tools_archive', ['id'=>$id]);
    }
    $db->update('tl_calendar', ['protected'=>0], ['id'=>$calendarId]);
    $db->update('tl_page', ['useSSL'=>1, 'includeLayout'=>0, 'layout'=>0], ['id'=>$rootPage]);
    if ($testLayout) $db->delete('tl_layout', ['id'=>$testLayout]);
    if ($testTheme) $db->delete('tl_theme', ['id'=>$testTheme]);
}
