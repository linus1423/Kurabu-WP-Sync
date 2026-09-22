# KURABU WP Sync

WordPress-Plugin zur automatisierten Synchronisation von Daten aus der
KURABU-API des TV 1848 Coburg.

KURABU ist die führende Datenquelle. Das Plugin synchronisiert die Daten
regelmäßig nach WordPress, hält sie lokal gecacht und stellt sie über
Shortcodes auf der Website bereit. Beim normalen Seitenaufruf wird die
KURABU-API nicht angefragt, die Website bleibt also auch dann funktionsfähig,
wenn KURABU vorübergehend nicht erreichbar ist.

## Stand

Fundament und Darstellung stehen; die Synchronisation mit KURABU fehlt noch.

| Bereich | Stand |
| --- | --- |
| Plugin-Grundgerüst, Autoloader, Aktivierung | vorhanden |
| Lokales Datenmodell und Repositories | vorhanden |
| Einstellungen (API-Zugang, Intervall) | vorhanden |
| Backend-Menü „KURABU" | vorhanden, teils Platzhalter |
| Shortcodes und Vorlagen-Baukasten | vorhanden |
| Sync-Engine (KURABU-API, Cron) | offen |

Bis die Synchronisation läuft, sind die lokalen Tabellen leer und die
Shortcodes geben nichts aus. Wer Vorlagen bearbeiten darf, sieht an ihrer
Stelle einen Hinweis, welche Kennung nicht gefunden wurde; Besucher sehen
nichts.

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

## Shortcodes

Alle Shortcodes lesen ausschließlich aus dem lokalen Datenbestand. Ein
Seitenaufruf fragt KURABU nie an.

### `[kurabu_department]`

Stellt eine komplette Abteilung dar.

```text
[kurabu_department id="turnen" template="standard" trainings="true" news="true" events="true"]
```

| Parameter | Standard | Bedeutung |
| --- | --- | --- |
| `id` | – | Abteilung, als KURABU-ID oder als Kennung (Slug) |
| `template` | `standard` | Abteilungs-Vorlage |
| `trainings` | `true` | Trainings der Abteilung mitausgeben |
| `training_template` | `standard` | Vorlage der Trainings darin |
| `news` | `false` | News-Block ausgeben |
| `events` | `false` | Termin-Block ausgeben |
| `orderby` | `menu_order` | `menu_order` oder `name` |
| `order` | `ASC` | `ASC` oder `DESC` |
| `limit` | `0` | höchstens so viele Trainings, `0` = alle |

News und Termine liegen nicht im lokalen Cache: News werden WordPress-Beiträge,
Termine landen im Vereinskalender. Solange die Synchronisation sie nicht
anlegt, bleiben beide Blöcke leer und werden weggelassen. Wer sie füllt, hängt
sich an `kurabu_wp_sync_department_news` bzw.
`kurabu_wp_sync_department_events`.

### `[kurabu_training]`

Stellt ein einzelnes Training dar, oder die Trainings einer Gruppe.

```text
[kurabu_training id="12345" template="compact"]
[kurabu_training team="jugend"]
```

| Parameter | Standard | Bedeutung |
| --- | --- | --- |
| `id` | – | Training, als KURABU-ID oder als Kennung |
| `team` | – | alle Trainings einer Trainingsgruppe |
| `department` | – | alle Trainings einer Abteilung |
| `location` | – | alle Trainings an einem Trainingsort |
| `template` | `standard` | Training-Vorlage |
| `orderby`, `order`, `limit` | wie oben | Sortierung und Begrenzung |

### `[kurabu_trainings]`

Dasselbe als Liste, auch ohne Auswahl.

```text
[kurabu_trainings department="turnen"]
[kurabu_trainings location="huk-halle" template="compact" limit="5"]
```

## Vorlagen

Unter **KURABU → Vorlagen** wird festgelegt, wie ein Training und wie eine
Abteilung dargestellt werden. Eine Vorlage ist eine Liste von Elementen: die
KURABU-Felder (Name, Gruppe, Wochentag, Beginn, Ende, Trainingsort, Halle,
Trainer, Beschreibung, Bild, Link …) und Layout-Elemente (Überschrift, Text,
Trennlinie, Link/Button, Abstand). Je Element lassen sich Reihenfolge,
Sichtbarkeit, Beschriftung, Icon, Breite (ganze, halbe oder Drittel-Spalte),
Abstand und CSS-Klasse einstellen. Eine Vorschau mit Beispieldaten steht unter
dem Formular.

Mitgeliefert werden:

```text
Training
├── Standard
├── Kompakt
└── Detail

Abteilung
├── Standard
└── Übersicht
```

Weitere Vorlagen lassen sich anlegen oder duplizieren und im Shortcode über
`template="…"` auswählen. Vorlagen liegen zentral in einer Option, das Design
wird also nur einmal geändert. Eine mitgelieferte Vorlage, die bearbeitet
wurde, lässt sich auf den Auslieferungszustand zurücksetzen.

Die Ausgabe ist bewusst schlicht gehalten: `assets/css/kurabu.css` bringt nur
Anordnung und Abstände mit, Farben und Schriften kommen vom Theme.

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

Die Darstellung lässt sich ohne WordPress-Installation prüfen. Die Tests legen
Testdaten in einem Speicher-Abbild der Tabellen an und lassen die echten
Shortcodes, Repositories und die Template-Engine darauf laufen:

```bash
php tests/render-test.php
```
