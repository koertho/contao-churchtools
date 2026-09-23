<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventListener\DataContainer\ChurchToolsArchive;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Koertho\ChurchToolsBundle\Api\ApiException;
use Koertho\ChurchToolsBundle\Api\ChurchToolsClient;
use Koertho\ChurchToolsBundle\Backend\CalendarSelection;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCallback(table: 'tl_church_tools_archive', target: 'fields.calendarIds.options')]
final class FieldsCalendarIdsOptionsListener
{
    public function __construct(private readonly ChurchToolsClient $client, private readonly Connection $connection, private readonly TranslatorInterface $translator)
    {
    }

    public function __invoke(?DataContainer $dc = null): array
    {
        $options = [];
        if ($dc?->id) {
            $stored = $this->connection->fetchOne('SELECT calendarIds FROM tl_church_tools_archive WHERE id = ?', [$dc->id]);
            foreach (CalendarSelection::decode($stored === false ? null : $stored) as $id) {
                $options[$id] = 'ID '.$id;
            }
        }
        try {
            foreach ($this->client->calendars() as $calendar) {
                $options[$calendar['id']] = htmlspecialchars($calendar['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').' (ID '.$calendar['id'].')';
            }
        } catch (ApiException) {
            Message::addInfo($this->translator->trans('calendarUnavailable', [], 'church_tools_backend'));
        }

        return $options;
    }
}
