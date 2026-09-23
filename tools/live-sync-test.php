<?php

declare(strict_types=1);

// Explicit opt-in read-only remote probe. Local writes only inside a rolled-back
// transaction on an already-provisioned isolated matrix DB. Never boot the host.
use Contao\ManagerBundle\HttpKernel\ContaoKernel;
use Koertho\ChurchToolsBundle\Api\ChurchToolsClient;
use Koertho\ChurchToolsBundle\Api\Transport;
use Koertho\ChurchToolsBundle\Model\ChurchToolsArchiveModel;
use Koertho\ChurchToolsBundle\Sync\SnapshotFetcher;
use Koertho\ChurchToolsBundle\Sync\Synchronizer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;

$kernel = null;
$db = null;
$transaction = false;
try {
    $project = $argv[1] ?? '';
    $url = getenv('CHURCHTOOLS_TEST_DATABASE_URL') ?: '';
    $envFile = getenv('CHURCHTOOLS_LIVE_ENV_FILE') ?: '';
    $origin = getenv('CHURCHTOOLS_LIVE_ORIGIN') ?: '';
    $calendarId = (int) (getenv('CHURCHTOOLS_LIVE_CALENDAR') ?: 0);
    if (!is_file($project.'/vendor/autoload.php') || !preg_match('~/churchtools_step3_[a-z0-9_]+(?:\?|$)~D', $url)
        || $envFile === '' || $origin === '' || $calendarId < 1) {
        throw new RuntimeException('Explicit isolated project, database and live inputs required.');
    }
    require $project.'/vendor/autoload.php';
    $kernel = ContaoKernel::fromInput($project, new ArrayInput(['--env' => 'prod', '--no-debug' => true]));
    $kernel->boot();
    $container = $kernel->getContainer();
    $db = $container->get('database_connection');
    if (!str_starts_with((string) $db->fetchOne('SELECT DATABASE()'), 'churchtools_step3_')) {
        throw new RuntimeException('Non-test database refused.');
    }
    $container->get('contao.framework')->initialize();
    // Standard process-internal Symfony environment loading; no file inspection/output.
    (new Dotenv())->loadEnv($envFile);
    $client = new ChurchToolsClient(new Transport(HttpClient::create(), $origin), $_ENV['CT_TOKEN'] ?? '');
    $sync = new Synchronizer(new SnapshotFetcher($client), $db, $container->get('contao.framework'), $container->get('contao.cache.tag_manager'));
    $db->beginTransaction();
    $transaction = true;
    $archive = new ChurchToolsArchiveModel();
    $archive->name = 'Isolated live acceptance';
    $archive->setCalendarIds([$calendarId]);
    $archive->save();
    $id = (int) $archive->id;
    for ($run = 1; $run <= 2; ++$run) {
        $result = $sync->synchronize($id)[$id];
        if ($result['status'] !== 'success') {
            // Fixed diagnostics only; no raw exception, URL, credential or response output.
            echo json_encode(['run' => $run, 'success' => false, 'diagnostic' => $result['error'] ?? 'Synchronization unavailable.'], JSON_THROW_ON_ERROR)."\n";
            throw new RuntimeException('Live acceptance failed.');
        }
        unset($result['status']);
        echo json_encode(['run' => $run] + $result, JSON_THROW_ON_ERROR)."\n";
    }
    echo 'Live aggregate acceptance PASS; local fixture transaction will be rolled back.'."\n";
} catch (Throwable) {
    echo "Live aggregate acceptance FAILED; no sensitive details emitted.\n";
    $failed = true;
} finally {
    if ($transaction) {
        // Closing the dedicated connection also rolls back after server-side deadlock
        // aborts, where a nested savepoint may already have ceased to exist.
        $db->close();
    }
    $kernel?->shutdown();
}
exit(isset($failed) ? 1 : 0);
