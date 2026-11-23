<?php

declare(strict_types=1);

use Contao\ManagerBundle\HttpKernel\ContaoKernel;
use Koertho\ContaoChurchTools\Tests\Calendar\BackendHttpAcceptance;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Filesystem\Filesystem;

// Same file acts as the temporary loopback router; never install it in a public directory.
if (PHP_SAPI === 'cli-server') {
    $project = getenv('CHURCHTOOLS_BACKEND_PROJECT') ?: '';
    if (!is_file($project.'/vendor/autoload.php') || !preg_match('~/churchtools_v2_[a-z0-9_]+(?:\?|$)~D', getenv('CHURCHTOOLS_TEST_DATABASE_URL') ?: '')) {
        http_response_code(500);
        exit;
    }
    require $project.'/vendor/autoload.php';
    ContaoKernel::setProjectDir($project);
    $kernel = new ContaoKernel('backend_http', false);
    $kernel->boot();
    $kernel->getContainer()->get('event_dispatcher')->addListener('kernel.exception', static function ($event): void {
        $error = $event->getThrowable();
        $diagnostic = $error::class.' '.basename($error->getFile()).':'.$error->getLine();
        if ($error->getPrevious()) $diagnostic .= ' previous '.$error->getPrevious()::class.' '.basename($error->getPrevious()->getFile()).':'.$error->getPrevious()->getLine();
        file_put_contents(getenv('CHURCHTOOLS_BACKEND_DIAGNOSTIC'), $diagnostic);
    });
    $request = Symfony\Component\HttpFoundation\Request::createFromGlobals();
    try {
        $response = $kernel->handle($request);
        $response->send();
        $kernel->terminate($request, $response);
    } catch (Throwable $error) {
        http_response_code(500);
        echo 'Backend test kernel failed: '.htmlspecialchars($error::class, ENT_QUOTES, 'UTF-8');
    }
    exit;
}

$project = $argv[1] ?? '';
$url = getenv('CHURCHTOOLS_TEST_DATABASE_URL') ?: '';
if (!is_file($project.'/vendor/autoload.php') || (!str_starts_with($project, '/') || (!preg_match('/^churchtools-v2-[a-zA-Z0-9_-]+$/D', basename($project)) && basename($project) !== 'churchtools-matrix')) || !preg_match('~/churchtools_v2_[a-z0-9_]+(?:\?|$)~D', $url)) throw new RuntimeException('Explicit isolated V2 matrix project and database required.');
require $project.'/vendor/autoload.php';
require_once dirname(__DIR__).'/tests/Calendar/BackendHttpAcceptance.php';
$kernel = ContaoKernel::fromInput($project, new ArrayInput(['--env' => 'prod', '--no-debug' => true]));
$kernel->boot();
$container = $kernel->getContainer();
$db = $container->get('database_connection');
if (!str_starts_with((string) $db->fetchOne('SELECT DATABASE()'), 'churchtools_v2_')) throw new RuntimeException('Refusing a non-test database.');
$container->get('contao.framework')->initialize();
$config = $project.'/config/config_backend_http.yaml';
if (file_exists($config)) throw new RuntimeException('Refusing to replace an existing backend HTTP test configuration.');
$prefix = 'ct_http_'.bin2hex(random_bytes(6));
$sessionDirectory = $project.'/var/'.$prefix;
$diagnostic = $project.'/var/'.$prefix.'.diagnostic';
$fs = new Filesystem();
$fs->mkdir($sessionDirectory, 0700);
file_put_contents($config, "imports:\n  - { resource: config.yaml }\ncontao:\n  localconfig: { timeZone: Europe/Berlin }\n  cron: { web_listener: false }\n  messenger:\n    web_worker: { transports: [] }\n  search:\n    listener: { index: false, delete: false }\nframework:\n  session:\n    save_path: '$sessionDirectory'\n");
$fs->remove($project.'/var/cache/backend_http');
$process = null;
$imageUuid = null;
$imageDirectory = $project.'/files/'.$prefix;
$users = $calendars = $series = [];
$count = 0;
$check = static function (bool $condition, string $message) use (&$count, $diagnostic): void {
    if (!$condition) throw new RuntimeException($message.(is_file($diagnostic) ? ' ['.file_get_contents($diagnostic).']' : ''));
    ++$count;
};
try {
    $fs->mkdir($imageDirectory);
    file_put_contents($imageDirectory.'/.public', '');
    file_put_contents($imageDirectory.'/preview.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
    $imageUuid = Contao\Dbafs::addResource('files/'.$prefix.'/preview.png')->uuid;
    $password = bin2hex(random_bytes(24));
    $db->insert('tl_user', ['username' => $prefix, 'name' => $prefix, 'email' => $prefix.'@example.invalid', 'password' => password_hash($password, PASSWORD_DEFAULT), 'admin' => 1, 'language' => 'en', 'dateAdded' => time(), 'tstamp' => time()]);
    $users[] = $user = (int) $db->lastInsertId();
    $db->insert('tl_calendar', ['title' => $prefix, 'ct_calendar_id' => 4200000010]);
    $calendars[] = $calendar = (int) $db->lastInsertId();
    $db->insert('tl_ct_series_templates', ['title' => $prefix.' series', 'ct_appointment_id' => 4200000011, 'teaser' => '<p>Shared teaser</p>', 'tstamp' => time()]);
    $series[] = $template = (int) $db->lastInsertId();
    $day = (new DateTimeImmutable('2026-10-25', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $db->insert('tl_calendar_events', ['pid' => $calendar, 'title' => $prefix.' source', 'alias' => $prefix, 'author' => 0, 'source' => 'default', 'recurring' => 0, 'addTime' => 1, 'startDate' => $day, 'endDate' => $day, 'startTime' => (new DateTimeImmutable('2026-10-25T02:30:00+02:00'))->getTimestamp(), 'endTime' => (new DateTimeImmutable('2026-10-25T02:30:00+01:00'))->getTimestamp(), 'ct_sync_key' => hash('sha256', $prefix), 'ct_calendar_id' => 4200000010, 'ct_appointment_id' => 4200000011, 'ct_series_template_id' => $template, 'ct_hash' => hash('sha256', 'initial'), 'ct_description' => 'Literal source description', 'teaser' => '<p>Old teaser</p>', 'published' => 0, 'ct_unpublished_by_sync' => '1', 'tstamp' => time()]);
    $event = (int) $db->lastInsertId();
    $db->update('tl_calendar_events', ['ct_image_src' => $imageUuid], ['id' => $event]);
    foreach ([['tl_calendar_events', $event], ['tl_ct_series_templates', $template]] as [$parent, $id]) $db->insert('tl_content', ['ptable' => $parent, 'pid' => $id, 'type' => 'text', 'text' => '<p>'.$prefix.' editorial</p>', 'tstamp' => time()]);

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) throw new RuntimeException('Cannot allocate loopback server socket.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $base = 'http://'.$address;
    $environment = getenv();
    $environment['CHURCHTOOLS_BACKEND_PROJECT'] = $project;
    $environment['CHURCHTOOLS_BACKEND_DIAGNOSTIC'] = $diagnostic;
    $process = proc_open([PHP_BINARY, '-S', $address, __FILE__], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $project, $environment);
    if (!is_resource($process)) throw new RuntimeException('Cannot start loopback backend server.');
    for ($attempt = 0; $attempt < 100; ++$attempt) {
        $ready = @stream_socket_client('tcp://'.$address, $errorCode, $errorMessage, 0.1);
        if ($ready) { fclose($ready); break; }
        usleep(50000);
    }
    $cookies = [];
    $csrf = null;
    $request = static function (string $path, ?array $post = null) use ($base, &$cookies, &$csrf): array {
        if ($csrf !== null && str_contains($path, 'act=')) $path .= '&rt='.rawurlencode($csrf);
        if ($post !== null && !isset($post['REQUEST_TOKEN']) && $csrf !== null) $post['REQUEST_TOKEN'] = $csrf;
        $headers = [];
        $curl = curl_init($base.$path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIE => implode('; ', array_map(static fn ($key, $value) => $key.'='.$value, array_keys($cookies), $cookies)), CURLOPT_HTTPHEADER => ['Accept-Language: en', 'Origin: '.$base, 'Referer: '.$base.$path], CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers, &$cookies): int {
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
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($body === false) throw new RuntimeException('Loopback HTTP transport failed.');
        curl_close($curl);
        if ($status === 200 && preg_match('/name="REQUEST_TOKEN" value="([^"]+)"/', $body, $matches)) $csrf = html_entity_decode($matches[1], ENT_QUOTES);
        return [$status, $body, $headers];
    };
    $follow = static function (array $response) use ($request): array {
        for ($i = 0; $response[0] >= 300 && $response[0] < 400 && $i < 5; ++$i) {
            $location = $response[2]['location'][0];
            $response = $request(parse_url($location, PHP_URL_PATH).(($query = parse_url($location, PHP_URL_QUERY)) ? '?'.$query : ''));
        }
        return $response;
    };
    $login = $request('/contao/login');
    $check($login[0] === 200, 'Native backend login page HTTP '.$login[0]);
    if (!preg_match('/name="REQUEST_TOKEN" value="([^"]+)"/', $login[1], $matches)) throw new RuntimeException('No login CSRF field.');
    $loggedIn = $follow($request('/contao/login', ['FORM_SUBMIT' => 'tl_login', 'REQUEST_TOKEN' => html_entity_decode($matches[1], ENT_QUOTES), 'username' => $prefix, 'password' => $password, 'login' => 1, '_target_path' => base64_encode('/contao?do=calendar'), '_always_use_target_path' => 1]));
    $check($loggedIn[0] === 200 && !str_contains($loggedIn[1], 'class="tl_login_form"'), 'Native admin authentication and backend navigation HTTP '.$loggedIn[0]);
    BackendHttpAcceptance::run($db, $request, $check, $calendar, $event, $template);
} catch (Throwable $error) {
    fwrite(STDERR, 'Backend HTTP acceptance FAIL after '.$count.' checks: '.$error->getMessage().PHP_EOL);
    $failed = true;
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    foreach ($calendars as $id) {
        foreach ($db->fetchFirstColumn('SELECT id FROM tl_calendar_events WHERE pid=?', [$id]) as $eventId) {
            $db->delete('tl_content', ['ptable' => 'tl_calendar_events', 'pid' => $eventId]);
            $db->delete('tl_version', ['fromTable' => 'tl_calendar_events', 'pid' => $eventId]);
        }
        $db->delete('tl_calendar_events', ['pid' => $id]);
        $db->delete('tl_calendar', ['id' => $id]);
    }
    foreach ($series as $id) {
        $db->delete('tl_content', ['ptable' => 'tl_ct_series_templates', 'pid' => $id]);
        $db->delete('tl_version', ['fromTable' => 'tl_ct_series_templates', 'pid' => $id]);
        $db->delete('tl_ct_series_templates', ['id' => $id]);
    }
    foreach ($users as $id) {
        $db->delete('tl_favorites', ['user' => $id]);
        $db->delete('tl_version', ['userid' => $id]);
        $db->delete('tl_undo', ['pid' => $id]);
        $db->delete('tl_trusted_device', ['userId' => $id, 'userClass' => Contao\BackendUser::class]);
        $db->delete('tl_user', ['id' => $id]);
    }
    if ($imageUuid !== null) $db->delete('tl_files', ['uuid' => $imageUuid]);
    $db->delete('tl_files', ['path' => 'files/'.$prefix, 'type' => 'folder']);
    $fs->remove($imageDirectory);
    $db->executeStatement('DELETE FROM tl_log WHERE username LIKE ?', [$prefix.'%']);
    $fs->remove([$sessionDirectory, $diagnostic, $config, $project.'/var/cache/backend_http']);
    $kernel->shutdown();
}
if ($failed ?? false) exit(1);
printf("Backend HTTP acceptance PASS: %d checks; Contao %s; actual login/CSRF/native DC_Table POST; fixtures removed.\n", $count, Composer\InstalledVersions::getPrettyVersion('contao/core-bundle'));
