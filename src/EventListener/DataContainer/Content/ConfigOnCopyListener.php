<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\Content;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Koertho\ContaoChurchTools\Calendar\Backend\ContentSeriesCache;

#[AsCallback(table: 'tl_content', target: 'config.oncopy')]
final class ConfigOnCopyListener
{
    public function __construct(private readonly ContentSeriesCache $cache) {}

    public function __invoke(int $insertId): void
    {
        $this->cache->invalidate($insertId);
    }
}
