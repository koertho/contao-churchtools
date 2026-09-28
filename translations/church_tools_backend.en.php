<?php

declare(strict_types=1);

return [
    'calendarUnavailable' => 'Calendar options are unavailable. Stored selections remain available by ID and can be explicitly deselected.',
    'never' => 'Never',
    'lastSync' => 'Last successful synchronization: %date%',
    'removed' => '%count% linked source entries were removed in the last successful synchronization. Contao events were preserved. This may include changed occurrence identities; it does not necessarily mean cancellation.',
    'unlinked' => 'Not linked',
    'linked' => 'Linked to Contao event ID %id%',
    'missingTarget' => 'Contao event ID %id% is missing; the stored reference is preserved.',
    'linkState' => 'Contao link status',
    'invalidTags' => 'Stored tag metadata is invalid.',
    'yes' => 'Yes',
    'no' => 'No',
    'emptyWindow' => 'An entirely empty remote calendar window stops synchronization as a precaution: an empty calendar cannot currently be distinguished from lost visibility. Existing entries and the last successful status are retained, including when the last occurrence was deleted.',
    'tag.id' => 'ID',
    'tag.name' => 'Name',
    'tag.description' => 'Description',
    'tag.color' => 'Color',
    'actions' => 'Contao event link',
    'back' => 'Back to appointments',
    'linked_id' => 'Stored Contao event ID %id%',
    'open_target' => 'Open target',
    'unlink' => 'Unlink (keep event)',
    'select_target' => 'Existing event',
    'link' => 'Link event',
    'select_calendar' => 'Target calendar',
    'create_target' => 'Create and link unpublished event',
    'dates_not_representable' => 'Cannot copy these dates exactly: Contao supports a limited date range with whole seconds and treats identical timed endpoints as open-ended. Create an event manually and link it explicitly.',
    'title_insert_tags_not_representable' => 'Contao 6 interprets {{ in event list titles as insert tags. This source title cannot be copied unchanged as plaintext. Create an event manually and link it explicitly; the source remains unchanged.',
    'title_not_representable' => 'The core requires a nonempty title with at most 255 characters. Create an event manually and link it explicitly.',
];
