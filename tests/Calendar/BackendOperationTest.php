<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Tests\Calendar;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\DC_Table;
use Koertho\ContaoChurchTools\EventListener\DataContainer\CalendarEvents\ListOperationsCopyButtonListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class BackendOperationTest extends TestCase
{
    public function testManualCopyPreservesNativeClipboardMethodAndAttributes(): void
    {
        $dc = (new \ReflectionClass(DC_Table::class))->newInstanceWithoutConstructor();
        $operation = new DataContainerOperation('copy', ['href' => 'clipboard=1&mode=copy', 'method' => 'POST', 'attributes' => 'data-controller="native"'], ['id' => 1, 'ct_sync_key' => null], $dc);
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        (new ListOperationsCopyButtonListener($authorization))($operation);
        self::assertNull($operation->getHtml());
        self::assertSame('clipboard=1&mode=copy', $operation['href']);
        self::assertSame('POST', $operation['method']);
        self::assertStringContainsString('native', (string) $operation['attributes']);
    }

    public function testImportedCopyIsHidden(): void
    {
        $dc = (new \ReflectionClass(DC_Table::class))->newInstanceWithoutConstructor();
        $operation = new DataContainerOperation('copy', ['href' => 'clipboard=1&mode=copy', 'method' => 'POST'], ['id' => 1, 'ct_sync_key' => 'key'], $dc);
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        (new ListOperationsCopyButtonListener($authorization))($operation);
        self::assertSame('', $operation->getHtml());
    }
}
