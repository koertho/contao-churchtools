<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Calendar\Backend;

use Contao\CoreBundle\Cache\CacheTagManager;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/** Native copy/cut operations do not invalidate tl_content parent cache tags. */
final class ContentSeriesCache implements ResetInterface
{
    private array $before = [];

    public function __construct(private readonly Connection $connection, private readonly CacheTagManager $cacheTags) {}

    public function capture(int $id): void
    {
        $this->before[$id] = $this->seriesTag($id);
    }

    public function invalidate(int $id): void
    {
        $tags = array_values(array_unique(array_filter([$this->before[$id] ?? null, $this->seriesTag($id)])));
        unset($this->before[$id]);
        $this->cacheTags->invalidateTags($tags);
    }

    private function seriesTag(int $id): ?string
    {
        $seen = [];
        while ($id > 0 && !isset($seen[$id])) {
            $seen[$id] = true;
            $row = $this->connection->fetchAssociative('SELECT ptable,pid FROM tl_content WHERE id=?', [$id]);
            if (!$row) {
                return null;
            }
            if ($row['ptable'] === 'tl_ct_series_templates') {
                return (int) $row['pid'] > 0 ? 'contao.db.tl_ct_series_templates.'.(int) $row['pid'] : null;
            }
            if ($row['ptable'] !== 'tl_content') {
                return null;
            }
            $id = (int) $row['pid'];
        }

        return null;
    }

    public function reset(): void
    {
        $this->before = [];
    }
}
