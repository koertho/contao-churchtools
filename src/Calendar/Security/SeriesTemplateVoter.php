<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Calendar\Security;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\Security\Voter\DataContainer\AbstractDataContainerVoter;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class SeriesTemplateVoter extends AbstractDataContainerVoter
{
    public function __construct(private readonly Connection $connection, private readonly AuthorizationCheckerInterface $authorization) {}

    protected function getTable(): string
    {
        return 'tl_ct_series_templates';
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        if (!$this->authorization->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'ct_series') || $action instanceof CreateAction) {
            return false;
        }
        if ($action instanceof DeleteAction) {
            return $this->connection->fetchOne('SELECT id FROM tl_calendar_events WHERE ct_series_template_id=? LIMIT 1', [$action->getCurrentId()]) === false;
        }
        if ($action instanceof UpdateAction) {
            foreach (['ct_appointment_id', 'title'] as $field) {
                if (array_key_exists($field, $action->getNew() ?? []) && (string) ($action->getCurrent()[$field] ?? '') !== (string) $action->getNew()[$field]) {
                    return false;
                }
            }
        }

        return true;
    }
}
