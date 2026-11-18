<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\CalendarEvents;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Koertho\ContaoChurchTools\Calendar\Backend\PublicationChanges;

#[AsCallback(table: 'tl_calendar_events', target: 'fields.published.save')]
final class FieldsPublishedSaveListener
{
    public function __construct(private readonly PublicationChanges $changes) {}

    public function __invoke(mixed $value, DataContainer $dc): mixed
    {
        $this->changes->prepare((int) $dc->id, $value);

        return $value;
    }
}
