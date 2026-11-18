<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\CtSeriesTemplates;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[AsCallback(table: 'tl_ct_series_templates', target: 'list.operations.delete.button')]
final class ListOperationsDeleteButtonListener
{
    public function __construct(private readonly Connection $connection, private readonly AuthorizationCheckerInterface $authorization) {}

    public function __invoke(DataContainerOperation $operation): void
    {
        $row = $operation->getRecord() ?? [];
        if ($this->connection->fetchOne('SELECT id FROM tl_calendar_events WHERE ct_series_template_id=? LIMIT 1', [$row['id']]) !== false) {
            $operation->hide();
        } elseif (!$this->authorization->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_ct_series_templates', new DeleteAction('tl_ct_series_templates', $row))) {
            $operation->disable();
        }
    }
}
