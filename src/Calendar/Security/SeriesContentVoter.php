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

/** A tl_content permission must follow the actual parent, not just the current backend module. */
final class SeriesContentVoter extends AbstractDataContainerVoter
{
    public function __construct(private readonly Connection $connection, private readonly AuthorizationCheckerInterface $authorization) {}

    protected function getTable(): string
    {
        return 'tl_content';
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        $current = $action instanceof CreateAction ? [] : ($action->getCurrent() ?? []);
        if (!$this->hasAccessToParent($current)) {
            return false;
        }
        if ($action instanceof CreateAction || $action instanceof UpdateAction) {
            return $this->hasAccessToParent(array_replace($current, $action->getNew() ?? []));
        }

        return true;
    }

    private function hasAccessToParent(array $row): bool
    {
        $seen = [];
        while (($row['ptable'] ?? null) === 'tl_content') {
            $id = (int) ($row['pid'] ?? 0);
            if ($id < 1 || isset($seen[$id]) || count($seen) > 100) {
                return false;
            }
            $seen[$id] = true;
            $row = $this->connection->fetchAssociative('SELECT pid,ptable FROM tl_content WHERE id=?', [$id]);
            if ($row === false) {
                return false;
            }
        }
        if (($row['ptable'] ?? null) !== 'tl_ct_series_templates') {
            return true;
        }

        return $this->authorization->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'ct_series')
            && $this->connection->fetchOne('SELECT id FROM tl_ct_series_templates WHERE id=?', [(int) ($row['pid'] ?? 0)]) !== false;
    }
}
