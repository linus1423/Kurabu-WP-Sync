# Die KURABU-API dieses Vereins

Notizen zur öffentlichen API, gegen die das Plugin synchronisiert. Grundlage ist
die Dokumentation unter
<https://tv1848coburg.kurabu.com/api/public/v1/docs> (Stand 28.09.2026).
Alles hier Beschriebene ist im Plugin voreingestellt und im Backend
korrigierbar; diese Seite sagt, warum die Voreinstellungen so aussehen.

## Zugang

- **Basis-URL:** `https://<verein>.kurabu.com/api/public/v1` — für diesen Verein
  also `https://tv1848coburg.kurabu.com/api/public/v1`. Der Verein steckt in der
  Subdomain und nie im Pfad. Wird unter *API-Konfiguration* nur die Adresse ohne
  Pfad eingetragen, ergänzt das Plugin `/api/public/v1`.
- **Authentifizierung:** `Authorization: Bearer <api_key>`, ein Schlüssel je
  Verein. Er wird im KURABU-Adminbereich unter *Einstellungen → Inhalte & APIs*
  erzeugt. Im Plugin ist die Auth-Methode „Bearer-Token" voreingestellt.
- **Feldnamen:** durchgehend `snake_case`.

## Antwortformat

```json
{
  "data": [ … ],
  "meta": { "pagination": { "limit": 20, "offset": 40, "total": 245 } }
}
```

Geblättert wird über `limit` (Standard 20, höchstens 100) und `offset`
(Standard 0). Das Plugin fragt 100 Datensätze je Anfrage und hört auf, sobald
`total` erreicht ist.

Fehler kommen einheitlich als `{"error": {"code": …, "message": …}}`:

| HTTP | Code | Bedeutung |
| --- | --- | --- |
| 401 | `INVALID_API_KEY` | Schlüssel fehlt, ist falsch oder abgelaufen |
| 403 | `TENANT_BLOCKED` | Verein ist gesperrt |
| 404 | `TENANT_NOT_FOUND` | Subdomain gehört zu keinem Verein |
| 422 | `VALIDATION_ERROR` | ungültige Parameter |

## Filter

Gefiltert wird in Klammer-Schreibweise: `filter[{feld}][{operator}]={wert}`.
Jede Liste kennt `filter[published][eq]` und `filter[updated_at][gt]` mit einem
Zeitpunkt in ISO 8601 UTC, etwa `2026-01-01T00:00:00Z`. Genau dieser Filter ist
der „geändert seit"-Filter, mit dem ein Lauf nur die Änderungen seit dem letzten
Erfolg holt.

Solange die Einstellung **Nur öffentliche Daten** aktiv ist, schickt das Plugin
zusätzlich `filter[published][eq]=true`, bei Teams und Terminen
`filter[visibility][in]=PUBLIC` und bei Artikeln
`filter[status][in]=PUBLISHED,FEATURED`. Was KURABU trotzdem als intern
markiert, wird verworfen statt veröffentlicht.

## Endpunkte und was das Plugin daraus macht

| Endpunkt | KURABU-Objekt | Wird im Plugin zu |
| --- | --- | --- |
| `clubs/categories` | Category | Abteilungen / Sportarten |
| `clubs/teams` | Team | Teams / Trainingsgruppen |
| `clubs/locations` | Location | Trainingsorte |
| `clubs/facilities` | Facility | Trainingsorte (Anlage) |
| `clubs/rooms` | Room | Trainingsorte (Raum) |
| `clubs/documents` | Document | noch nicht übernommen |
| `content/articles` | Article | News als WordPress-Beiträge |
| `events/calendar-events` | CalendarEvent | Trainings, Trainingszeiten und Events |
| `memberships/team-leads` | TeamLead | noch nicht übernommen |

Drei Eigenheiten sind wichtig, weil sie nicht zum Datenmodell des Plugins
passen und im Code eigens behandelt werden:

1. **Es gibt keine Trainings und keine Trainingszeiten.** Ein Training ist ein
   wiederkehrender Kalendertermin, der zu einer Trainingsgruppe gehört
   (`team_id` gesetzt, `is_recurring` wahr). Der Wochentag steckt im `BYDAY`
   des `rrule`, das Ende der Serie in dessen `UNTIL`. Ein Termin an zwei Tagen
   ist deshalb ein Datensatz in KURABU und zwei Trainingszeiten im Cache; ihre
   Kennung ist die Termin-ID mit angehängtem Wochentag, damit der nächste Lauf
   dieselben Zeilen wiederfindet. Was nicht als Training gilt, wandert als Event
   in den Kalender. Die Regel dafür steht unter *API-Konfiguration* als
   **Trainings erkennen an**.
2. **Eine Abteilung kennt ihre Teams, ein Team seine Abteilung nicht.** Die
   Verbindung steht in `team_ids` einer Category, deshalb laufen die Abteilungen
   vor den Teams und das Team liest seine Abteilung aus dem Cache.
3. **Ein Ort ist dreistufig**: Location, darin Facility, darin Room. Ein Termin
   verweist auf die Stufe, in der er stattfindet. Damit jeder Verweis auflösbar
   bleibt, landen alle drei im Trainingsorte-Cache.

Uhrzeiten kommen getrennt vom Datum (`start_date` plus `start_time`), ein
ganztägiger Termin hat gar keine Uhrzeit. Bilder liefert KURABU als URL; das
Plugin übernimmt sie als URL und lädt sie nicht in die Mediathek.
