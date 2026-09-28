<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventIntegration;

use Contao\Controller;
use Contao\CoreBundle\Security\ContaoCorePermissions as Permissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class BackendAccess
{
    public function __construct(private readonly AuthorizationCheckerInterface $authorization) {}

    public function entry(array $entry): void
    {
        Controller::loadDataContainer('tl_church_tools_entry');
        if (!$this->authorization->isGranted(Permissions::USER_CAN_ACCESS_MODULE, 'church_tools_events')
            || !$this->authorization->isGranted(Permissions::DC_PREFIX.'tl_church_tools_entry', new ReadAction('tl_church_tools_entry', $entry))
            || !$this->authorization->isGranted(Permissions::USER_CAN_EDIT_FIELD_OF_TABLE, 'tl_church_tools_entry::contaoEventId')) {
            throw new AccessDeniedException('ChurchTools link permission required.');
        }
    }

    public function target(array $event): bool
    {
        Controller::loadDataContainer('tl_calendar_events');
        return $this->authorization->isGranted(Permissions::DC_PREFIX.'tl_calendar_events', new ReadAction('tl_calendar_events', $event))
            && $this->authorization->isGranted(Permissions::DC_PREFIX.'tl_calendar_events', new UpdateAction('tl_calendar_events', $event));
    }

    public function calendar(array $calendar): bool
    {
        Controller::loadDataContainer('tl_calendar');
        Controller::loadDataContainer('tl_calendar_events');
        Controller::loadDataContainer('tl_content');
        return $this->authorization->isGranted(Permissions::DC_PREFIX.'tl_calendar', new ReadAction('tl_calendar', $calendar))
            && $this->authorization->isGranted(Permissions::DC_PREFIX.'tl_calendar_events', new CreateAction('tl_calendar_events', ['pid' => $calendar['id']]));
    }

    public function content(int $eventId): bool
    {
        Controller::loadDataContainer('tl_content');
        return $this->authorization->isGranted(Permissions::USER_CAN_ACCESS_ELEMENT_TYPE, 'text')
            && $this->authorization->isGranted(Permissions::USER_CAN_EDIT_FIELD_OF_TABLE, 'tl_content::text')
            && $this->authorization->isGranted(Permissions::DC_PREFIX.'tl_content', new CreateAction('tl_content', ['pid' => $eventId, 'ptable' => 'tl_calendar_events', 'type' => 'text']));
    }
}
