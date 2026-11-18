<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\CtSeriesTemplates;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;

#[AsCallback(table: 'tl_ct_series_templates', target: 'fields.title.save')]
final class FieldsTitleSaveListener
{
    public function __construct(private readonly Connection $connection) {}

    public function __invoke(mixed $value, DataContainer $dc): mixed
    {
        $original = $this->connection->fetchOne('SELECT title FROM tl_ct_series_templates WHERE id=?', [$dc->id]);

        return $original === false ? $value : $original;
    }
}
