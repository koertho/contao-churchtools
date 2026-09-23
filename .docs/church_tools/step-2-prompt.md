# Prompt: Schritt 2 implementieren

Implementiere ausschließlich Schritt 2 der ChurchTools-Erweiterung: installierbares Bundle-Gerüst, Konfiguration, API-Client, optionale Token-Einrichtung und frühe Kompatibilitätsmatrix.

## Verbindliche Orte und Namen

- Eigenständiges Bundle: `/home/dev/Kunden/github/contao-churchtools`
- Composername: `koertho/contao-churchtools`
- Planung und API-Fixtures: `/home/dev/Kunden/github/contao-churchtools/.docs/church_tools`
- Contao-Host: `/home/dev/Kunden/privat/jzm-contao`
- Lokale PHP-/Composer-/Integrationstests: DDEV-Projekt `jzm`
- Ziele: PHP 8.4+, Contao 5.7 und 6

Die frühere Ablage unter `packages/` und der Name `koertho/contao-church-tools` sind ersetzt. Das Bundle muss unabhängig vom JZM-Projekt installierbar bleiben.

## Zuerst lesen und prüfen

Lies README.md, requirements.md, architecture.md, steps.md und insbesondere den aktuellen Teil von api-contract.md im Planungsverzeichnis. Historische Untersuchungsabschnitte sind Belege, keine zusätzlichen Anforderungen. Beachte vorhandene AGENTS.md-Regeln in beiden Arbeitsverzeichnissen. Prüfe Git-Status und vorhandene Dateien; bewahre fremde Änderungen. Implementiere ohne Commit, Push oder Veröffentlichung.

Schritt 1 liefert ausreichende Grundlagen, ist aber nicht vollständig verifiziert. Der Live-Test für Berechtigungsverlust wurde ausdrücklich übersprungen; fordere dafür keine erneuten Berechtigungsänderungen an. Die übrigen Restprüfungen sind kein Blocker für dieses Gerüst.

## Umsetzung

1. Erstelle eine saubere Composer-Paketstruktur, PSR-4-Autoloading, Symfony-AbstractBundle und Contao-Manager-Integration. Bestimme kompatible direkte Abhängigkeiten anhand tatsächlich auflösbarer Versionen. Verwende keine hostprojektspezifischen Klassen.
2. Definiere eigene Bundle-Konfiguration für Instanz-URL, Token und optionale Setup-Zugangsdaten sowie die vereinbarten Synchronisationsparameter. Keine fest eingebauten Host-Variablennamen oder Instanz-URL.
3. Implementiere einen kleinen Symfony-HttpClient-basierten Client für verifizierte Kalender-/Terminabfragen und Authentifizierung. Regulärer Zugriff nutzt `Authorization: Login <token>`. Validierung und Fehlerbehandlung dürfen weder Geheimnisse noch vollständige private Antworttexte in Logs/Exceptions ausgeben. Automatischer Passwort-Fallback beim normalen Zugriff ist nicht vorgesehen. `5pm-hdh/churchtools-api` ist ausschließlich Recherchematerial, keine Runtime-Abhängigkeit.
4. Ergänze eine ausdrücklich aufgerufene Token-Einrichtung: konfigurierte Benutzername/Passwort-Zugangsdaten verwenden, eigenes Konto auflösen, dessen Token abrufen und ohne Login-Sitzung verifizieren. Geheimnisse nicht ausgeben oder als Prozessargumente verlangen. Speicherung nur in einem ausdrücklich gewählten lokalen Secret-Ziel; keine stillschweigenden Änderungen an Deployment-Konfiguration. Den bereits vorhandenen Host-Token nicht neu provisionieren oder überschreiben.
5. Integriere das Paket im Host über ein Composer-Path-Repository mit Symlink. Prüfe zuerst, wie das außerhalb des Hosts liegende Bundle im DDEV-Webcontainer erreichbar gemacht wird; richte bei Bedarf einen gezielten zusätzlichen Mount ein. Containerpfad, Composer-Repositorypfad und Symlinkziel müssen zusammen funktionieren. Bewahre bestehende Mounts, Repositories und fremde Lockfile-Änderungen; keine pauschalen Dependency-Updates.
6. Richte eine reproduzierbare CI-Matrix für Contao 5.7 und 6 mit PHP 8.4+ ein. Prüfe Dependency-Auflösung, Installation, Paketregistrierung, Container-Boot und passende Tests. Zusätzliche Contao-Zielumgebungen getrennt vom bestehenden JZM-Host betreiben. Keine Plattformanforderungen ignorieren, um Kompatibilität vorzutäuschen.
7. Dokumentiere Installation, Host-Mapping der Umgebungsvariablen, Einrichtung und Grenzen im Paket-README. Aktualisiere Schritte und Prüfprotokoll nach tatsächlich erzielten Ergebnissen.

## Zugangsdaten und Projektregeln

- `.env.local` im Host darf nicht inspiziert, angezeigt oder in Toolausgaben kopiert werden. Ausschließlich prozessinterne Nutzung über den normalen Symfony-Environment-Loader ist autorisiert.
- Vorhandene Variablen: `CT_TOKEN`, `CT_USER`, `CT_PASSWORD`. Das Host-Projekt ordnet diese Bundle-Konfigurationsschlüsseln zu.
- Keine Änderungen an ChurchTools-Terminen, Kalendern oder Berechtigungen. Für Integrationstests höchstens lesende Abfragen; keine Anmeldung unter fremden Identitäten.
- Bestehenden `ChurchToolsTestController.php` nicht anfassen.
- Symfony-PHP-Übersetzungen; PHP-Attribute für Listener/Callbacks/Hooks/Cron/Contao-Elemente; keine Callback-Arrays oder Registrierungs-Tags. Falls Reihenfolge relevant ist, explizite Prioritäten setzen.
- Spätere DCA-Spalten nutzen Doctrine-Schemadefinitionen. Kein eigenes targetColumn für virtuelle Felder.
- Cronlistener später unter `src/EventListener/Cron/`; DCA-Listener später unter `src/EventListener/DataContainer/<Table>/`, eine Klasse pro Callback.

## Klare Umfangsgrenze

Noch keine Datenbanktabellen/Migrationen, Synchronisierung oder automatische Löschungen, Backend-Verwaltung, Contao-Event-Erzeugung, Frontend-Inhaltselemente oder SSO implementieren. Diese gehören zu späteren Schritten. Bereits festgelegte Grenzen nicht neu entwerfen: genau zwei Tabellen, Metadaten-Blob, nullable Contao-Event-ID, UID-basierte Zuordnung mit akzeptiertem Verknüpfungsverlust bei Serienaufteilungen.

## Abnahme

Prüfe Composer-Konfiguration, Autoloading, Bundle-Erkennung und Container im DDEV-Host. Teste den Client mit kontrollierten Responses (Authentifizierung, valide/fehlerhafte Antworten, 401/403/429, Timeout und Geheimnisfreiheit); keine destruktiven Live-Tests. Prüfe die Token-Einrichtung gegen Testantworten inklusive fehlgeschlagener Verifikation und explizitem Ausgabeziel, ohne den produktiven Token zu ersetzen.

Führe die verfügbaren Matrixprüfungen aus und halte PHP-/Contao-/Symfony-Versionen fest. Eine CI-Datei allein bedeutet keinen erfolgreichen Contao-6-Test. Berichte nicht ausführbare Prüfungen als unverified. Markiere Schritt 2 nur vollständig abgeschlossen, wenn seine Abnahmekriterien tatsächlich erfüllt sind; andernfalls benenne präzise Restarbeiten.

Liefere einen kurzen Abschlussbericht mit Änderungen, Prüfungen und offenen Grenzen sowie Links auf relevante Dateien. Halte die Arbeit auf Schritt 2 begrenzt.
