<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\Content;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;

/** The core invalidates only the immediate parent, including for nested content. */
#[AsCallback(table: 'tl_content', target: 'config.oninvalidate_cache_tags')]
final class ConfigOnInvalidateCacheTagsListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(DataContainer $dc, array $tags): array
    {
        $ids = [(int) $dc->id];
        foreach ($tags as $tag) {
            if (preg_match('/^contao\.db\.tl_content\.([1-9][0-9]*)$/D', $tag, $match)) {
                $ids[] = (int) $match[1];
            }
        }
        $seen = [];
        foreach ($ids as $id) {
            while ($id > 0 && !isset($seen[$id])) {
                $seen[$id] = true;
                $row = $this->connection->fetchAssociative('SELECT ptable, pid FROM tl_content WHERE id = ?', [$id]);
                if (!$row) {
                    break;
                }
                if ($row['ptable'] === 'tl_ct_series_templates' && (int) $row['pid'] > 0) {
                    $tags[] = 'contao.db.tl_ct_series_templates.'.(int) $row['pid'];
                    break;
                }
                if ($row['ptable'] !== 'tl_content') {
                    break;
                }
                $id = (int) $row['pid'];
            }
        }

        return array_values(array_unique($tags));
    }
}
