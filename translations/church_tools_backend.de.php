<?php

declare(strict_types=1);

return [
    'calendarUnavailable' => 'Kalenderoptionen sind nicht verfügbar. Gespeicherte Auswahlen bleiben über ihre ID verfügbar und können ausdrücklich abgewählt werden.',
    'never' => 'Noch nie',
    'lastSync' => 'Letzte erfolgreiche Synchronisierung: %date%',
    'removed' => '%count% verknüpfte Quelleinträge wurden bei der letzten erfolgreichen Synchronisierung entfernt. Die Contao-Termine bleiben erhalten. Ursache können auch geänderte Terminidentitäten sein, nicht nur Absagen.',
    'unlinked' => 'Nicht verknüpft',
    'linked' => 'Verknüpft mit Contao-Termin ID %id%',
    'missingTarget' => 'Contao-Termin ID %id% fehlt; die gespeicherte Verknüpfung bleibt erhalten.',
    'linkState' => 'Contao-Verknüpfungsstatus',
    'invalidTags' => 'Die gespeicherten Tag-Metadaten sind ungültig.',
    'yes' => 'Ja',
    'no' => 'Nein',
    'emptyWindow' => 'Ein vollständig leeres Zeitfenster eines Quellkalenders stoppt die Synchronisierung vorsorglich: Ein leerer Kalender lässt sich derzeit nicht von fehlender Sichtbarkeit unterscheiden. Vorhandene Termine und der letzte Erfolgsstatus bleiben erhalten, auch beim Löschen des letzten Termins.',
    'tag.id' => 'ID',
    'tag.name' => 'Name',
    'tag.description' => 'Beschreibung',
    'tag.color' => 'Farbe',
    'actions' => 'Contao-Termin verknüpfen',
    'back' => 'Zurück zu Terminen',
    'linked_id' => 'Gespeicherte Contao-Termin-ID %id%',
    'open_target' => 'Ziel öffnen',
    'unlink' => 'Verknüpfung lösen (Termin behalten)',
    'select_target' => 'Bestehender Termin',
    'link' => 'Termin verknüpfen',
    'select_calendar' => 'Zielkalender',
    'create_target' => 'Unveröffentlichten Termin erstellen und verknüpfen',
    'dates_not_representable' => 'Diese Zeiten lassen sich nicht exakt kopieren: Contao unterstützt einen begrenzten Datumsbereich mit ganzen Sekunden und behandelt identische Start-/Endzeiten als offenes Ende. Bitte den Termin manuell erstellen und ausdrücklich verknüpfen.',
    'title_insert_tags_not_representable' => 'Contao 6 interpretiert {{ in Terminlistentiteln als Insert-Tags. Dieser Quelltitel kann nicht unverändert als Klartext kopiert werden. Bitte den Termin manuell erstellen und ausdrücklich verknüpfen; die Quelle bleibt unverändert.',
    'title_not_representable' => 'Der Core benötigt einen nichtleeren Titel mit höchstens 255 Zeichen. Bitte den Termin manuell erstellen und ausdrücklich verknüpfen.',
];
