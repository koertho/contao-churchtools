# Prompt: Schritt 3 implementieren

Implementiere ausschließlich Schritt 3 der ChurchTools-Erweiterung: Archive und lokale Terminspeicherung mit genau zwei Tabellen, Contao-DCA, Contao-Modellen und geprüften Speicheroperationen. Schritt 2 ist implementiert und lokal geprüft; baue auf dem vorhandenen Code auf.

## Verbindliche Orte und Grundlagen

- Bundle: `/home/dev/Kunden/github/contao-churchtools`
- Composername: `koertho/contao-churchtools`
- Planung und Fixtures: `.docs/church_tools/` im Bundle
- Host: `/home/dev/Kunden/privat/jzm-contao`, DDEV-Projekt `jzm`
- Unterstützung: PHP 8.4+, Contao 5.7 und 6

Lies die geltenden AGENTS.md-Dateien und gegebenenfalls AGENTS.local.md sowie README.md, requirements.md, architecture.md, steps.md, den aktuellen API-Vertrag und step-2-validation.md im Planungsverzeichnis. Historische Untersuchungen sind Belege, keine konkurrierenden Arbeitsaufträge. Prüfe vorhandenen Code und Arbeitsstand, bewahre fremde Änderungen. Keine Commits, Pushes oder Veröffentlichung.

Der bestehende API-Client bleibt für HTTP, Authentifizierung und Antwortvalidierung zuständig. Für Schritt 3 ist kein Umbau seiner öffentlichen Schnittstelle vorgesehen. Sein Ergebnis ist kein Nachweis für einen vollständig und sicher löschbaren Synchronisationsbestand. Synchronisationslogik folgt in Schritt 4.

## Tabellen und DCA

Lege die konkreten Tabellen- und Feldnamen anhand der Paketkonventionen fest und dokumentiere sie. Verwende genau zwei Erweiterungstabellen:

1. **Archiv:** ID und nötige lokale Verwaltungsfelder, Name, ausgewählte ChurchTools-Kalender-IDs als Blob, letzter erfolgreicher Synchronisationszeitpunkt, letzter Fehler sowie Zähler der beim letzten erfolgreichen Lauf wegen Absage/Quelllöschung entfernten verknüpften Einträge. Noch keine Statusänderungslogik oder Fehlerausgaben mit Remote-Payloads implementieren.
2. **Eintrag:** ID, Archivzuordnung über `pid`, ChurchTools-Occurrence-UID, veränderliche Quelltermin- und Kalender-ID, Kalendername, Titel, Beschreibung, gegebenenfalls verifizierte Orts-/Linkdaten, Beginn/Ende mit eindeutiger Zeitzonen-/Ganztagssemantik, Tags/Kategorie-Metadaten als Blob und eine nullable Contao-Event-ID direkt am Eintrag.

Nutze Doctrine-Schemadefinitionen in den DCA-Feldern. Setze einen Datenbank-Unique-Key auf `(pid, occurrenceUid)` beziehungsweise die entsprechend gewählten Feldnamen. Die vollständige UID muss ohne Abschneiden gespeichert und eindeutig verglichen werden können. Wähle begründete Indizes für Archiv-, Quellkalender- und Datumsabfragen, ohne jede denkbare Feldkombination zu indexieren.

Definiere die Contao-Parent/Child-Beziehung und die grundlegende DCA-Navigation Archiv → Einträge. Quellfelder dürfen keine redaktionelle Bearbeitung ermöglichen. Halte die dafür benötigte DCA-Konfiguration klein; das vollständige Backend-Modul, API-Kalenderauswahl, Filter, Hinweise und Link-/Create-Aktionen bleiben Schritt 5 beziehungsweise 6 vorbehalten.

Keine zusätzlichen Kalender-, Zuordnungs-, Taxonomie-, Status-, Mapping- oder Tombstone-Tabellen. Keine pauschale Speicherung vollständiger API-Antworten. Globale Verbindungskonfiguration und Synchronisationsfenster bleiben in der bestehenden Bundle-Konfiguration.

## Datenkontrakt und Speicherung

- Identität ist ausschließlich `(pid, calculated.iCalUid)`. Quelltermin-ID, Kalender-ID und Datum sind veränderliche Quelldaten. Dieselbe UID darf in verschiedenen Archiven mit unabhängigen Contao-Verknüpfungen existieren.
- Implementiere tatsächliche `Contao\Model`-Unterklassen unter `src/Model/` und registriere sie in `$GLOBALS['TL_MODELS']`. Füge gezielte Speicher-/Abfrageoperationen hinzu, soweit für diesen Schritt nötig. Keine generische Repository-Hierarchie oder trivialen Delegationswrapper.
- Wiederholtes Speichern derselben Archiv-/UID-Kombination erzeugt keinen zweiten Eintrag. Aktualisierungen von Quelldaten erhalten die bestehende Contao-Event-ID. Trenne erlaubte Quellfelder ausdrücklich von lokal gepflegten Feldern, statt vollständige Arrays ungeprüft zu übernehmen.
- Ein fehlendes Contao-Ziel führt nicht zum Leeren der ID. Ein Fremdschlüssel mit `SET NULL` oder Löschkaskade zum Contao-Event wäre damit unvereinbar. Das Löschen eines lokalen Eintrags verändert oder löscht niemals den Contao-Termin. Eine neu angelegte UID übernimmt keine alte Verknüpfung anhand ähnlicher Titel oder Daten.
- Definiere Blob-Format und erlaubte Schlüssel ausdrücklich. Kalenderauswahl enthält IDs; Tags enthalten ID, Name, nullable Beschreibung und Farbe. Zusätzliche Kategorie-/Kalendermetadaten nur bei verifiziertem Bedarf mit benannten Feldern. Keine spekulativen Kategorien erfinden.
- Remote `meta`, `onBehalfOfPid`, `signup`, `image`, Buchungen, Meeting-Requests und komplette Quellobjekte werden nicht persistiert. Interne Termine sind nicht speicherberechtigt. Die Verarbeitung ihres Sichtbarkeitswechsels und deren Löschung folgen im Synchronizer, nicht in diesem Schritt.
- Beschreibung ist Plaintext, auch wenn sie händisch eingetragenes HTML oder Markdown enthält. Speicher sie verlustfrei als Text; Interpretation, Frontend-Ausgabe und Übernahme in Contao folgen später. Das separate Linkfeld ist kein Beweis für einen ChurchTools-Deep-Link.
- Lege die interne Datumsdarstellung fest und dokumentiere sie: zeitgebundene Werte sind Instants mit Quell-Offset/Zeitzonenbezug, ganztägige Werte Kalenderdaten mit inklusive geliefertem Enddatum. Bei intern exklusivem Ende muss die Umwandlung eindeutig sein. Keine feste Addition von 86400 Sekunden für lokale Kalendertage. Noch keine Abbildung auf `tl_calendar_events` implementieren.

Prüfe direkte Composer-Abhängigkeiten anhand der tatsächlich verwendeten Klassen/DCA-Beziehungen. Wenn die Kalenderintegration eine direkte Abhängigkeit auf `contao/calendar-bundle` benötigt, deklariere sie kompatibel mit beiden Zielversionen und berücksichtige sie in der Matrix. Keine breiten Dependency-Updates.

## Projektregeln und Umfangsgrenze

Verwende Symfony-PHP-Übersetzungen. Registriere eventuell nötige DCA-Callbacks ausschließlich mit `#[AsCallback]`, eine Klasse pro Callback unter `src/EventListener/DataContainer/<Table>/`; keine Callback-Arrays oder Listener-Tags. Kein eigenes `targetColumn` für virtuelle Felder. Beachte die vorhandenen Regeln gegen unnötige Wrapper und Contao-reservierte Namen.

Noch keinen API-Synchronisationslauf, Cronjob, Sync-CLI, Absence-Cleanup, Retention, Cache-Invalidierung, Contao-Event-Erzeugung, Display-Resolver, Frontend-Inhaltselemente oder SSO implementieren. Der Live-Test für Berechtigungsverlust wurde ausdrücklich übersprungen und ist keine Voraussetzung dieses Schritts. Keine neuen Live-Testtermine oder Remote-Änderungen verlangen.

Die Host-Datei `.env.local` darf nicht inspiziert oder ausgegeben werden. Für diesen Schritt sind keine echten ChurchTools-Zugangsdaten erforderlich. Den bestehenden Testcontroller, Token und die Token-Einrichtung unverändert lassen.

## Abnahme und Tests

Nutze DDEV `jzm` für lokale PHP-/Composer-Prüfungen. Verwende für Datenbanktests isolierte Testdatenbanken beziehungsweise getrennte Testinstallationen. Keine produktiven Archiv-/Terminbestände löschen und keine pauschalen Host-Schemaänderungen ausführen. Prüfe vorgeschlagene Schemaänderungen vor deren Anwendung; führe keine fremden Migrationen als Nebeneffekt aus.

Prüfe mit einer echten, Contao-kompatiblen Datenbank und den verfügbaren Contao-5.7-/6-Testumgebungen:

- Schema-Installation und erneuter Schemaabgleich ohne weitere Änderungen; vorhandene Zeilen bleiben beim erneuten Abgleich erhalten.
- Modellregistrierung, Archiv-/Eintrag-Roundtrips und grundlegende Parent/Child-Konfiguration beziehungsweise Navigation.
- Datenbankseitige Eindeutigkeit pro Archiv sowie erlaubte gleiche UID in unterschiedlichen Archiven.
- Wiederholtes Speichern und ein Umzug mit gleichbleibender UID, aber geänderter Quelltermin-ID/Datum: dieselbe lokale Zeile und unveränderte Contao-Event-ID.
- Unabhängige Verknüpfungen überlappender Archive, nullable und nicht mehr auflösbare Ziel-IDs, Erhalt von Contao-Events beim Löschen lokaler Einträge.
- Kalenderauswahl-/Tag-Blob-Roundtrips einschließlich leerer Werte und nullable Tag-Beschreibung; ausgeschlossene Remote-Felder gelangen nicht in den gespeicherten Datensatz.
- Verlustfreie Plaintext-Speicherung sowie die gewählte Datumsdarstellung für zeitgebundene und ganztägige, ein- und mehrtägige Termine einschließlich einer DST-Grenze.

Verwende kontrollierte Daten und vorhandene Fixtures; baue dafür keinen vollständigen Synchronizer. Führe die bestehenden Tests aus, damit der API-Client und die Token-Einrichtung weiterhin funktionieren. Halte tatsächliche PHP-/Contao-/Symfony-/Datenbankversionen und Ergebnisse fest. Unausführbare Prüfungen und nur konfigurierte, aber nicht ausgeführte CI klar als ungeprüft kennzeichnen.

## Dokumentation und Abschluss

Dokumentiere das endgültige Schema samt Feldtypen, Blob-Strukturen, Unique-Key, Indizes und Datumskonvention in architecture.md. Aktualisiere steps.md anhand tatsächlich erfüllter Kriterien und lege step-3-validation.md mit reproduzierbaren Prüfungen und Grenzen an. Markiere Schritt 3 nur vollständig abgeschlossen, wenn seine Abnahme belegt ist.

Liefere einen kurzen Abschlussbericht mit Änderungen, Testergebnissen und offenen Grenzen. Benenne den Anschluss für Schritt 4, ohne ihn bereits umzusetzen.
