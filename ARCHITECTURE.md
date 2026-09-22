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

### Sync-Engine (`includes/Sync/`, noch leer)

Holt die Daten aus der KURABU-API und schreibt sie über die Repositories in den
Cache. Sie benutzt:

- `Support\Settings` für Basis-URL, Token und Intervall. Die Engine liest diese
  Werte nur; geschrieben werden sie im Backend.
- `Plugin::CRON_HOOK` (`kurabu_wp_sync_run`) als Cron-Hook. Die erlaubten
  Intervalle stehen in `Settings::INTERVALS`; passende WP-Cron-Schedules
  registriert die Engine daraus.
- `SyncState::last_success()` als Startpunkt für „nur Geändertes".
  `mark_error()` lässt `last_success_at` bewusst unangetastet, damit ein
  fehlgeschlagener Lauf denselben Zeitraum erneut versucht und der zuletzt
  erfolgreiche Datenbestand erhalten bleibt.
- `Support\Logger` für das Fehlerprotokoll. Ein `Logger` pro Lauf, damit alle
  Einträge eines Laufs dieselbe `run_id` tragen.
- `AbstractRepository::prune()` zum Entfernen verschwundener Datensätze. Eine
  leere Liste löscht nichts, damit ein API-Fehler den Cache nicht leeren kann.

Einstiegspunkte: die Actions `kurabu_wp_sync_booted`,
`kurabu_wp_sync_activated` und `kurabu_wp_sync_settings_saved`.

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
