<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventListener\Frontend;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class NoStoreListener
{
    #[AsEventListener(priority: -300)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getRequest()->attributes->get('church_tools_dynamic_content')) return;

        $event->getResponse()->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');
    }
}
