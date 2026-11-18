<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\Calendar;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Koertho\ContaoChurchTools\Calendar\Backend\CalendarAssignment;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCallback(table: 'tl_calendar', target: 'fields.ct_calendar_id.save')]
final class FieldsCtCalendarIdSaveListener
{
    public function __construct(private readonly CalendarAssignment $assignment, private readonly Connection $connection, private readonly RequestStack $requests, private readonly ?TranslatorInterface $translator = null) {}

    public function __invoke(mixed $value, DataContainer $dc): ?int
    {
        if (!$this->requests->getCurrentRequest()?->request->has('ct_calendar_id')) {
            $stored = $this->connection->fetchOne('SELECT ct_calendar_id FROM tl_calendar WHERE id=?', [$dc->id]);

            return $stored === false || $stored === null ? null : (int) $stored;
        }

        try {
            return $this->assignment->save($value, (int) $dc->id);
        } catch (\InvalidArgumentException $error) {
            throw new \InvalidArgumentException($this->translator?->trans($error->getMessage(), [], 'church_tools_backend') ?? $error->getMessage());
        }
    }
}
