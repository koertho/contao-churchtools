<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Model;

use Contao\Model;
use Koertho\ChurchToolsBundle\Storage\SourceOccurrence;

final class ChurchToolsEntryModel extends Model
{
    protected static $strTable = 'tl_church_tools_entry';

    /**
     * Save one public source occurrence, never local link/status fields from the payload.
     * Concurrent writers must be serialized by the synchronizer; the unique key
     * rejects a racing insert rather than creating a duplicate or guessing a new identity.
     */
    public static function saveSource(int $archiveId, array $row): self
    {
        $fields = SourceOccurrence::fields($row);
        if ($archiveId < 1 || ChurchToolsArchiveModel::findByPk($archiveId) === null) {
            throw new \InvalidArgumentException('An existing archive is required.');
        }
        $entry = static::findOneBy(['tl_church_tools_entry.pid=?', 'tl_church_tools_entry.occurrenceUid=?'], [$archiveId, $fields['occurrenceUid']]);
        if ($entry === null) {
            $entry = new self();
            $entry->pid = $archiveId;
        }
        foreach ($fields as $name => $value) {
            $entry->$name = $value;
        }
        $entry->tstamp = time();
        $entry->save();

        return $entry;
    }
}
