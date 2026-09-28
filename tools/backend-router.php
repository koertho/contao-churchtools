<?php

declare(strict_types=1);

// Test-only entrypoint; never installed as a public controller.
$project = getenv('CHURCHTOOLS_BACKEND_PROJECT');
if (!$project || !preg_match('~/churchtools_step3_[a-z0-9_]+(?:\?|$)~D', getenv('CHURCHTOOLS_TEST_DATABASE_URL') ?: '')) {
    http_response_code(500);
    exit;
}
require $project.'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Backend/FixtureClient.php';
require dirname(__DIR__).'/tests/EventIntegration/ResolverController.php';
\Contao\ManagerBundle\HttpKernel\ContaoKernel::setProjectDir($project);
$kernel = new \Contao\ManagerBundle\HttpKernel\ContaoKernel('backend_test', false);
$kernel->boot();
$kernel->getContainer()->get('event_dispatcher')->addListener('kernel.exception', static function ($event): void {
    $e = $event->getThrowable();
    $diagnostic = $e::class.' '.basename($e->getFile()).':'.$e->getLine();
    if ($e->getPrevious()) $diagnostic .= ' previous: '.$e->getPrevious()::class.' '.basename($e->getPrevious()->getFile()).':'.$e->getPrevious()->getLine();
    if (preg_match('/churchtools_step3_[a-z0-9_]+[.]tl_[a-z0-9_]+/', $e->getMessage(), $m)) $diagnostic .= ' '.$m[0];
    if (str_contains(strtolower($e->getMessage()), 'token')) $diagnostic .= ' token';
    file_put_contents(getenv('CHURCHTOOLS_BACKEND_STATE').'.failure', $diagnostic);
});
$request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
try {
    $response = $kernel->handle($request);
    $response->send();
    $kernel->terminate($request, $response);
} catch (\Throwable $e) {
    // No request/session/credential dump, no raw exception trace.
    http_response_code(500);
    echo 'Backend kernel failure: '.htmlspecialchars($e::class);
}
