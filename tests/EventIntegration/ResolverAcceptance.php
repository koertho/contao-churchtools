<?php
use Koertho\ChurchToolsBundle\Model\ChurchToolsEntryModel;
use Koertho\ChurchToolsBundle\Tests\Sync\RemoteFixture;

$db->insert('tl_page', ['title'=>$prefix.'Root', 'type'=>'root', 'alias'=>$prefix.'root','language'=>'en','fallback'=>1,'published'=>1,'dateFormat'=>'Y-m-d','timeFormat'=>'H:i','datimFormat'=>'Y-m-d H:i','urlSuffix'=>'.html']);
$pages[] = $rootPage = (int)$db->lastInsertId();
$db->insert('tl_page', ['pid'=>$rootPage,'title'=>$prefix.'Reader','type'=>'regular','alias'=>$prefix.'reader','published'=>1]);
$pages[] = $readerPage = (int)$db->lastInsertId();
$db->update('tl_calendar', ['jumpTo'=>$readerPage], ['id'=>$calendarId]);
$db->insert('tl_member_group', ['name'=>$prefix.'Members']);
$memberGroups[] = $memberGroup = (int)$db->lastInsertId();
foreach (['allowed','other'] as $role) {
    $db->insert('tl_member', ['username'=>$prefix.'_'.$role,'password'=>password_hash($password, PASSWORD_DEFAULT),'email'=>$prefix.'_'.$role.'@example.invalid','login'=>1,'groups'=>serialize($role==='allowed'?[$memberGroup]:[])]);
    $members[] = (int)$db->lastInsertId();
}
$cookies=[];$csrf=null;
foreach ($dateTargets as [$dateTarget,$expectedStart,$expectedEnd,$allDay]) {
    $db->update('tl_calendar_events',['published'=>1],['id'=>$dateTarget]);
    $db->executeStatement("UPDATE tl_content SET text=CONCAT(text, '<p>Reader detail fixture marker</p>') WHERE pid=? AND ptable='tl_calendar_events'", [$dateTarget]);
    $rendered = $request('/ct-resolver?render='.$dateTarget.'&page='.$readerPage);
    $check($rendered[0] === 200, 'Core reader HTTP '.$rendered[0]);
    $check(str_contains($rendered[1], 'Reader detail fixture marker'), 'Actual edited content child rendered, not teaser fallback');
    $check(str_contains($rendered[1], substr($expectedStart,0,10)) && str_contains($rendered[1], substr($expectedEnd,0,10)), 'Core rendered start/end date');
    if (!$allDay) $check(str_contains($rendered[1], substr($expectedStart,11,5)) && str_contains($rendered[1], substr($expectedEnd,11,5)), 'Core rendered start/end clock');
    $check(str_contains(html_entity_decode(strip_tags($rendered[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $dateTitle), 'Core reader title preserves plaintext and insert-tag syntax');
    $check(substr_count(html_entity_decode(strip_tags($rendered[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), '{{env::host}}') >= ($core6 ? 1 : 2), 'Actual detail and supported title preserve literal insert tags');
    $check(str_contains($rendered[1], '&lt;script&gt;x&lt;/script&gt;') && str_contains($rendered[1], '**literal**') && !str_contains($rendered[1], '<script>x'), 'Core reader details preserve literal source text');
}
// Core 6 insert_tag itself entity-encodes text before Twig autoescape in the attribute.
// Assert that native representation explicitly; do not compensate by changing storage.
$listed = $request('/ct-resolver?render='.$dateTargets[0][0].'&page='.$readerPage.'&list=1');
$check($listed[0] === 200, 'Actual ModuleEventlist HTTP');
$listDocument = new DOMDocument();
@$listDocument->loadHTML($listed[1]);
$listXpath = new DOMXPath($listDocument);
$listLinks = $listXpath->query('//div[contains(@class,"layout_list")]//h2/a');
$check($listLinks->length === count($dateTargets), 'Core event_list renders every copied date fixture');
foreach ($listLinks as $link) {
    $check($link->textContent === $dateTitle, 'Core event_list link text preserves literal title without double encoding');
    $check(str_starts_with($link->getAttribute('title'), ($core6 ? htmlspecialchars($dateTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $dateTitle).' ('), 'Core event_list link-title follows Core escaping without insert-tag expansion');
}
$check(str_contains(html_entity_decode(strip_tags($listed[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), '{{env::host}}'), 'Core event_list description preserves literal insert tag');
// Publish through the real core editor, using the browser's successful controls.
$login('admin');
$coreEdit='/contao?do=calendar&table=tl_calendar_events&act=edit&id='.$createdId;
$coreForm=$request($coreEdit);
$check($coreForm[0]===200,'Actual core publication form');
$document=new DOMDocument();
@$document->loadHTML($coreForm[1]);
$xpath=new DOMXPath($document);$pairs=[];
foreach($xpath->query('//form[@id="tl_calendar_events"]//*[@name]') as $control){
    if($control->hasAttribute('disabled'))continue;
    $name=$control->getAttribute('name');$type=$control->getAttribute('type');
    if(in_array($type,['submit','button'],true)|| (in_array($type,['checkbox','radio'],true)&&!$control->hasAttribute('checked')))continue;
    if($control->tagName==='textarea')$values=[$control->textContent];
    elseif($control->tagName==='select'){
        $values=[];foreach($control->getElementsByTagName('option')as$option)if($option->hasAttribute('selected'))$values[]=$option->getAttribute('value');
        if(!$values&&$control->getElementsByTagName('option')->length)$values[]=$control->getElementsByTagName('option')->item(0)->getAttribute('value');
    }else $values=[$control->getAttribute('value')];
    foreach($values as $value)$pairs[]=rawurlencode($name).'='.rawurlencode($value);
}
parse_str(implode('&',$pairs),$corePost);
$corePost['FORM_SUBMIT']='tl_calendar_events';$corePost['published']='1';$corePost['save']='1';
$savedForm=$request($coreEdit,$corePost);
if (!(bool)$db->fetchOne('SELECT published FROM tl_calendar_events WHERE id=?',[$createdId])) {
    preg_match_all('~<(?:p|div)[^>]*class="[^"]*(?:tl_error|error)[^"]*"[^>]*>(.*?)</(?:p|div)>~s',$savedForm[1],$formErrors);
    throw new RuntimeException('Core publication HTTP '.$savedForm[0].'; controls: '.implode(',',array_keys($corePost)).'; validation: '.strip_tags(implode(' ', $formErrors[1])));
}
$check((bool)$db->fetchOne('SELECT published FROM tl_calendar_events WHERE id=?',[$createdId]),'Editor publishes copied event via real DCA POST');
$cookies=[];$csrf=null;
$rendered = $request('/ct-resolver?render='.$createdId.'&page='.$readerPage);
$check($rendered[0]===200 && str_contains($rendered[1], '&lt;script&gt;title-probe&lt;/script&gt;') && !str_contains($rendered[1], '<script>title-probe'), 'Core reader title escapes HTML source');
// Separate archive set for resolver fixtures, retained independently of requested period.
foreach (['ResolverA','ResolverB'] as $name) {
    $db->insert('tl_church_tools_archive', ['name'=>$prefix.$name,'calendarIds'=>'[2]']);
    $archives[] = (int)$db->lastInsertId();
}
$ra = $archives[count($archives)-2]; $rb = $archives[count($archives)-1];
$source = RemoteFixture::row($prefix.'effective');
$source['appointment']['base']['title']='Source visible';
$source['appointment']['calculated']['startDate']='2026-10-20T08:00:00Z';
$source['appointment']['calculated']['endDate']='2026-10-20T09:00:00Z';
$re = ChurchToolsEntryModel::saveSource($ra, $source);
$re2 = ChurchToolsEntryModel::saveSource($rb, $source);
$event = ['pid'=>$calendarId,'title'=>'Editorial visible','teaser'=>'<p>Editorial teaser</p>','source'=>'default','startDate'=>strtotime('2026-09-24'),'endDate'=>strtotime('2026-09-24'),'startTime'=>strtotime('2026-09-24 10:00'),'endTime'=>strtotime('2026-09-24 11:00'),'addTime'=>1,'published'=>1,'recurring'=>1,'repeatEach'=>serialize(['value'=>1,'unit'=>'days']),'recurrences'=>0];
$db->insert('tl_calendar_events', $event);
$effectiveId = (int)$db->lastInsertId();
$db->update('tl_church_tools_entry', ['contaoEventId'=>$effectiveId], ['id'=>$re2->id]);
$cookies = []; $csrf = null;
$resolvePath = '/ct-resolver?archives='.$ra.','.$rb;
$resolve = static function (string $query='') use ($request,$resolvePath,$check): array {
    $r=$request($resolvePath.$query);
    $check($r[0]===200,'Real FE resolver HTTP '.$r[0]);
    $check(str_contains($r[2]['content-type'][0] ?? '', 'application/json'), 'FE resolver JSON content type '.($r[2]['content-type'][0] ?? 'none'));
    return json_decode($r[1], true, flags:JSON_THROW_ON_ERROR);
};
$data = $resolve('&from=2026-09-23&until=2026-09-30');
$check(!$data['member'] && count($data['rows'])===1 && $data['rows'][0]['targetId']===$effectiveId, 'Anonymous visible target wins dedup before period filter');
$check(str_contains($data['rows'][0]['url'], $prefix.'reader/'.$effectiveId.'.html'), 'Actual core numeric-ID event URL');
$check($data['rows'][0]['title']==='Editorial visible' && str_starts_with($data['rows'][0]['start'],'2026-09-24 10:00'), 'Effective core title/time');
$check(count($data['rows'])===1, 'Recurring core target expands no occurrences');
foreach ([4294967294, 0] as $missingParent) {
    $db->update('tl_page', ['pid'=>$missingParent], ['id'=>$readerPage]);
    $fallback = $resolve()['rows'];
    $check(count($fallback) === 1 && $fallback[0]['title'] === 'Source visible' && $fallback[0]['targetId'] === null && $fallback[0]['url'] === null, 'Orphan reader falls back to source without target disclosure '.$missingParent);
    $check((int)$db->fetchOne('SELECT contaoEventId FROM tl_church_tools_entry WHERE id=?', [$re2->id]) === $effectiveId, 'Orphan reader retains stored target ID '.$missingParent);
    $db->update('tl_page', ['pid'=>$rootPage], ['id'=>$readerPage]);
    $check($resolve()['rows'][0]['targetId'] === $effectiveId, 'Restored reader ancestry reactivates target '.$missingParent);
}
$data = $resolve('&from=2026-10-19&until=2026-10-21');
$check($data['rows']===[], 'Winner moved out; no fallback to source duplicate');
$db->update('tl_calendar_events', ['published'=>0], ['id'=>$effectiveId]);
$data=$resolve();
$check(count($data['rows'])===1 && $data['rows'][0]['archiveId']===$ra && $data['rows'][0]['title']==='Source visible' && $data['rows'][0]['url']===null && $data['rows'][0]['targetId']===null, 'Unpublished uses lowest archive source with no target data');
$db->update('tl_calendar_events', ['published'=>1], ['id'=>$effectiveId]);
foreach ([['start'=>time()+600,'stop'=>''],['start'=>'','stop'=>\Contao\Date::floorToMinute()],['start'=>'','stop'=>'0']] as $window) {
    $db->update('tl_calendar_events', $window, ['id'=>$effectiveId]);
    $check($resolve()['rows'][0]['targetId']===null,'Publication start/stop enforced');
}
$db->update('tl_calendar_events', ['start'=>\Contao\Date::floorToMinute(),'stop'=>time()+600], ['id'=>$effectiveId]);
$check($resolve()['rows'][0]['targetId']===$effectiveId,'Inclusive start and future stop visible');
$db->update('tl_calendar_events', ['start'=>'','stop'=>''], ['id'=>$effectiveId]);
$db->update('tl_calendar', ['protected'=>1,'groups'=>serialize([$memberGroup])], ['id'=>$calendarId]);
$check($resolve()['rows'][0]['targetId']===null,'Anonymous restricted calendar falls back');
$memberLogin = static function (string $role) use (&$cookies,&$csrf,$request,$follow,$token,$check,$password,$prefix): void {
    $cookies=[]; $csrf=null;
    $r=$request('/ct-resolver?login=1');
    $check($r[0]===200, 'FE login form');
    $r=$follow($request('/ct-resolver?login=1',['FORM_SUBMIT'=>'tl_login','REQUEST_TOKEN'=>$token($r[1]),'username'=>$prefix.'_'.$role,'password'=>$password,'_target_path'=>base64_encode('/ct-resolver?login=1'),'_always_use_target_path'=>1]));
    $check($r[0]===200,'Actual FE login '.$role);
};
$memberLogin('other');
$data=$resolve();
$check($data['member'] && $data['rows'][0]['targetId']===null,'Logged-in member without calendar rights');
$memberLogin('allowed');
$data=$resolve();
$check($data['member'] && $data['rows'][0]['targetId']===$effectiveId,'Logged-in member with calendar rights');
$db->update('tl_calendar',['protected'=>0],['id'=>$calendarId]);
$db->update('tl_page',['protected'=>1,'groups'=>serialize([$memberGroup])],['id'=>$readerPage]);
$check($resolve()['rows'][0]['targetId']===$effectiveId,'Member with page rights');
$memberLogin('other');
$check($resolve()['rows'][0]['targetId']===null,'Member without page rights');
$cookies=[];$csrf=null;
$check($resolve()['rows'][0]['targetId']===null,'Anonymous protected page');
$db->update('tl_page',['protected'=>0],['id'=>$readerPage]);
$db->update('tl_page',['protected'=>1,'groups'=>serialize([$memberGroup])],['id'=>$rootPage]);
$check($resolve()['rows'][0]['targetId']===null,'Inherited page protection');
$db->update('tl_page',['protected'=>0,'published'=>0],['id'=>$rootPage]);
$check($resolve()['rows'][0]['targetId']===null,'Unpublished root prevents target disclosure');
$db->update('tl_page',['published'=>1],['id'=>$rootPage]);
foreach ([['published'=>0],['published'=>1,'start'=>time()+600],['start'=>'','stop'=>\Contao\Date::floorToMinute()]] as $pageState) {
    $db->update('tl_page',$pageState,['id'=>$readerPage]);
    $check($resolve()['rows'][0]['targetId']===null,'Page publication/window enforced');
}
$db->update('tl_page',['published'=>1,'start'=>'','stop'=>''],['id'=>$readerPage]);
$saved = $db->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?',[$effectiveId]);
$db->delete('tl_calendar_events',['id'=>$effectiveId]);
$check($resolve()['rows'][0]['targetId']===null && (int)$db->fetchOne('SELECT contaoEventId FROM tl_church_tools_entry WHERE id=?',[$re2->id])===$effectiveId,'Deleted target falls back and keeps ID');
$db->insert('tl_calendar_events',$saved);
$check($resolve()['rows'][0]['targetId']===$effectiveId,'Same-ID restoration reactivates');
$db->update('tl_calendar_events',['startDate'=>strtotime('2026-11-20'),'endDate'=>strtotime('2026-11-20'),'startTime'=>strtotime('2026-11-20 10:00'),'endTime'=>strtotime('2026-11-20 11:00')],['id'=>$effectiveId]);
$check($resolve('&from=2026-10-01&until=2026-10-31')['rows']===[], 'Moved effective date excludes original source month');
$check(count($resolve('&from=2026-11-01&until=2026-11-30')['rows'])===1,'Moved effective date included in new month');
// Changing visitors must not reuse another visitor's result, even with backend login cookies.
$login('admin');
$db->update('tl_calendar_events',['published'=>0],['id'=>$effectiveId]);
$check($resolve()['rows'][0]['targetId']===null,'Backend login grants no public preview exception');
// Two visible copies: archive order decides before range filtering.
$db->update('tl_calendar_events', ['published'=>1,'startTime'=>strtotime('2026-09-24 10:00'),'endTime'=>strtotime('2026-09-24 11:00')], ['id'=>$effectiveId]);
$db->insert('tl_calendar_events', array_replace($event,['title'=>'Lower archive winner','startTime'=>strtotime('2026-11-20 10:00'),'endTime'=>strtotime('2026-11-20 11:00')]));
$lowerTarget=(int)$db->lastInsertId();
$db->update('tl_church_tools_entry',['contaoEventId'=>$lowerTarget],['id'=>$re->id]);
$check($resolve('&from=2026-09-01&until=2026-09-30')['rows']===[],'Two visible copies: lower archive wins before filtering; no fallback');
$check($resolve()['rows'][0]['targetId']===$lowerTarget,'Both visible: lowest archive wins');
$db->update('tl_calendar_events',['published'=>0],['id'=>$lowerTarget]);
$check($resolve()['rows'][0]['targetId']===$effectiveId,'Visible higher archive wins over unpublished lower target');
$db->update('tl_church_tools_entry',['contaoEventId'=>null],['id'=>$re->id]);
// Different occurrence of a series stays distinct; ordering uses effective dates.
$source['appointment']['calculated']['iCalUid']=$prefix.'second-occurrence';
$source['appointment']['calculated']['startDate']='2026-09-25T08:00:00.000001Z';
$source['appointment']['calculated']['endDate']='2026-09-25T08:00:00.000001Z';
$second=ChurchToolsEntryModel::saveSource($ra,$source);
$data=$resolve('&from=2026-09-01&until=2026-09-30');
$check(count($data['rows'])===2 && $data['rows'][0]['targetId']===$effectiveId && $data['rows'][1]['id']===(int)$second->id,'Effective date sorting precedes grouping; distinct series UIDs remain');
$check(str_contains($data['rows'][1]['start'],'.000001') && $data['rows'][1]['start']===$data['rows'][1]['end'],'Source point/fraction semantics preserved by resolver');
$db->update('tl_calendar_events',['startTime'=>strtotime('2026-09-26 10:00'),'endTime'=>strtotime('2026-09-26 11:00')],['id'=>$effectiveId]);
$check($resolve('&from=2026-09-01&until=2026-09-30')['rows'][0]['id']===(int)$second->id,'Moved effective target reorders entries');
$db->update('tl_calendar_events',['endTime'=>strtotime('2026-09-26 10:00')],['id'=>$effectiveId]);
$data=$resolve('&from=2026-09-26%2012%3A00&until=2026-09-27');
$check(count($data['rows'])===1 && str_contains($data['rows'][0]['end'],'23:59:59'),'Editorial equal-end target retains core open-end semantics');
// Follow native alternative destinations without leaking protected destination data.
$db->update('tl_calendar_events',['source'=>'external','url'=>'https://example.invalid/editorial'],['id'=>$effectiveId]);
$data=$resolve('&from=2026-09-26&until=2026-09-27');
$check($data['rows'][0]['url']==='https://example.invalid/editorial','Native external event URL (not fetched)');
$db->update('tl_calendar_events',['source'=>'internal','jumpTo'=>$readerPage],['id'=>$effectiveId]);
$check(str_ends_with($resolve('&from=2026-09-26&until=2026-09-27')['rows'][0]['url'],$prefix.'reader.html'),'Native internal event URL');
$db->update('tl_page',['protected'=>1,'groups'=>serialize([$memberGroup])],['id'=>$readerPage]);
$check($resolve('&from=2026-09-26&until=2026-09-27')['rows']===[],'Restricted internal page prevents override');
$db->insert('tl_page',['pid'=>$rootPage,'type'=>'forward','title'=>$prefix.'Forward','alias'=>$prefix.'forward','published'=>1,'jumpTo'=>$readerPage]);
$pages[]=$forwardPage=(int)$db->lastInsertId();
$db->update('tl_calendar_events',['jumpTo'=>$forwardPage],['id'=>$effectiveId]);
$check($resolve('&from=2026-09-26&until=2026-09-27')['rows']===[],'Protected destination behind page forward is checked');
$db->update('tl_page',['protected'=>0],['id'=>$readerPage]);
$db->insert('tl_article',['pid'=>$readerPage,'title'=>$prefix.'Article','alias'=>$prefix.'article','published'=>1,'protected'=>1,'groups'=>serialize([$memberGroup])]);
$articles[]=$articleId=(int)$db->lastInsertId();
$db->update('tl_calendar_events',['source'=>'article','articleId'=>$articleId],['id'=>$effectiveId]);
$check($resolve('&from=2026-09-26&until=2026-09-27')['rows']===[],'Protected article destination prevents override');
$memberLogin('allowed');
$data=$resolve('&from=2026-09-26&until=2026-09-27');
$check(count($data['rows'])===1 && str_contains($data['rows'][0]['url'],'/articles/'.$prefix.'article'),'Authorized article uses actual core URL');
$db->update('tl_article',['published'=>0],['id'=>$articleId]);
$check($resolve('&from=2026-09-26&until=2026-09-27')['rows']===[],'Unpublished article destination prevents override');
