<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Calendar\Security;

use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\Security\Voter\DataContainer\AbstractDataContainerVoter;
use Koertho\ContaoChurchTools\Calendar\Backend\EventProtection;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/** Adds source protection without replacing Contao's native calendar permissions. */
final class ImportedEventVoter extends AbstractDataContainerVoter
{
    protected function getTable(): string
    {
        return 'tl_calendar_events';
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        if ($action instanceof ReadAction) {
            return true;
        }
        if ($action instanceof CreateAction) {
            foreach (EventProtection::SYNC_FIELDS as $field) {
                if (!empty($action->getNew()[$field])) {
                    return false;
                }
            }

            return true;
        }
        $current = $action->getCurrent();
        if ($action instanceof DeleteAction) {
            return ($current['ct_sync_key'] ?? null) === null;
        }
        foreach ($action->getNew() ?? [] as $field => $value) {
            $protected = in_array($field, EventProtection::SYNC_FIELDS, true)
                || (($current['ct_sync_key'] ?? null) !== null && in_array($field, EventProtection::SOURCE_FIELDS, true));
            // The manual published callback alone clears this bookkeeping flag.
            if ($field === 'ct_unpublished_by_sync' && ($value === '' || $value === null)) {
                continue;
            }
            if ($protected && (string) ($current[$field] ?? '') !== (string) $value) {
                return false;
            }
        }

        return true;
    }
}
