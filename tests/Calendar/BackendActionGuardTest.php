<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Tests\Calendar;

use Contao\CoreBundle\DataContainer\ClipboardManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\DriverManager;
use Koertho\ContaoChurchTools\Calendar\Backend\BackendActionGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class BackendActionGuardTest extends TestCase
{
    public function testBulkArchiveCopyUsesClipboardEvenWhenCurrentSelectionDiffers(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE tl_calendar_events (id INTEGER PRIMARY KEY,pid INTEGER,ct_sync_key TEXT)');
        $db->insert('tl_calendar_events', ['id' => 1, 'pid' => 2, 'ct_sync_key' => 'key']);
        $request = new Request(['act' => 'copyAll'], ['IDS' => [3]]);
        $session = new Session(new MockArraySessionStorage());
        $backend = new AttributeBag('contao_backend');
        $backend->setName('contao_backend');
        $session->registerBag($backend);
        $backend->set('CURRENT', ['IDS' => [3]]);
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);
        $clipboard = new ClipboardManager($this->createStub(ContaoFramework::class), $stack);
        $clipboard->setIds('tl_calendar', [2], 'copyAll', false);
        $this->expectException(AccessDeniedException::class);
        (new BackendActionGuard($db, $stack, $clipboard))->guard('tl_calendar', 0);
    }
}
