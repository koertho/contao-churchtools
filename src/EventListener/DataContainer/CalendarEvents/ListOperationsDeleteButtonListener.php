<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\CalendarEvents;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[AsCallback(table: 'tl_calendar_events', target: 'list.operations.delete.button')]
final class ListOperationsDeleteButtonListener
{
    public function __construct(private readonly AuthorizationCheckerInterface $authorization) {}

    public function __invoke(DataContainerOperation $operation): void
    {
        $row = $operation->getRecord() ?? [];
        if (($row['ct_sync_key'] ?? null) !== null) {
            $operation->hide();
        } elseif (!$this->authorization->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_calendar_events', new DeleteAction('tl_calendar_events', $row))) {
            $operation->disable();
        }
    }
}
