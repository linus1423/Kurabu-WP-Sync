# KURABU WP Sync

WordPress-Plugin zur automatisierten Synchronisation von Daten aus der
KURABU-API des TV 1848 Coburg.

KURABU ist die führende Datenquelle. Das Plugin synchronisiert die Daten
regelmäßig nach WordPress, hält sie lokal gecacht und stellt sie über
Shortcodes auf der Website bereit. Beim normalen Seitenaufruf wird die
KURABU-API nicht angefragt, die Website bleibt also auch dann funktionsfähig,
wenn KURABU vorübergehend nicht erreichbar ist.

## Stand

Fundament und Synchronisation stehen; die Darstellung über Shortcodes fehlt
noch.

| Bereich | Stand |
| --- | --- |
| Plugin-Grundgerüst, Autoloader, Aktivierung | vorhanden |
| Lokales Datenmodell und Repositories | vorhanden |
| Einstellungen (API-Zugang, Intervall) | vorhanden |
| Backend-Menü „KURABU" | vorhanden, teils Platzhalter |
| Sync-Engine (KURABU-API, Cron) | vorhanden |
| Shortcodes und Vorlagen-Baukasten | offen |

## Synchronisierte Daten

Abteilungen/Sportarten, Teams/Trainingsgruppen, Trainings, Trainingszeiten und
Trainingsorte liegen im lokalen Cache. News werden zu WordPress-Beiträgen,
Events wandern in den Vereinskalender; beide werden über ihre stabile KURABU-ID
wiedererkannt und aktualisiert statt doppelt angelegt.

## Synchronisation

Das Intervall wird unter **KURABU → API-Konfiguration** gesetzt: 5, 15 oder 30
Minuten, 1, 2, 6 oder 24 Stunden. Unter **KURABU → Synchronisation** lässt sich
zusätzlich jederzeit manuell synchronisieren — entweder direkt oder im
Hintergrund, was bei vielen Datensätzen der sichere Weg ist. Dort wird auch
gewählt, welche Datenarten überhaupt laufen.

Wo die API es unterstützt, fragt ein Lauf nur die seit dem letzten Erfolg
geänderten Daten ab. Mindestens einmal täglich läuft trotzdem ein vollständiger
Lauf, denn nur er sieht die Gesamtliste und erkennt, was in KURABU gelöscht
wurde.

Schlägt ein Abruf fehl, bleibt der zuletzt erfolgreiche Datenbestand
unverändert: der Zeitstempel des letzten Erfolgs wird nicht fortgeschrieben, der
nächste Lauf holt denselben Zeitraum erneut. Ein Fehler betrifft immer nur seine
Datenart, die übrigen laufen weiter. Was passiert ist, steht unter
**Synchronisationsstatus** und **Fehlerprotokoll**.

### Anpassung an die KURABU-API

Die API-Dokumentation liegt noch nicht vor. Deshalb sind Endpunkte,
Auth-Methode, Paginierung und Feldnamen Einstellungen und keine festen Werte:

- **API-Konfiguration** wählt die Auth-Methode (Bearer-Token, API-Key im Header
  oder als Query-Parameter, Basic Auth).
- **Mapping** setzt je Datenart den Endpunkt-Pfad und, wo nötig, den exakten
  KURABU-Feldnamen je Feld. Ohne Angabe werden mehrere übliche Schreibweisen
  der Reihe nach probiert; ein Punkt greift in verschachtelte Werte, etwa
  `location.name`.
- **Verbindung testen** auf der Seite *Synchronisation* ruft einen Endpunkt
  einmal auf und zeigt die gelieferten Feldnamen. Damit lässt sich das Mapping
  ohne Raten einstellen.
- **Kalenderintegration** wählt, wohin die Events geschrieben werden: The Events
  Calendar oder ein frei wählbarer Beitragstyp mit benannten Custom Fields.

## Installation

1. Das Verzeichnis nach `wp-content/plugins/kurabu-wp-sync/` kopieren.
2. Das Plugin im Backend aktivieren. Dabei werden die Tabellen angelegt.
3. Unter **KURABU → API-Konfiguration** Basis-URL, Token und Intervall setzen.

Der API-Token kann statt im Backend auch in der `wp-config.php` hinterlegt
werden, dann hat die Konstante Vorrang:

```php
define( 'KURABU_WP_SYNC_API_TOKEN', '…' );
```

## Backend

Das Menü **KURABU** enthält: API-Konfiguration, Synchronisation,
Synchronisationsstatus, Fehlerprotokoll, Abteilungen, Trainings, Mapping,
Kalenderintegration und Vorlagen.

## Deinstallation

Deaktivieren beendet nur die geplanten Läufe; Daten und Einstellungen bleiben
erhalten. Erst das Löschen des Plugins entfernt Tabellen und Einstellungen.

## Entwicklung

Anforderungen: WordPress 6.0+, PHP 7.4+.

Wie die Schichten zusammenhängen und welche Schnittstellen zwischen ihnen
gelten, steht in [ARCHITECTURE.md](ARCHITECTURE.md).
