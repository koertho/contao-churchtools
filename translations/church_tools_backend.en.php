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
];
