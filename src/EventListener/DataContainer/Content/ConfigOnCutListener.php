<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\Content;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Koertho\ContaoChurchTools\Calendar\Backend\ContentSeriesCache;

#[AsCallback(table: 'tl_content', target: 'config.oncut')]
final class ConfigOnCutListener
{
    public function __construct(private readonly ContentSeriesCache $cache) {}

    public function __invoke(DataContainer $dc): void
    {
        $this->cache->invalidate((int) $dc->id);
    }
}
