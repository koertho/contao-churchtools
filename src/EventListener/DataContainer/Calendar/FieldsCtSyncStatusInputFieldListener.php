<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\Calendar;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCallback(table: 'tl_calendar', target: 'fields.ct_sync_status.input_field')]
final class FieldsCtSyncStatusInputFieldListener
{
    public function __construct(private readonly Connection $connection, private readonly TranslatorInterface $translator) {}

    public function __invoke(DataContainer $dc): string
    {
        $row = $this->connection->fetchAssociative('SELECT ct_last_sync,ct_sync_error,ct_sync_counts FROM tl_calendar WHERE id=?', [$dc->id]);
        $status = $row && $row['ct_last_sync'] ? date('Y-m-d H:i:s', (int) $row['ct_last_sync']) : $this->translator->trans('neverSynced', [], 'church_tools_backend');
        $error = $row['ct_sync_error'] ?? '';

        $counts = json_decode($row['ct_sync_counts'] ?? '', true);
        $summary = [];
        foreach (['inserted', 'updated', 'unchanged', 'unpublished', 'reactivated', 'seriesUpdated'] as $key) {
            if (is_array($counts) && isset($counts[$key]) && is_int($counts[$key]) && $counts[$key] >= 0) {
                $summary[] = htmlspecialchars($this->translator->trans($key, [], 'church_tools_backend'), ENT_QUOTES, 'UTF-8').': '.$counts[$key];
            }
        }

        return '<div class="tl_help tl_full"><strong>'.htmlspecialchars($status, ENT_QUOTES, 'UTF-8').'</strong><p>'.implode(' · ', $summary).'</p>'.($error ? '<p>'.htmlspecialchars($error, ENT_QUOTES, 'UTF-8').'</p>' : '').'<p>'.htmlspecialchars($this->translator->trans('emptyWindow', [], 'church_tools_backend'), ENT_QUOTES, 'UTF-8').'</p></div>';
    }
}
