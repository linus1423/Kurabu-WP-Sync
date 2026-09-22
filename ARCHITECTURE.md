# Architektur

Die Spezifikation verlangt, dass Synchronisation, Datenhaltung, Shortcodes und
Template-Engine strikt getrennt bleiben, damit Änderungen an der Darstellung
die KURABU-Synchronisation nicht berühren. Dieses Dokument hält fest, wo die
Grenzen verlaufen und über welche Schnittstellen die Schichten miteinander
reden.

```
KURABU API
    │
    ▼
Sync-Engine          includes/Sync/        schreibt
    │
    ▼
Datenhaltung         includes/Database/    lokaler Cache, einzige SQL-Schicht
    │
    ├──────────────► WordPress-Kalender    über ObjectMap
    ▼
Shortcodes           includes/Shortcode/   liest
    │
    ▼
Template-Engine      includes/Template/    bestimmt nur das Aussehen
    │
    ▼
WordPress-Seite
```

## Regeln

1. **Nur `includes/Database/` baut SQL.** Alle anderen Schichten gehen über die
   Repositories. Wer ein neues Feld braucht, erweitert das Schema und die
   Repositories, nicht die eigene Schicht.
2. **Die Sync-Engine schreibt, die Darstellung liest.** Shortcodes und
   Template-Engine rufen niemals die KURABU-API auf. Beim normalen
   Seitenaufruf gibt es keine Anfrage an KURABU.
3. **Die Template-Engine bestimmt ausschließlich das Aussehen.** Sie entscheidet
   nicht, welche Datensätze geladen werden; das tun die Shortcodes.
4. **Stabile KURABU-IDs sind der Schlüssel.** Jeder Datensatz wird über seine
   KURABU-ID wiedererkannt, nicht über Namen oder Reihenfolge.

## Schichten

### Datenhaltung (`includes/Database/`, `includes/Model/`)

Der lokale Cache. Die Tabellen liegen in `Database/Schema.php`, jede mit
`kurabu_id` als eindeutigem Schlüssel, einem rohen `payload` mit dem
unveränderten API-Datensatz und einer `checksum` zur Änderungserkennung.

Zugriff ausschließlich über die Repositories in
`Database/Repository/`:

| Repository | Inhalt |
| --- | --- |
| `DepartmentRepository` | Abteilungen / Sportarten |
| `TeamRepository` | Teams / Trainingsgruppen |
| `LocationRepository` | Trainingsorte |
| `TrainingRepository` | Trainings |
| `TrainingTimeRepository` | Trainingszeiten |

Erreichbar über den Container: `plugin()->departments()`, `plugin()->teams()`,
`plugin()->locations()`, `plugin()->trainings()`, `plugin()->training_times()`.

Dazu zwei Helfer ohne eigenes Repository:

- `Database\ObjectMap` merkt sich, in welches WordPress-Objekt ein
  KURABU-Datensatz synchronisiert wurde. News werden zu WordPress-Beiträgen und
  Events wandern in den Vereinskalender, liegen also nicht in eigenen Tabellen.
  Die Map sorgt dafür, dass ein zweiter Lauf das vorhandene Objekt aktualisiert
  statt ein Duplikat anzulegen.
- `Database\SyncState` hält je Datenart fest, wann zuletzt erfolgreich
  synchronisiert wurde. Das ist die Grundlage für inkrementelle Läufe.

### Sync-Engine (`includes/Sync/`)

Holt die Daten aus der KURABU-API und schreibt sie über die Repositories in den
Cache. `Engine::bootstrap()` hängt sich in `kurabu_wp_sync_booted` ein und
registriert von dort die Cron-Schedules und den Cron-Handler.

Die Schicht ist von außen nach innen gebaut:

| Baustein | Aufgabe |
| --- | --- |
| `Transport\Transport` | spricht HTTP, sonst nichts. `HttpTransport` nutzt `wp_remote_request()` und wiederholt einen Transportfehler begrenzt. |
| `Auth\Authenticator` | hängt den Token an. Bearer, API-Key im Header, Query-Parameter, Basic oder gar nichts — ausgewählt über die Einstellung `auth_method`. |
| `Client` | kennt Endpunkte, Paginierung und das Antwort-Envelope und liefert rohe KURABU-Datensätze. Kennt die Datenbank nicht. |
| `Mapping\Definition` | die einzige Stelle, die weiß, wie KURABU seine Felder benennt: je Zielfeld ein Typ und mehrere Kandidatennamen. |
| `Mapping\FieldMap` | im Backend gesetzte Endpunkte und Feldnamen; sie gewinnen über die Kandidaten. |
| `Mapping\RecordMapper` | liest die Felder aus einem Datensatz und normalisiert sie (Datum, Uhrzeit, Wochentag, verschachtelte Werte über `location.name`). |
| `Handler\Handler` | ein Handler je Datenart. Ein Fehler bleibt dadurch lokal. |
| `Calendar\CalendarAdapter` | schreibt Events in den Kalender, den die Website einsetzt. |
| `Engine` | plant, sperrt, protokolliert und fasst den Lauf zusammen. |

Dabei gilt:

- `Support\Settings` liefert Basis-URL, Token, Intervall, Auth-Methode und die
  Abfrage-Parameter. Die Engine liest diese Werte nur; geschrieben werden sie im
  Backend.
- `Plugin::CRON_HOOK` (`kurabu_wp_sync_run`) ist der Cron-Hook. `Scheduler`
  registriert aus `Settings::INTERVALS` je Intervall ein WP-Cron-Schedule und
  plant nur um, wenn sich das Intervall wirklich geändert hat.
- `SyncState::last_success()` ist der Startpunkt für „nur Geändertes".
  `mark_error()` lässt `last_success_at` bewusst unangetastet, damit ein
  fehlgeschlagener Lauf denselben Zeitraum erneut versucht und der zuletzt
  erfolgreiche Datenbestand erhalten bleibt.
- `Support\Logger` schreibt das Fehlerprotokoll. Ein `Logger` pro Lauf, damit
  alle Einträge eines Laufs dieselbe `run_id` tragen.
- `AbstractRepository::prune()` entfernt verschwundene Datensätze — aber nur
  nach einem vollständigen Lauf. Ein inkrementeller Lauf kennt die Gesamtliste
  nicht und darf deshalb nichts löschen; deswegen läuft mindestens einmal
  täglich ein vollständiger Lauf.
- `Lock` verhindert, dass sich zwei Läufe überschneiden.

Einstiegspunkte: die Actions `kurabu_wp_sync_booted`,
`kurabu_wp_sync_activated` und `kurabu_wp_sync_settings_saved`.

Solange die KURABU-Doku fehlt, sind Endpunkte, Auth-Methode, Paginierung und
Feldnamen bewusst Einstellungen und keine Konstanten: eine falsche Annahme wird
im Backend unter „Mapping" korrigiert, nicht im Code.

### Shortcodes (`includes/Shortcode/`, noch leer)

Entscheiden, **welche** Daten auf einer Seite erscheinen: `[kurabu_department]`,
`[kurabu_training]`, `[kurabu_trainings]` samt Parametern. Sie lesen
ausschließlich aus den Repositories.

`AbstractRepository::find_by_reference()` löst dabei sowohl
`id="12345"` (KURABU-ID) als auch `id="turnen"` (Slug) auf.

### Template-Engine (`includes/Template/`, noch leer)

Bestimmt, **wie** ein Training oder eine Abteilung aussieht. Die im Backend
gespeicherte Vorlage wird zentral abgelegt und von allen Shortcodes verwendet.

Als Feldzugriff dient `Model\AbstractModel::get()`: Es löst einen Feldnamen
zuerst gegen die Spalten auf und fällt danach auf den rohen KURABU-`payload`
zurück. Dadurch kann der Vorlagen-Baukasten auch KURABU-Felder anbieten, die
das Schema nicht ausdrücklich abbildet, ohne dass dafür eine Migration nötig
ist.

### Backend (`includes/Admin/`)

Das Menü „KURABU" und seine Screens. Neue Screens werden über den Filter
`kurabu_wp_sync_admin_pages` ergänzt, nicht durch Änderungen an `AdminMenu`.

## Erweiterungspunkte

| Hook | Zeitpunkt |
| --- | --- |
| `kurabu_wp_sync_booted` | Container steht, Schichten hängen sich ein |
| `kurabu_wp_sync_activated` | Tabellen existieren, erster Lauf kann geplant werden |
| `kurabu_wp_sync_deactivated` | geplante Läufe wurden entfernt |
| `kurabu_wp_sync_settings_saved` | Einstellungen geändert, Cron neu planen |
| `kurabu_wp_sync_admin_pages` | Backend-Screens ergänzen |
| `kurabu_wp_sync_run_finished` | ein Lauf ist durch, bekommt den `RunReport` |

Dazu die Filter der Sync-Schicht: `kurabu_wp_sync_field_definition` (Feldnamen),
`kurabu_wp_sync_query_args` (Query-Parameter), `kurabu_wp_sync_handlers`
(Datenarten), `kurabu_wp_sync_calendar_adapters` (weitere Kalender) und
`kurabu_wp_sync_full_sync_interval` (Abstand zweier vollständiger Läufe).
