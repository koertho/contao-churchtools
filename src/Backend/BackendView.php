<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Backend;

use Contao\Config;
use Contao\Date;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Plain source data is escaped at the final HTML boundary, including tag colors. */
final class BackendView
{
    public function __construct(private readonly TranslatorInterface $translator, private readonly Connection $connection)
    {
    }

    public function status(array $row): string
    {
        $last = $row['lastSuccessfulSync'] ? Date::parse(Config::get('datimFormat'), (int) $row['lastSuccessfulSync']) : $this->translator->trans('never', [], 'church_tools_backend');
        $html = '<p>'.htmlspecialchars((string) ($this->translator->trans('lastSync', ['%date%' => $last], 'church_tools_backend')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';
        if ($row['lastError'] ?? '') {
            $html .= '<p class="tl_error">'.htmlspecialchars((string) ($row['lastError']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';
        }
        if ((int) ($row['removedLinkedEntries'] ?? 0) > 0) {
            $html .= '<p class="tl_info">'.htmlspecialchars((string) ($this->translator->trans('removed', ['%count%' => $row['removedLinkedEntries']], 'church_tools_backend')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';
        }

        return $html;
    }

    public function linkedState(array $row): string
    {
        $id = $row['contaoEventId'] ?? null;
        if ($id === null) {
            return $this->translator->trans('unlinked', [], 'church_tools_backend');
        }
        // Only existence is relevant here; never reveal target titles/access-protected data.
        $exists = $this->connection->fetchOne('SELECT id FROM tl_calendar_events WHERE id = ?', [$id]);

        return $this->translator->trans($exists ? 'linked' : 'missingTarget', ['%id%' => $id], 'church_tools_backend');
    }

    public function details(array $row): array
    {
        $data = [];
        foreach (['id', 'pid', 'occurrenceUid', 'sourceAppointmentId', 'sourceCalendarId', 'calendarName', 'title', 'description', 'allDay', 'sourceStart', 'sourceEnd'] as $field) {
            $label = $GLOBALS['TL_LANG']['tl_church_tools_entry'][$field][0] ?? $field;
            $value = $row[$field] ?? '';
            if ($field === 'allDay') {
                $value = $this->translator->trans($value ? 'yes' : 'no', [], 'church_tools_backend');
            }
            $data[htmlspecialchars((string) ($label), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')] = new \Twig\Markup(nl2br(htmlspecialchars((string) ($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), 'UTF-8');
        }
        $tags = $row['tags'];
        if (is_resource($tags)) {
            $tags = stream_get_contents($tags);
        }
        try {
            $tags = json_decode((string) $tags, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($tags) || !array_is_list($tags)) {
                throw new \UnexpectedValueException();
            }
            $lines = [];
            foreach ($tags as $tag) {
                if (!is_array($tag)) {
                    throw new \UnexpectedValueException();
                }
                $values = [];
                foreach (['id', 'name', 'description', 'color'] as $field) {
                    if (isset($tag[$field]) && is_scalar($tag[$field])) {
                        $values[] = $this->translator->trans('tag.'.$field, [], 'church_tools_backend').': '.$tag[$field];
                    }
                }
                $lines[] = implode(' | ', $values);
            }
            $value = implode("\n", $lines);
        } catch (\JsonException|\UnexpectedValueException) {
            $value = $this->translator->trans('invalidTags', [], 'church_tools_backend');
        }
        $data[htmlspecialchars((string) ($GLOBALS['TL_LANG']['tl_church_tools_entry']['tags'][0]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')] = new \Twig\Markup(nl2br(htmlspecialchars((string) ($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), 'UTF-8');
        $data[htmlspecialchars((string) ($this->translator->trans('linkState', [], 'church_tools_backend')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')] = new \Twig\Markup(htmlspecialchars((string) ($this->linkedState($row)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'UTF-8');

        return ['tl_church_tools_entry' => [$data]];
    }
}
