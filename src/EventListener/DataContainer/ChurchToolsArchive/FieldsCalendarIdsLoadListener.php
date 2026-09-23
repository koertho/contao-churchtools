<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventListener\DataContainer\ChurchToolsArchive;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Koertho\ChurchToolsBundle\Backend\CalendarSelection;

#[AsCallback(table: 'tl_church_tools_archive', target: 'fields.calendarIds.load')]
final class FieldsCalendarIdsLoadListener
{
    public function __invoke(mixed $value): array
    {
        return CalendarSelection::decode($value);
    }
}
