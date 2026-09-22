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

### Shortcodes (`includes/Shortcode/`)

Entscheiden, **welche** Daten auf einer Seite erscheinen: `[kurabu_department]`,
`[kurabu_training]`, `[kurabu_trainings]` samt Parametern. Sie lesen
ausschließlich aus den Repositories.

`AbstractRepository::find_by_reference()` löst dabei sowohl
`id="12345"` (KURABU-ID) als auch `id="turnen"` (Slug) auf.

`ShortcodeManager::bootstrap()` hängt die Schicht in
`kurabu_wp_sync_booted`; registriert wird sie erst dort, nicht im Container.

Der Weg eines Aufrufs:

1. `AbstractShortcode::render()` normalisiert die Parameter und fragt den
   `RenderCache`.
2. Der jeweilige Shortcode wählt die Datensätze über die Repositories aus.
3. Der `ContextBuilder` liest die verbundenen Datensätze (Gruppe, Abteilung,
   Trainingsort, Trainingszeiten) und baut daraus die fertigen Werte.
4. `Template\Renderer` macht daraus HTML.

Der `ContextBuilder` gehört bewusst hierher und nicht in die Template-Engine:
Er ist der Teil, der Datensätze liest. Die Engine bekommt nur noch Werte.

Der `RenderCache` legt fertiges HTML in einem Transient ab. Der Schlüssel
enthält die Vorlagen-Revision und den jüngsten erfolgreichen Sync-Zeitpunkt,
deshalb entwertet sich ein Eintrag von selbst, sobald eine Vorlage gespeichert
oder ein Lauf abgeschlossen wurde. Wer Vorlagen bearbeiten darf, umgeht den
Zwischenspeicher. `kurabu_wp_sync_shortcode_cache_ttl` stellt die Lebensdauer
ein, `0` schaltet ihn ab.

### Template-Engine (`includes/Template/`)

Bestimmt, **wie** ein Training oder eine Abteilung aussieht. Die im Backend
gespeicherte Vorlage wird zentral abgelegt und von allen Shortcodes verwendet.

Als Feldzugriff dient `Model\AbstractModel::get()`: Es löst einen Feldnamen
zuerst gegen die Spalten auf und fällt danach auf den rohen KURABU-`payload`
zurück. Dadurch kann der Vorlagen-Baukasten auch KURABU-Felder anbieten, die
das Schema nicht ausdrücklich abbildet, ohne dass dafür eine Migration nötig
ist.

| Klasse | Aufgabe |
| --- | --- |
| `Element` | ein Element einer Vorlage: Feld oder Layout, plus Optionen |
| `Template` | eine benannte Vorlage: Typ, Name, Elemente in Reihenfolge |
| `TemplateStore` | zentrale Ablage in der Option `kurabu_wp_sync_templates` |
| `DefaultTemplates` | die mitgelieferten Vorlagen |
| `FieldRegistry` | welche KURABU-Felder und Layout-Elemente es gibt |
| `Renderer` | Vorlage plus Kontext ergibt HTML |
| `SampleData` | Beispieldaten für die Vorschau im Backend |
| `Assets` | das Stylesheet, nur wo ein Shortcode ausgegeben wurde |

Der Renderer bekommt einen Kontext mit zwei Teilen: `values` (Feldwerte) und
`blocks` (bereits gerendertes HTML, etwa die Trainings einer Abteilung). Er
fragt nichts nach und liest nichts; er entscheidet ausschließlich über die
Darstellung.

Nicht gespeicherte Vorlagen kommen aus `DefaultTemplates`. Gespeichert wird
erst, wenn jemand im Backend etwas ändert; das Löschen einer mitgelieferten
Vorlage stellt deshalb den Auslieferungszustand wieder her.

Erweitert wird über Filter statt über Änderungen an diesen Klassen:
`kurabu_wp_sync_template_fields` meldet ein Feld an,
`kurabu_wp_sync_template_values` füllt es.

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
| `kurabu_wp_sync_templates_saved` | eine Vorlage wurde angelegt, geändert oder gelöscht |

| Filter | Zweck |
| --- | --- |
| `kurabu_wp_sync_template_fields` | zusätzliche KURABU-Felder im Baukasten |
| `kurabu_wp_sync_template_values` | Werte für die Darstellung ergänzen |
| `kurabu_wp_sync_templates` | Vorlagen ergänzen oder ersetzen |
| `kurabu_wp_sync_shortcodes` | weitere Shortcodes registrieren |
| `kurabu_wp_sync_shortcode_cache_ttl` | Lebensdauer des Zwischenspeichers |
| `kurabu_wp_sync_department_news` | News einer Abteilung beisteuern |
| `kurabu_wp_sync_department_events` | Termine einer Abteilung beisteuern |
| `kurabu_wp_sync_rendered_template` | fertiges HTML einer Vorlage nachbearbeiten |
| `kurabu_wp_sync_element_classes` | CSS-Klassen eines Elements anpassen |
