<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\Frontend;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Koertho\ContaoChurchTools\Calendar\Frontend\EventContentResolver;

#[AsHook('getAllEvents')]
final class GetAllEventsListener
{
    public function __construct(private readonly EventContentResolver $resolver)
    {
    }

    public function __invoke(array $events, mixed ...$arguments): array
    {
        $module = $arguments === [] ? null : $arguments[array_key_last($arguments)];
        foreach ($events as &$day) {
            foreach ($day as &$time) {
                foreach ($time as &$event) {
                    $event = $this->resolver->resolve($event);
                    if (($event['ct_sync_key'] ?? null) !== null && $module instanceof \Contao\Module) {
                        $event['ct_module_image_size'] = $module->imgSize;
                    }
                }
                unset($event);
            }
            unset($time);
        }
        unset($day);

        return $events;
    }
}
