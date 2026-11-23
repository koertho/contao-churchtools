<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\Calendar;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Koertho\ContaoChurchTools\Calendar\Backend\BackendActionGuard;

#[AsCallback(table: 'tl_calendar', target: 'config.onload')]
final class ConfigOnLoadListener
{
    public function __construct(private readonly BackendActionGuard $guard) {}

    public function __invoke(?DataContainer $dc = null): void
    {
        $this->guard->guard('tl_calendar', (int) ($dc?->id ?? 0));
    }
}
