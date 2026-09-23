<?php

declare(strict_types=1);

use Contao\ManagerBundle\HttpKernel\ContaoKernel;
use Koertho\ChurchToolsBundle\Model\ChurchToolsEntryModel;
use Koertho\ChurchToolsBundle\Tests\Sync\RemoteFixture;
use Symfony\Component\Console\Input\ArrayInput;

$project = $argv[1] ?? '';
$url = getenv('CHURCHTOOLS_TEST_DATABASE_URL') ?: '';
if (!is_file($project.'/vendor/autoload.php') || !preg_match('~/churchtools_step3_[a-z0-9_]+(?:\?|$)~D', $url) || (!str_starts_with($project, '/') || !str_starts_with(basename($project), 'churchtools-matrix'))) {
    throw new RuntimeException('Explicit isolated matrix project/database required.');
}
require $project.'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Sync/RemoteFixture.php';
$kernel = ContaoKernel::fromInput($project, new ArrayInput(['--env' => 'prod', '--no-debug' => true]));
$kernel->boot();
$c = $kernel->getContainer();
$db = $c->get('database_connection');
if (!str_starts_with((string) $db->fetchOne('SELECT DATABASE()'), 'churchtools_step3_')) throw new RuntimeException('Non-test database.');
$c->get('contao.framework')->initialize();
$count = 0;
$check = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) throw new RuntimeException($label.(isset($GLOBALS['ct_state']) && is_file($GLOBALS['ct_state'].'.failure') ? ' ['.file_get_contents($GLOBALS['ct_state'].'.failure').']' : ''));
    ++$count;
};
$prefix = 'ct_backend_'.bin2hex(random_bytes(5));
$users = $groups = $archives = $entries = [];
$process = null;
$calendarId = $eventId = null;
$config = $project.'/config/config_backend_test.yaml';
if (file_exists($config)) throw new RuntimeException('Refusing to overwrite existing backend test configuration.');
$state = $project.'/'.$prefix.'.state';
$GLOBALS['ct_state'] = $state;
$sessionDir = $project.'/var/'.$prefix;
mkdir($sessionDir, 0700, true);
file_put_contents($state, 'online');
file_put_contents($config, "imports:\n  - { resource: config.yaml }\nframework:\n  session:\n    save_path: '$sessionDir'\nservices:\n  church_tools.http_client:\n    class: Symfony\\Component\\HttpClient\\MockHttpClient\n    factory: ['Koertho\\ChurchToolsBundle\\Tests\\Backend\\FixtureClient', create]\n");
$fs = new Symfony\Component\Filesystem\Filesystem();
$fs->remove($project.'/var/cache/backend_test');
// An ephemeral loopback server exercises routing, firewall, login, CSRF, DC_Table and real saves.
$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://'.$address;
$cookies = [];
$csrf = null;
$request = static function (string $path, ?array $post = null) use ($base, &$cookies, &$csrf): array {
    if ($csrf !== null && str_contains($path, "act=")) $path .= "&rt=".rawurlencode($csrf);
    if ($post !== null && !isset($post["REQUEST_TOKEN"]) && $csrf !== null) $post["REQUEST_TOKEN"] = $csrf;
    $headers = [];
    $curl = curl_init($base.$path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIE => implode('; ', array_map(static fn ($k, $v) => $k.'='.$v, array_keys($cookies), $cookies)),
        CURLOPT_HTTPHEADER => ['Accept-Language: en', 'Origin: '.$base, 'Referer: '.$base.$path],
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers, &$cookies): int {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))][] = trim($value);
                if (strtolower($key) === 'set-cookie') {
                    [$pair] = explode(';', trim($value));
                    [$name, $cookie] = explode('=', $pair, 2);
                    $cookies[$name] = $cookie;
                }
            }
            return strlen($line);
        }]);
    if ($post !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $body = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($body === false) throw new RuntimeException('Loopback HTTP transport failed.');
    curl_close($curl);
    if ($code === 200 && preg_match('/name="REQUEST_TOKEN" value="([^"]+)"/', $body, $m)) $csrf = html_entity_decode($m[1], ENT_QUOTES);
    // Never output bodies, request parameters, tokens, cookies or credentials.
    return [$code, $body, $headers];
};
$token = static function (string $html): string {
    if (!preg_match('/name="REQUEST_TOKEN" value="([^"]+)"/', $html, $matches)) throw new RuntimeException('No CSRF field in form.');
    return html_entity_decode($matches[1], ENT_QUOTES);
};
$follow = static function (array $response) use ($request): array {
    for ($i=0; $response[0] >= 300 && $response[0] < 400 && $i < 5; ++$i) {
        $location = $response[2]['location'][0];
        $path = parse_url($location, PHP_URL_PATH).(($q = parse_url($location, PHP_URL_QUERY)) ? '?'.$q : '');
        $response = $request($path);
    }
    return $response;
};
try {
    $password = bin2hex(random_bytes(24));
    foreach (['admin', 'editor', 'reader', 'denied', 'name_only'] as $role) {
        $groupId = null;
        if ($role !== 'admin') {
            $db->insert('tl_user_group', ['name' => $prefix.'_'.$role, 'modules' => serialize($role === 'denied' ? ['undo'] : ['church_tools_events']),
                'alexf' => serialize(match ($role) { 'editor' => ['tl_church_tools_archive::name','tl_church_tools_archive::calendarIds'], 'name_only' => ['tl_church_tools_archive::name'], default => [] }),
                'cud' => serialize(in_array($role, ['editor','name_only'], true) ? ['tl_church_tools_archive::create','tl_church_tools_archive::update'] : [])]);
            $groups[] = $groupId = (int) $db->lastInsertId();
        }
        $db->insert('tl_user', ['username'=>$prefix.'_'.$role, 'name'=>$prefix.'_'.$role, 'email'=>$prefix.'_'.$role.'@example.invalid',
            'password'=>password_hash($password, PASSWORD_DEFAULT), 'admin'=>$role === 'admin' ? 1 : 0, 'language'=>'en', 'inherit'=>'group',
            'groups'=>serialize($groupId ? [$groupId] : []), 'dateAdded'=>time(), 'tstamp'=>time()]);
        $users[] = (int) $db->lastInsertId();
    }
    $db->insert('tl_calendar', ['title'=>$prefix.'Calendar']);
    $calendarId = (int) $db->lastInsertId();
    $db->insert('tl_calendar_events', ['pid'=>$calendarId, 'title'=>$prefix.'Target']);
    $eventId = (int) $db->lastInsertId();
    $eventBefore = $db->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$eventId]);
    $check(!$db->fetchOne('SELECT id FROM tl_calendar_events WHERE id=4294967294'), 'Missing-target fixture ID available');
    foreach (['A','B'] as $suffix) {
        $db->insert('tl_church_tools_archive', ['name'=>$prefix.$suffix, 'calendarIds'=>'[2,77]', 'lastSuccessfulSync'=>1700000000,
            'lastError'=>'<script>error-probe</script>', 'removedLinkedEntries'=>3]);
        $archives[] = (int) $db->lastInsertId();
    }
    $a = $archives[0];
    foreach ([2,77] as $calendar) {
        $row = RemoteFixture::row($prefix.'-'.$calendar);
        $row['appointment']['base']['calendar'] = ['id'=>$calendar,'name'=>'<script>source-probe</script>'];
        $row['appointment']['base']['title'] = $prefix.'title'.$calendar.'<script>title-probe</script>';
        $row['tags'] = [['id'=>1,'name'=>'<script>tag-probe</script>','description'=>"Tag\n<svg onload=alert(1)>",'color'=>'<img src=x onerror=alert(1)>']];
        $entry = ChurchToolsEntryModel::saveSource($a, $row);
        $entries[] = (int) $entry->id;
    }
    $db->update('tl_church_tools_entry', ['contaoEventId'=>4294967294], ['id'=>$entries[0]]);
    $db->update('tl_church_tools_entry', ['contaoEventId'=>$eventId], ['id'=>$entries[1]]);
    $beforeEntries = $db->fetchAllAssociative('SELECT * FROM tl_church_tools_entry WHERE pid = ? ORDER BY id', [$a]);
    $env = getenv();
    $env['CHURCHTOOLS_BACKEND_PROJECT'] = $project;
    $env['CHURCHTOOLS_BACKEND_STATE'] = $state;
    $process = proc_open([PHP_BINARY, '-S', $address, dirname(__DIR__).'/tools/backend-router.php'], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes, $project, $env);
    for ($i=0;$i<100;++$i) {
        $ready = @stream_socket_client('tcp://'.$address, $ec, $em, .1);
        if ($ready) { fclose($ready); break; }
        usleep(50000);
    }
    $login = static function (string $role) use (&$cookies, $request, $follow, $token, $check, $prefix, $password): array {
        $cookies = [];
        $r = $request('/contao/login');
        $check($r[0] === 200, 'Login page HTTP '.$r[0]);
        $r = $follow($request('/contao/login', ['FORM_SUBMIT'=>'tl_login','REQUEST_TOKEN'=>$token($r[1]),'username'=>$prefix.'_'.$role,'password'=>$password,'login'=>1,'_target_path'=>base64_encode($role === 'denied' ? '/contao?do=undo' : '/contao?do=church_tools_events'),'_always_use_target_path'=>1]));
        $check($r[0] === 200 && !str_contains($r[1], 'class="tl_login_form"'), 'Authenticated '.$role.' navigation HTTP '.$r[0]);
        return $r;
    };
    $login('admin');
    $module = '/contao?do=church_tools_events';
    $edit = $module.'&act=edit&id='.$a;
    $list = $module.'&table=tl_church_tools_entry&id='.$a;
    $r = $request($module);
    $check(str_contains($r[1], 'Archives'), 'Archives navigation label');
    $check($r[0] === 200 && str_contains($r[1], 'ChurchTools') && str_contains($r[1], $prefix.'A'), 'Admin archive navigation');
    $check(str_contains($r[1], '&lt;script&gt;error-probe&lt;/script&gt;') && !str_contains($r[1], '<script>error-probe'), 'Archive error escaped');
    $check(str_contains($r[1], '3 linked source entries'), 'Archive removal notice');
    $created = $request($module.'&act=create');
    parse_str(parse_url($created[2]['location'][0] ?? '', PHP_URL_QUERY) ?: '', $createdQuery);
    if (isset($createdQuery['id'])) $archives[] = (int) $createdQuery['id'];
    $check(isset($createdQuery['id']), 'Admin creates archive through real backend');
    $newEdit = $module.'&act=edit&id='.$createdQuery['id'];
    $newForm = $request($newEdit);
    $check($newForm[0] === 200, 'New archive edit form');
    $request($newEdit, ['FORM_SUBMIT'=>'tl_church_tools_archive','REQUEST_TOKEN'=>$token($newForm[1]),'name'=>$prefix.'Created','calendarIds'=>['2'],'save'=>1]);
    $check($db->fetchOne('SELECT calendarIds FROM tl_church_tools_archive WHERE id=?', [$createdQuery['id']]) === '[2]', 'New archive JSON selection saved');
    $r = $request($edit);
    $check($r[0] === 200 && str_contains($r[1], 'Saved calendar'), 'Archive edit calendar discovery');
    $check(str_contains($r[1], '&lt;script&gt;calendar-probe&lt;/script&gt;'), 'Remote calendar option escaped');
    $save = static function (array $extra) use ($request, $edit, $token, $prefix, $check): array {
        $form = $request($edit);
        $check($form[0] === 200, 'Edit form accessible');
        return $request($edit, array_merge(['FORM_SUBMIT'=>'tl_church_tools_archive','REQUEST_TOKEN'=>$token($form[1]),'name'=>$prefix.'A','save'=>1], $extra));
    };
    $save(['calendarIds'=>['2','77']]);
    $check($db->fetchOne('SELECT calendarIds FROM tl_church_tools_archive WHERE id=?', [$a]) === '[2,77]', 'Real widget POST writes JSON');
    file_put_contents($state, 'missing');
    $r = $request($edit);
    $check(str_contains($r[1], 'ID 77') && preg_match('/value="77"[^>]*checked/', $r[1]) === 1, 'Missing API calendar retained and checked');
    file_put_contents($state, 'offline');
    $r = $request($edit);
    $check(str_contains($r[1], 'Calendar options are unavailable') && preg_match('/value="77"[^>]*checked/', $r[1]) === 1, 'Outage shows retained selection');
    $save(['calendarIds'=>['2','77']]);
    $check($db->fetchOne('SELECT calendarIds FROM tl_church_tools_archive WHERE id=?', [$a]) === '[2,77]', 'Outage save preserves JSON selection');
    $save([]);
    $check($db->fetchOne('SELECT calendarIds FROM tl_church_tools_archive WHERE id=?', [$a]) === '[2,77]', 'Missing POST input preserves selection');
    $save(['calendarIds'=>['77']]);
    $check($db->fetchOne('SELECT calendarIds FROM tl_church_tools_archive WHERE id=?', [$a]) === '[77]', 'Explicit subset during outage');
    $save(['calendarIds'=>'']);
    $check($db->fetchOne('SELECT calendarIds FROM tl_church_tools_archive WHERE id=?', [$a]) === '[]', 'Explicit all-unchecked saves empty JSON during outage');
    file_put_contents($state, 'online');
    $save(['calendarIds'=>['77']]);
    $r = $request($list);
    $check($r[0] === 200 && str_contains($r[1], $prefix.'title2') && str_contains($r[1], $prefix.'title77'), 'Child navigation shows only source list');
    $other = $request($module.'&table=tl_church_tools_entry&id='.$archives[1]);
    $check($other[0] === 200 && !str_contains($other[1], $prefix.'title'), 'Parent child list isolation');
    $check(!str_contains($r[1], '<script>title-probe') && str_contains($r[1], '4294967294'), 'List escaped and missing target reference visible');
    $show = $module.'&table=tl_church_tools_entry&act=show&id='.$entries[0];
    $r = $request($show);
    $check($r[0] === 200 && str_contains($r[1], '&lt;script&gt;tag-probe&lt;/script&gt;') && str_contains($r[1], '&lt;svg onload=alert(1)&gt;'), 'Read-only metadata escaped');
    $targetView = $request($module.'&table=tl_church_tools_entry&act=show&id='.$entries[1]);
    $check($targetView[0] === 200 && str_contains($targetView[1], 'Linked to Contao event ID '.$eventId) && !str_contains($targetView[1], $prefix.'Target'), 'Existing target status without disclosing target data');
    $check(str_contains($r[1], 'stored reference is preserved') && !str_contains($r[1], '<img src=x'), 'Missing link retained; tag color not HTML');
    $r = $request($list);
    $r = $follow($request($list, ['FORM_SUBMIT'=>'tl_filters','REQUEST_TOKEN'=>$token($r[1]),'sourceCalendarId'=>'77']));
    $check($r[0] === 200 && str_contains($r[1], $prefix.'title77') && !str_contains($r[1], $prefix.'title2'), 'Real source calendar filter POST');
    foreach (['edit','delete','copy','create','editAll','overrideAll','deleteAll','cut'] as $act) {
        $r = $request($module.'&table=tl_church_tools_entry&act='.$act.'&id='.$entries[0], ['FORM_SUBMIT'=>'tl_church_tools_entry','title'=>'attacker','contaoEventId'=>1]);
        $check($r[0] === 403, 'Entry action denied with valid CSRF (HTTP '.$r[0].'): '.$act);
    }
    $login('editor');
    $save(['calendarIds'=>['77'], 'lastSuccessfulSync'=>1, 'lastError'=>'attacker', 'removedLinkedEntries'=>99, 'contaoEventId'=>1]);
    $row = $db->fetchAssociative('SELECT * FROM tl_church_tools_archive WHERE id=?', [$a]);
    $check($row['calendarIds'] === '[77]' && (int)$row['lastSuccessfulSync'] === 1700000000 && $row['lastError'] === '<script>error-probe</script>' && (int)$row['removedLinkedEntries'] === 3, 'Editor permitted fields only; status injection ignored');
    $login('name_only');
    $r = $request($edit);
    $check(str_contains($r[1], 'Last successful synchronization'), 'Status visible independently of field editing permissions');
    $check(!str_contains($r[1], 'name="calendarIds'), 'Core excluded-field permission hides calendar widget');
    $save(['name'=>$prefix.'Renamed', 'calendarIds'=>['2']]);
    $check($db->fetchOne('SELECT calendarIds FROM tl_church_tools_archive WHERE id=?', [$a]) === '[77]', 'Excluded-field POST injection ignored');
    $check($db->fetchOne('SELECT name FROM tl_church_tools_archive WHERE id=?', [$a]) === $prefix.'Renamed', 'Permitted name field saved');
    $login('reader');
    $r = $request($list);
    $check($r[0] === 200, 'Module-only reader may read entries');
    $r = $request($edit, ['FORM_SUBMIT'=>'tl_church_tools_archive','calendarIds'=>'','name'=>'attacker']);
    $check($r[0] === 403, 'Reader cannot update archive with valid CSRF (HTTP '.$r[0].')');
    $deniedPage = $login('denied');
    $check(!str_contains($deniedPage[1], '?do=church_tools_events'), 'Unauthorized module hidden in navigation');
    foreach ([$module,$edit,$list,$show,$module.'&table=tl_church_tools_archive&act=show&id='.$a, '/contao?do=undo&table=tl_church_tools_entry&act=show&id='.$entries[0]] as $path) {
        $check($request($path)[0] === 403, 'Unauthorized direct GET denied');
        $check($request($path, ['FORM_SUBMIT'=>'tl_church_tools_archive','name'=>'attacker','calendarIds'=>''])[0] === 403, 'Unauthorized direct POST with valid CSRF denied');
    }
    $login('admin');
    $check($request($show, ['FORM_SUBMIT'=>'tl_church_tools_entry','title'=>'attacker','contaoEventId'=>1])[0] === 200, 'Detail POST stays read-only');
    $db->update('tl_user', ['language'=>'de'], ['id'=>$users[0]]);
    $r = $login('admin');
    $check(str_contains($r[1], 'Letzte erfolgreiche Synchronisierung') && str_contains($r[1], 'verknüpfte Quelleinträge'), 'German backend translations');
    $check($db->fetchAllAssociative('SELECT * FROM tl_church_tools_entry WHERE pid=? ORDER BY id', [$a]) === $beforeEntries, 'All source rows/link IDs unchanged after reads and forged writes');
    $check($db->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$eventId]) === $eventBefore, 'Linked core event remains byte-identical');
} catch (Throwable $failure) {
    fwrite(STDERR, 'Backend HTTP acceptance FAIL: '.$failure->getMessage().PHP_EOL);
    $failed = true;
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    foreach ($archives as $id) { $db->delete('tl_church_tools_entry', ['pid'=>$id]); $db->delete('tl_church_tools_archive', ['id'=>$id]); }
    if ($eventId !== null) $db->delete('tl_calendar_events', ['id'=>$eventId]);
    if ($calendarId !== null) $db->delete('tl_calendar', ['id'=>$calendarId]);
    foreach ($users as $id) {
        $db->delete('tl_favorites', ['user'=>$id]);
        $db->delete('tl_version', ['userid'=>$id]);
        $db->delete('tl_undo', ['pid'=>$id]);
        $db->delete('tl_trusted_device', ['userId'=>$id,'userClass'=>Contao\BackendUser::class]);
        $db->delete('tl_user', ['id'=>$id]);
    }
    foreach ($groups as $id) $db->delete('tl_user_group', ['id'=>$id]);
    $db->executeStatement('DELETE FROM tl_log WHERE username LIKE ?', ['%'.$prefix.'%']);
    $fs->remove([$state, $state.'.failure', $sessionDir, $config, $project.'/var/cache/backend_test']);
    $kernel->shutdown();
}

if ($failed ?? false) exit(1);
printf("Backend HTTP acceptance PASS: %d checks; Contao %s; actual login/CSRF/kernel/DC_Table/POST; fixtures removed.\n", $count, Composer\InstalledVersions::getPrettyVersion('contao/core-bundle'));
