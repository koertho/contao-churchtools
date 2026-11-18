<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\Content;

use Contao\CoreBundle\DataContainer\ClipboardManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Koertho\ContaoChurchTools\Calendar\Backend\ContentSeriesCache;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsCallback(table: 'tl_content', target: 'config.onload')]
final class ConfigOnLoadListener
{
    public function __construct(private readonly ContentSeriesCache $cache, private readonly RequestStack $requests, #[Autowire(service: 'contao.data_container.clipboard_manager')] private readonly ClipboardManager $clipboard) {}

    public function __invoke(?DataContainer $dc = null): void
    {
        $action = $this->requests->getCurrentRequest()?->query->get('act');
        if (!in_array($action, ['cut', 'cutAll'], true)) {
            return;
        }
        $ids = $action === 'cutAll' ? $this->clipboard->getIds('tl_content') : [(int) ($dc?->id ?? 0)];
        foreach ($ids as $id) {
            if ((int) $id > 0) {
                $this->cache->capture((int) $id);
            }
        }
    }
}
