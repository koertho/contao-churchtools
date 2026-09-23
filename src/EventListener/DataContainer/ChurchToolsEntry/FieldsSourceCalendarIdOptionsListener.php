<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventListener\DataContainer\ChurchToolsEntry;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Doctrine\DBAL\Connection;

#[AsCallback(table: 'tl_church_tools_entry', target: 'fields.sourceCalendarId.options')]
final class FieldsSourceCalendarIdOptionsListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(): array
    {
        $options = [];
        foreach ($this->connection->fetchAllAssociative('SELECT DISTINCT sourceCalendarId, calendarName FROM tl_church_tools_entry ORDER BY sourceCalendarId, calendarName') as $row) {
            $options[$row['sourceCalendarId']] = htmlspecialchars($row['calendarName'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').' (ID '.$row['sourceCalendarId'].')';
        }

        return $options;
    }
}
