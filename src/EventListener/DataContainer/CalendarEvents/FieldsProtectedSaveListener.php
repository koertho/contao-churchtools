<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\CalendarEvents;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Koertho\ContaoChurchTools\Calendar\Backend\EventProtection;

#[AsCallback(table: 'tl_calendar_events', target: 'fields.pid.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.title.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.addTime.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.startDate.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.endDate.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.startTime.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.endTime.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.location.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.address.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.recurring.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.repeatEach.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.recurrences.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.repeatEnd.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.source.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.ct_sync_key.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.ct_calendar_id.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.ct_appointment_id.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.ct_series_template_id.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.ct_hash.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.ct_last_sync.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.ct_unpublished_by_sync.save', priority: -1024)]
#[AsCallback(table: 'tl_calendar_events', target: 'fields.ct_description.save', priority: -1024)]
final class FieldsProtectedSaveListener
{
    public function __construct(private readonly EventProtection $protection) {}

    public function __invoke(mixed $value, DataContainer $dc): mixed
    {
        $row = $this->protection->record((int) $dc->id);
        if ($row === null) {
            return $value;
        }
        $field = (string) $dc->field;
        if (in_array($field, EventProtection::SYNC_FIELDS, true) || ($row['ct_sync_key'] ?? null) !== null) {
            return $row[$field] ?? null;
        }

        return $value;
    }
}
