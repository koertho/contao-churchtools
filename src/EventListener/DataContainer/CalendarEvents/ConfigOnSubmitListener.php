<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\CalendarEvents;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Koertho\ContaoChurchTools\Calendar\Backend\EventProtection;
use Koertho\ContaoChurchTools\Calendar\Backend\PublicationChanges;

#[AsCallback(table: 'tl_calendar_events', target: 'config.onsubmit', priority: -1024)]
final class ConfigOnSubmitListener
{
    public function __construct(private readonly EventProtection $protection, private readonly PublicationChanges $publication) {}

    public function __invoke(DataContainer $dc): void
    {
        $this->protection->restore((int) $dc->id);
        $this->publication->commit((int) $dc->id);
    }
}
