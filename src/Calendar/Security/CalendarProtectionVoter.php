<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Calendar\Security;

use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\Security\Voter\DataContainer\AbstractDataContainerVoter;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class CalendarProtectionVoter extends AbstractDataContainerVoter
{
    public function __construct(private readonly Connection $connection) {}

    protected function getTable(): string
    {
        return 'tl_calendar';
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        if (!$action instanceof DeleteAction) {
            return true;
        }

        return $this->connection->fetchOne('SELECT id FROM tl_calendar_events WHERE pid=? AND ct_sync_key IS NOT NULL LIMIT 1', [$action->getCurrentId()]) === false;
    }
}
