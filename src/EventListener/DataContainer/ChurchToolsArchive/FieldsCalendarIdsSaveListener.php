<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventListener\DataContainer\ChurchToolsArchive;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Koertho\ChurchToolsBundle\Backend\CalendarSelection;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsCallback(table: 'tl_church_tools_archive', target: 'fields.calendarIds.save')]
final class FieldsCalendarIdsSaveListener
{
    public function __construct(private readonly Connection $connection, private readonly RequestStack $requests)
    {
    }

    public function __invoke(mixed $value, DataContainer $dc): string
    {
        // Core checkboxes submit an explicit empty hidden input when all are unchecked.
        // Missing input is not an explicit deselection (e.g. partial/manual POST).
        if (!$this->requests->getCurrentRequest()?->request->has('calendarIds')) {
            $stored = $this->connection->fetchOne('SELECT calendarIds FROM tl_church_tools_archive WHERE id = ?', [$dc->id]);

            return json_encode(CalendarSelection::decode($stored === false ? null : $stored), JSON_THROW_ON_ERROR);
        }

        return CalendarSelection::fromWidget($value);
    }
}
