<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Model;

use Contao\Model;

final class ChurchToolsArchiveModel extends Model
{
    protected static $strTable = 'tl_church_tools_archive';

    /** Store an explicit selection; API discovery never implicitly replaces it. */
    public function setCalendarIds(array $ids): void
    {
        if (!array_is_list($ids)) {
            throw new \InvalidArgumentException('Calendar selection must be a list.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || $id > 4294967295) {
                throw new \InvalidArgumentException('Calendar IDs must be positive unsigned integers.');
            }
        }
        $this->calendarIds = json_encode(array_values(array_unique($ids)), JSON_THROW_ON_ERROR);
    }
}
