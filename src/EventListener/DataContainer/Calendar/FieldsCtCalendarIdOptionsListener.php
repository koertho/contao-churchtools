<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\Calendar;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Koertho\ContaoChurchTools\Api\ApiException;
use Koertho\ContaoChurchTools\Api\ChurchToolsClient;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCallback(table: 'tl_calendar', target: 'fields.ct_calendar_id.options')]
final class FieldsCtCalendarIdOptionsListener
{
    public function __construct(private readonly ChurchToolsClient $client, private readonly Connection $connection, private readonly TranslatorInterface $translator) {}

    public function __invoke(?DataContainer $dc = null): array
    {
        $id = (int) ($dc?->id ?? 0);
        $stored = $id ? $this->connection->fetchOne('SELECT ct_calendar_id FROM tl_calendar WHERE id=?', [$id]) : null;
        $used = array_map('intval', $this->connection->fetchFirstColumn('SELECT ct_calendar_id FROM tl_calendar WHERE ct_calendar_id IS NOT NULL AND id<>?', [$id]));
        $options = $stored ? [(int) $stored => 'ID '.(int) $stored] : [];
        try {
            foreach ($this->client->calendars() as $calendar) {
                if (!in_array($calendar['id'], $used, true)) {
                    $options[$calendar['id']] = htmlspecialchars($calendar['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').' (ID '.$calendar['id'].')';
                }
            }
        } catch (ApiException) {
            Message::addInfo($this->translator->trans('calendarUnavailable', [], 'church_tools_backend'));
        }

        return $options;
    }
}
