<?php
require __DIR__.'/event-runtime.php';
$user=\Contao\BackendUser::loadUserById((int)$argv[4]);
$c->get('security.token_storage')->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user,'contao_backend',$user->getRoles()));
// Widen the write transaction so the two workers exercise the shared lock concurrently.
$c->get('event_dispatcher')->addListener(\Contao\CoreBundle\Event\InvalidateCacheTagsEvent::class, static function () use ($db): void {
    if ($db->getTransactionNestingLevel() > 0) usleep(300000);
});
try {
    $actions->change((int)$argv[2],'create',(int)$argv[3]);
    echo "created-or-existing\n";
} catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) { echo "locked\n"; }
catch (Throwable $e) { echo $e::class."\n"; exit(1); }
