# KURABU WP Sync

WordPress-Plugin zur automatisierten Synchronisation von Daten aus der
KURABU-API des TV 1848 Coburg.

KURABU ist die führende Datenquelle. Das Plugin synchronisiert die Daten
regelmäßig nach WordPress, hält sie lokal gecacht und stellt sie über
Shortcodes auf der Website bereit. Beim normalen Seitenaufruf wird die
KURABU-API nicht angefragt, die Website bleibt also auch dann funktionsfähig,
wenn KURABU vorübergehend nicht erreichbar ist.

## Stand

Das Fundament steht: Grundgerüst, lokales Datenmodell und Backend-Skelett.

| Bereich | Stand |
| --- | --- |
| Plugin-Grundgerüst, Autoloader, Aktivierung | vorhanden |
| Lokales Datenmodell und Repositories | vorhanden |
| Einstellungen (API-Zugang, Intervall) | vorhanden |
| Backend-Menü „KURABU" | vorhanden, teils Platzhalter |
| Sync-Engine (KURABU-API, Cron) | offen |
| Shortcodes und Vorlagen-Baukasten | offen |

## Synchronisierte Daten

Abteilungen/Sportarten, Teams/Trainingsgruppen, Trainings, Trainingszeiten und
Trainingsorte liegen im lokalen Cache. News werden zu WordPress-Beiträgen,
Events wandern in den Vereinskalender; beide werden über ihre stabile KURABU-ID
wiedererkannt und aktualisiert statt doppelt angelegt.

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
