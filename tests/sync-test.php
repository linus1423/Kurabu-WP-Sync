<?php
/**
 * Runs the sync layer against a simulated KURABU API.
 *
 * Run with: php tests/sync-test.php
 *
 * There is no access to the live API from here, so the tests install a canned
 * transport whose answers have the shape the KURABU public API documents, and
 * let the real client, mapper, handlers, repositories and engine work on them.
 * What is checked is the behaviour the specification asks for: recognising
 * records by their KURABU id, keeping the last good data when the API fails,
 * and never deleting on an incremental run.
 *
 * @link https://tv1848coburg.kurabu.com/api/public/v1/docs
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Tests;

use Kurabu\WPSync\Database\ObjectMap;
use Kurabu\WPSync\Database\SyncState;
use Kurabu\WPSync\Support\Logger;
use Kurabu\WPSync\Support\Settings;
use Kurabu\WPSync\Sync\ApiException;
use Kurabu\WPSync\Sync\Auth\BasicAuthenticator;
use Kurabu\WPSync\Sync\Auth\BearerAuthenticator;
use Kurabu\WPSync\Sync\Auth\HeaderAuthenticator;
use Kurabu\WPSync\Sync\Auth\QueryAuthenticator;
use Kurabu\WPSync\Sync\Client;
use Kurabu\WPSync\Sync\Engine;
use Kurabu\WPSync\Sync\Lock;
use Kurabu\WPSync\Sync\Mapping\CalendarEvent;
use Kurabu\WPSync\Sync\Mapping\Definition;
use Kurabu\WPSync\Sync\Mapping\FieldMap;
use Kurabu\WPSync\Sync\Mapping\RecordMapper;
use Kurabu\WPSync\Sync\Resource;
use Kurabu\WPSync\Sync\RunReport;
use Kurabu\WPSync\Sync\Scheduler;

use function Kurabu\WPSync\plugin;

require_once __DIR__ . '/sync-bootstrap.php';

$failures = 0;
$checks   = 0;

/**
 * Asserts a condition.
 *
 * @param string $what      What is being checked.
 * @param bool   $condition The result.
 * @param string $detail    Shown when the check fails.
 */
function check( string $what, bool $condition, string $detail = '' ): void {
	global $failures, $checks;

	$checks++;

	if ( $condition ) {
		printf( "  ok   %s\n", $what );

		return;
	}

	$failures++;

	printf( " FAIL  %s%s\n", $what, '' !== $detail ? '  (' . $detail . ')' : '' );
}

/**
 * Asserts that two values are identical.
 *
 * @param string $what     What is being checked.
 * @param mixed  $actual   Value produced.
 * @param mixed  $expected Value wanted.
 */
function same( string $what, $actual, $expected ): void {
	check(
		$what,
		$actual === $expected,
		sprintf( 'erwartet %s, erhalten %s', var_export( $expected, true ), var_export( $actual, true ) )
	);
}

/**
 * Prints a section heading.
 *
 * @param string $title Heading.
 */
function section( string $title ): void {
	printf( "\n%s\n", $title );
}

/**
 * Installs the canned API answers for the following checks.
 *
 * The URLs that were requested land in $GLOBALS['kurabu_requested_urls'],
 * which this function resets.
 *
 * @param array<string, mixed> $payloads Endpoint basename => decoded answer.
 * @param string[]             $failing  Endpoint basenames answering with 500.
 */
function respond_with( array $payloads, array $failing = array() ): void {
	$GLOBALS['kurabu_requested_urls'] = array();

	$GLOBALS['kurabu_responder'] = static function ( string $url ) use ( $payloads, $failing ): array {
		$GLOBALS['kurabu_requested_urls'][] = $url;

		$endpoint = basename( (string) parse_url( $url, PHP_URL_PATH ) );

		if ( in_array( $endpoint, $failing, true ) ) {
			return array(
				'status' => 500,
				'body'   => 'Internal Server Error',
			);
		}

		$query = array();
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		$answer = $payloads[ $endpoint ] ?? array();

		// A payload given as a list of pages is served page by page. KURABU
		// counts in records skipped, so the page follows from the offset.
		if ( isset( $answer['__pages'] ) ) {
			$limit  = max( 1, (int) ( $query['limit'] ?? 100 ) );
			$page   = 1 + intdiv( (int) ( $query['offset'] ?? 0 ), $limit );
			$answer = $answer['__pages'][ $page ] ?? array( 'data' => array() );
		}

		return array(
			'status' => 200,
			'body'   => (string) wp_json_encode( $answer ),
		);
	};
}

/**
 * The query arguments of a URL, with the bracket filters unpacked.
 *
 * @param string $url URL to read.
 *
 * @return array<string, mixed>
 */
function query_of( string $url ): array {
	$query = array();

	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

	return $query;
}

/**
 * The URLs requested since the last respond_with().
 *
 * @return array<int, string>
 */
function requested_urls(): array {
	return (array) ( $GLOBALS['kurabu_requested_urls'] ?? array() );
}

/**
 * The answers a full club looks like, in the shape the KURABU API documents.
 *
 * Keyed by the last segment of the endpoint path, which is what the canned
 * transport looks at.
 *
 * @return array<string, mixed>
 */
function club_payloads(): array {
	return array(
		// Abteilungen are categories, and a category lists its teams.
		'categories'      => envelope(
			array(
				array(
					'id'          => 'cat-1',
					'name'        => 'Turnen',
					'description' => 'Abteilung Turnen',
					'group'       => 'Breitensport',
					'image_url'   => 'https://cdn.example.org/turnen.jpg',
					'published'   => true,
					'team_ids'    => array( 'team-1' ),
					'contact'     => array(
						'first_name' => 'Petra',
						'last_name'  => 'Mustermann',
						'email'      => 'turnen@example.org',
						'phone'      => '09561 123',
					),
				),
				array(
					'id'        => 'cat-2',
					'name'      => 'Leichtathletik',
					'published' => true,
					'team_ids'  => array(),
					'contact'   => null,
				),
			)
		),
		'teams'           => envelope(
			array(
				array(
					'id'           => 'team-1',
					'name'         => 'Jugend',
					'published'    => true,
					'visibility'   => 'PUBLIC',
					'document_ids' => array(),
					'image_url'    => null,
				),
			)
		),
		// A place is a location, a facility inside it or a room inside that.
		'locations'       => envelope(
			array(
				array(
					'id'           => 'loc-1',
					'name'         => 'HUK-Coburg-Arena',
					'published'    => true,
					'facility_ids' => array( 'fac-1' ),
					'address'      => array(
						'street'  => 'Sportweg 1',
						'zip'     => '96450',
						'city'    => 'Coburg',
						'country' => 'DE',
					),
				),
			)
		),
		'facilities'      => envelope(
			array(
				array(
					'id'          => 'fac-1',
					'name'        => 'Turnhalle',
					'published'   => true,
					'location_id' => 'loc-1',
					'room_ids'    => array( 'room-1' ),
				),
			)
		),
		'rooms'           => envelope(
			array(
				array(
					'id'          => 'room-1',
					'name'        => 'Gymnastikraum',
					'published'   => true,
					'facility_id' => 'fac-1',
					'location_id' => 'loc-1',
				),
			)
		),
		// Trainings, Trainingszeiten and Events all come from here.
		'calendar-events' => envelope(
			array(
				array(
					'id'                => 'ev-tr-1',
					'name'              => 'Eltern-Kind-Turnen',
					'description'       => 'Für Kinder ab zwei Jahren',
					'type'              => 'TEAM',
					'visibility'        => 'PUBLIC',
					'published'         => true,
					'all_day'           => false,
					'is_recurring'      => true,
					'is_cancelled'      => false,
					'rrule'             => 'FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20270731T215959Z',
					'start_date'        => '2026-09-21',
					'start_time'        => '15:00:00',
					'end_date'          => null,
					'end_time'          => '16:00:00',
					'team_id'           => 'team-1',
					'category_id'       => 'cat-1',
					'location_id'       => null,
					'facility_id'       => 'fac-1',
					'room_id'           => null,
					'location_info'     => null,
					'event_leads'       => array(
						array(
							'first_name' => 'Petra',
							'last_name'  => 'Mustermann',
						),
					),
					'event_exceptions'  => array(),
					'ticket_options'    => array(),
					'going_count'       => 0,
					'is_ticketed'       => false,
				),
				array(
					'id'            => 'ev-1',
					'name'          => 'Hallenturnier',
					'description'   => '<p>Alle Abteilungen</p>',
					'type'          => 'PUBLIC',
					'visibility'    => 'PUBLIC',
					'published'     => true,
					'all_day'       => false,
					'is_recurring'  => false,
					'rrule'         => null,
					'start_date'    => '2026-10-04',
					'start_time'    => '10:00:00',
					'end_date'      => '2026-10-04',
					'end_time'      => '16:00:00',
					'team_id'       => null,
					'category_id'   => 'cat-1',
					'location_id'   => 'loc-1',
					'location_info' => 'Angerhalle, Coburg',
					'event_leads'   => array(),
				),
				// Visible to members only, so it stays off a public website.
				array(
					'id'           => 'ev-intern',
					'name'         => 'Vorstandssitzung',
					'type'         => 'ADMIN',
					'visibility'   => 'INTERNAL',
					'published'    => true,
					'is_recurring' => false,
					'start_date'   => '2026-10-10',
					'start_time'   => '19:00:00',
				),
				array(
					'id'           => 'ev-entwurf',
					'name'         => 'Noch nicht öffentlich',
					'type'         => 'PUBLIC',
					'visibility'   => 'PUBLIC',
					'published'    => false,
					'is_recurring' => false,
					'start_date'   => '2026-10-11',
					'start_time'   => '09:00:00',
				),
			)
		),
		'articles'        => envelope(
			array(
				array(
					'id'              => 'art-1',
					'title'           => 'Sommerfest',
					'body'            => '<p>Es war schön.</p>',
					'preview_text'    => 'Ein Nachmittag im Park',
					'cover_image_url' => 'https://cdn.example.org/fest.jpg',
					'image_credit'    => 'Foto: Verein',
					'publishing_date' => '2026-08-01',
					'status'          => 'PUBLISHED',
					'visibility'      => 'PUBLIC',
					'likes_count'     => 3,
					'category_ids'    => array( 'cat-1' ),
				),
				array(
					'id'           => 'art-intern',
					'title'        => 'Nur für Mitglieder',
					'body'         => '<p>Intern.</p>',
					'status'       => 'PUBLISHED',
					'visibility'   => 'INTERNAL',
					'likes_count'  => 0,
					'category_ids' => array(),
				),
			)
		),
	);
}

/**
 * Wraps records in the data/meta envelope the API answers with.
 *
 * @param array<int, array<string, mixed>> $records Records.
 *
 * @return array<string, mixed>
 */
function envelope( array $records ): array {
	return array(
		'data' => $records,
		'meta' => array(
			'pagination' => array(
				'limit'  => 100,
				'offset' => 0,
				'total'  => count( $records ),
			),
		),
	);
}

// --- Normalising what KURABU delivers ---------------------------------------

section( 'Normalisierung der KURABU-Werte' );

same( 'Wochentag "Montag"', RecordMapper::normalise( 'Montag', Definition::TYPE_WEEKDAY ), 1 );
same( 'Wochentag "Mi."', RecordMapper::normalise( 'Mi.', Definition::TYPE_WEEKDAY ), 3 );
same( 'Wochentag "sunday"', RecordMapper::normalise( 'sunday', Definition::TYPE_WEEKDAY ), 7 );
same( 'Wochentag 0 heißt Sonntag', RecordMapper::normalise( 0, Definition::TYPE_WEEKDAY ), 7 );
same( 'Wochentag unbekannt', RecordMapper::normalise( 'irgendwas', Definition::TYPE_WEEKDAY ), 0 );

same( 'Uhrzeit "18:00"', RecordMapper::normalise( '18:00', Definition::TYPE_TIME ), '18:00:00' );
same( 'Uhrzeit "9:05:30"', RecordMapper::normalise( '9:05:30', Definition::TYPE_TIME ), '09:05:30' );
same( 'Uhrzeit aus Zeitstempel', RecordMapper::normalise( '2026-09-21T18:30:00+02:00', Definition::TYPE_TIME ), '18:30:00' );

// A bare date has no time of day, so no timezone may shift it.
same( 'Datum deutsch', RecordMapper::normalise( '21.09.2026', Definition::TYPE_DATE ), '2026-09-21' );
same( 'Datum ISO', RecordMapper::normalise( '2026-09-21', Definition::TYPE_DATE ), '2026-09-21' );
same( 'Zeitstempel mit Zone wird lokal', RecordMapper::normalise( '2026-09-21T16:00:00Z', Definition::TYPE_DATETIME ), '2026-09-21 18:00:00' );
same( 'Zeitstempel ohne Zone bleibt lokal', RecordMapper::normalise( '2026-09-21 18:00:00', Definition::TYPE_DATETIME ), '2026-09-21 18:00:00' );

same( 'ID aus eingebettetem Objekt', RecordMapper::normalise( array( 'id' => 17 ), Definition::TYPE_ID ), '17' );
same( 'ID aus Skalar', RecordMapper::normalise( 17, Definition::TYPE_ID ), '17' );
same( 'Text aus benanntem Objekt', RecordMapper::normalise( array( 'name' => 'HUK-Halle' ), Definition::TYPE_TEXT ), 'HUK-Halle' );
same( 'Ja/Nein deutsch', RecordMapper::normalise( 'ja', Definition::TYPE_BOOL ), true );

$nested = array(
	'id'       => 5,
	'location' => array(
		'id'   => 9,
		'name' => 'Angerhalle',
	),
);
same( 'Punktpfad greift verschachtelt', RecordMapper::value_at( $nested, 'location.name' ), 'Angerhalle' );
same( 'Punktpfad ins Leere', RecordMapper::value_at( $nested, 'location.fehlt' ), null );
same(
	'Kandidat fällt auf Skalar zurück',
	RecordMapper::pick( array( 'department' => '17' ), array( 'department.id', 'department' ) ),
	'17'
);

// --- The mapping the backend can correct ------------------------------------

section( 'Mapping aus dem Backend' );

Settings::update(
	array(
		'api_base_url' => 'https://api.kurabu.test/v1',
		'api_token'    => 'geheim',
	)
);

FieldMap::save( Resource::DEPARTMENTS, 'v2/abteilungen', array( 'name' => 'bezeichnung' ) );
same( 'Endpunkt überschrieben', FieldMap::endpoint( Resource::DEPARTMENTS ), 'v2/abteilungen' );
same( 'Feldname gepinnt', FieldMap::sources( Resource::DEPARTMENTS, 'name' ), array( 'bezeichnung' ) );

$mapped = RecordMapper::map(
	Resource::DEPARTMENTS,
	array(
		'id'          => 'd1',
		'name'        => 'falsch',
		'bezeichnung' => 'Turnen',
	)
);
same( 'Gepinntes Feld schlägt die Kandidaten', $mapped['name'], 'Turnen' );

FieldMap::reset( Resource::DEPARTMENTS );
same( 'Zurücksetzen stellt den Standard her', FieldMap::endpoint( Resource::DEPARTMENTS ), 'clubs/categories' );

// --- The endpoints and filters of the documented API ------------------------

section( 'Endpunkte und Filter der KURABU-API' );

same( 'Abteilungen sind Kategorien', Definition::endpoints()[ Resource::DEPARTMENTS ], 'clubs/categories' );
same( 'News sind Artikel', Definition::endpoints()[ Resource::NEWS ], 'content/articles' );
same( 'Events kommen aus dem Kalender', Definition::endpoints()[ Resource::EVENTS ], 'events/calendar-events' );
same( 'Trainings kommen aus demselben Kalender', Definition::endpoints()[ Resource::TRAININGS ], 'events/calendar-events' );
same(
	'Trainingsorte holen auch Anlagen und Räume',
	Definition::extra_endpoints( Resource::LOCATIONS ),
	array( 'clubs/facilities', 'clubs/rooms' )
);

$query = query_of( ( new Client() )->endpoint_url( Resource::TEAMS, null, 1 ) );

same( 'Nur veröffentlichte Daten', $query['filter']['published']['eq'] ?? '', 'true' );
same( 'Nur öffentlich sichtbare Daten', $query['filter']['visibility']['in'] ?? '', 'PUBLIC' );
same( 'Anzahl je Seite als limit', $query['limit'] ?? '', '100' );
check( 'Erste Seite ohne offset', ! isset( $query['offset'] ), (string) wp_json_encode( $query ) );

$query = query_of( ( new Client() )->endpoint_url( Resource::TEAMS, null, 3 ) );
same( 'Dritte Seite überspringt 200 Datensätze', $query['offset'] ?? '', '200' );

$query = query_of( ( new Client() )->endpoint_url( Resource::NEWS, null, 1 ) );
same( 'Artikel werden über den Status gefiltert', $query['filter']['status']['in'] ?? '', 'PUBLISHED,FEATURED' );

Settings::update( array( 'public_only' => false ) );
$query = query_of( ( new Client() )->endpoint_url( Resource::TEAMS, null, 1 ) );
check( 'Ohne die Einschränkung kein Filter', ! isset( $query['filter'] ), (string) wp_json_encode( $query ) );
Settings::update( array( 'public_only' => true ) );

Settings::update( array( 'api_base_url' => 'https://tv1848coburg.kurabu.test' ) );
check(
	'Basis-URL ohne Pfad bekommt den API-Pfad',
	0 === strpos(
		( new Client() )->endpoint_url( Resource::TEAMS, null, 1 ),
		'https://tv1848coburg.kurabu.test/api/public/v1/clubs/teams?'
	),
	( new Client() )->endpoint_url( Resource::TEAMS, null, 1 )
);
Settings::update( array( 'api_base_url' => 'https://api.kurabu.test/v1' ) );

// --- Trainings hide in the calendar ----------------------------------------

section( 'Trainings im Kalender erkennen' );

$events   = club_payloads()['calendar-events']['data'];
$training = $events[0];
$single   = $events[1];

check( 'Wiederkehrender Gruppentermin ist ein Training', CalendarEvent::is_training( $training ) );
check( 'Einzeltermin ist kein Training', ! CalendarEvent::is_training( $single ) );
same( 'Wochentage aus der rrule', CalendarEvent::weekdays( $training ), array( 1, 3 ) );
same( 'Letzter Termin aus UNTIL', CalendarEvent::until( $training ), '2027-07-31' );
same( 'Beginn aus Datum und Uhrzeit', CalendarEvent::start( $single ), '2026-10-04 10:00:00' );
same( 'Ende aus Datum und Uhrzeit', CalendarEvent::end( $single ), '2026-10-04 16:00:00' );
same( 'Ende ohne eigenes Enddatum nimmt den Starttag', CalendarEvent::end( $training ), '2026-09-21 16:00:00' );
same( 'Trainer aus den event_leads', CalendarEvent::leads( $training ), 'Petra Mustermann' );
check( 'Interner Termin ist nicht öffentlich', ! CalendarEvent::is_public( $events[2] ) );
check( 'Unveröffentlichter Termin ist nicht öffentlich', ! CalendarEvent::is_public( $events[3] ) );

Settings::update( array( 'training_rule' => CalendarEvent::RULE_TEAM ) );
check(
	'Regel "alle Gruppentermine" erkennt auch Einzeltermine',
	CalendarEvent::is_training( array( 'team_id' => 'team-1', 'is_recurring' => false ) )
);
Settings::update( array( 'training_rule' => CalendarEvent::RULE_TEAM_RECURRING ) );

// --- Paging, envelopes and errors ------------------------------------------

section( 'Abruf aus der API' );

Settings::update( array( 'per_page' => 2 ) );

respond_with(
	array(
		'categories' => array(
			'__pages' => array(
				1 => array(
					'data' => array( array( 'id' => 1 ), array( 'id' => 2 ) ),
				),
				2 => array(
					'data' => array( array( 'id' => 3 ) ),
				),
			),
		),
	)
);

$client  = new Client();
$records = $client->fetch( Resource::DEPARTMENTS, null );
$urls    = requested_urls();

same( 'Alle Seiten geladen', count( $records ), 3 );
same( 'Zwei Anfragen gestellt', count( $urls ), 2 );
check(
	'Basis-URL und Endpunkt im Aufruf',
	0 === strpos( (string) ( $urls[0] ?? '' ), 'https://api.kurabu.test/v1/clubs/categories?' ),
	(string) ( $urls[0] ?? '' )
);
check(
	'Zweite Seite über den offset',
	false !== strpos( (string) ( $urls[1] ?? '' ), 'offset=2' ),
	(string) ( $urls[1] ?? '' )
);

respond_with( array( 'teams' => array( array( 'id' => 1 ) ) ) );
same( 'Nacktes JSON-Array ist die Liste', count( $client->fetch( Resource::TEAMS, null ) ), 1 );

// An API that ignores the paging parameters would otherwise be asked forever.
respond_with(
	array(
		'locations' => array(
			'items' => array( array( 'id' => 1 ), array( 'id' => 2 ) ),
		),
	)
);
same( 'Gleiche Seite beendet das Blättern', count( $client->fetch( Resource::LOCATIONS, null ) ), 2 );
same( 'Nur zwei Anfragen', count( requested_urls() ), 2 );

respond_with( array(), array( 'articles' ) );

try {
	$client->fetch( Resource::NEWS, null );
	check( 'HTTP-Fehler wirft', false, 'keine Ausnahme' );
} catch ( ApiException $exception ) {
	check( 'HTTP-Fehler wirft', true );
	same( 'Status im Fehler', $exception->status(), 500 );
	same( 'Antwort im Kontext', $exception->context()['response'] ?? '', 'Internal Server Error' );
}

$GLOBALS['kurabu_responder'] = static function (): array {
	return array(
		'status' => 200,
		'body'   => '<html>kein JSON</html>',
	);
};

try {
	$client->fetch( Resource::NEWS, null );
	check( 'Ungültiges JSON wirft', false, 'keine Ausnahme' );
} catch ( ApiException $exception ) {
	check( 'Ungültiges JSON wirft', true );
}

section( 'Inkrementeller Filter' );

$url   = $client->endpoint_url( Resource::NEWS, '2026-09-21 18:00:00', 1 );
$query = query_of( $url );

check( '"geändert seit" wird gesendet', isset( $query['filter']['updated_at']['gt'] ), $url );
same( 'als UTC nach ISO 8601', (string) ( $query['filter']['updated_at']['gt'] ?? '' ), '2026-09-21T16:00:00Z' );
check( 'Seite 1 ohne offset', ! isset( $query['offset'] ), $url );

// --- Cron ------------------------------------------------------------------

section( 'Cron-Planung' );

$schedules = Scheduler::register_schedules( array() );
same( 'Ein Schedule je Intervall', count( $schedules ), count( Settings::INTERVALS ) );
same( '5 Minuten', $schedules['kurabu_wp_sync_5min']['interval'], 300 );
same( '24 Stunden', $schedules['kurabu_wp_sync_24h']['interval'], 86400 );

Settings::update(
	array(
		'sync_enabled'  => true,
		'sync_interval' => '30min',
	)
);
Scheduler::reschedule();
check( 'Lauf ist geplant', Scheduler::is_scheduled() );
same( 'mit dem gewählten Intervall', wp_get_schedule( \Kurabu\WPSync\Plugin::CRON_HOOK ), 'kurabu_wp_sync_30min' );

$planned = Scheduler::next_run();
Scheduler::reschedule();
same( 'Erneutes Speichern verschiebt nichts', Scheduler::next_run(), $planned );

Settings::update( array( 'sync_interval' => '6h' ) );
Scheduler::reschedule();
same( 'Intervallwechsel greift', wp_get_schedule( \Kurabu\WPSync\Plugin::CRON_HOOK ), 'kurabu_wp_sync_6h' );

Settings::update( array( 'sync_enabled' => false ) );
Scheduler::reschedule();
check( 'Ausschalten entplant', ! Scheduler::is_scheduled() );

section( 'Sperre gegen überlappende Läufe' );

check( 'Erster Lauf bekommt die Sperre', Lock::acquire() );
check( 'Zweiter Lauf wird abgewiesen', ! Lock::acquire() );
Lock::release();
check( 'Nach der Freigabe wieder frei', Lock::acquire() );
Lock::release();

section( 'Kalenderziel und Authentifizierung' );

same( 'The Events Calendar ist voreingestellt', Settings::defaults()['calendar_target'], 'the_events_calendar' );

same(
	'Bearer-Token',
	( new BearerAuthenticator() )->headers( 'abc' ),
	array( 'Authorization' => 'Bearer abc' )
);
same(
	'API-Key im Header',
	( new HeaderAuthenticator( 'X-Kurabu-Key' ) )->headers( 'abc' ),
	array( 'X-Kurabu-Key' => 'abc' )
);
same(
	'API-Key als Query-Parameter',
	( new QueryAuthenticator() )->query_args( 'abc' ),
	array( 'api_key' => 'abc' )
);
same(
	'Basic Auth mit Doppelpunkt',
	( new BasicAuthenticator() )->headers( 'user:pass' ),
	array( 'Authorization' => 'Basic ' . base64_encode( 'user:pass' ) )
);

// --- A whole run -----------------------------------------------------------

section( 'Erster, vollständiger Lauf' );

$payloads = club_payloads();

Settings::update(
	array(
		'per_page'        => 100,
		'calendar_target' => 'post_type',
		'event_post_type' => 'page',
		'news_post_type'  => 'post',
	)
);

respond_with( $payloads );

$engine = Engine::instance();
$report = $engine->run( array(), RunReport::MODE_MANUAL );

check( 'Lauf ohne Fehler', ! $report->has_errors(), $report->summary() );
same( 'Alle Datenarten gelaufen', count( $report->results() ), count( Resource::all() ) );

$calendar_calls = array_filter(
	requested_urls(),
	static function ( string $url ): bool {
		return false !== strpos( $url, 'calendar-events' );
	}
);
same( 'Der Kalender wird je Lauf einmal abgefragt', count( $calendar_calls ), 1 );
same( 'Abteilungen im Cache', plugin()->departments()->count(), 2 );
same( 'Teams im Cache', plugin()->teams()->count(), 1 );
same( 'Ort, Anlage und Raum im Cache', plugin()->locations()->count(), 3 );
same( 'Trainings im Cache', plugin()->trainings()->count(), 1 );
same( 'Trainingszeiten im Cache', plugin()->training_times()->count(), 2 );
same( 'News als WordPress-Beitrag', ObjectMap::count( ObjectMap::TYPE_POST ), 1 );
same( 'Event im Kalender', ObjectMap::count( ObjectMap::TYPE_EVENT ), 1 );

$department = plugin()->departments()->find_by_kurabu_id( 'cat-1' );
check( 'Abteilung über die KURABU-ID auffindbar', null !== $department );
same( 'Name übernommen', (string) $department->get( 'name' ), 'Turnen' );
same( 'Slug erzeugt', (string) $department->get( 'slug' ), 'turnen' );
same( 'Ansprechpartner aus Vor- und Nachname', (string) $department->get( 'contact_name' ), 'Petra Mustermann' );
same( 'E-Mail aus contact.email', (string) $department->get( 'contact_email' ), 'turnen@example.org' );
same( 'Telefon aus contact.phone', (string) $department->get( 'contact_phone' ), '09561 123' );
same( 'Rohdatensatz erhalten', $department->payload()['group'] ?? '', 'Breitensport' );
check( 'Shortcode-Referenz über den Slug', null !== plugin()->departments()->find_by_reference( 'turnen' ) );
check( 'Shortcode-Referenz über die ID', null !== plugin()->departments()->find_by_reference( 'cat-1' ) );

$team = plugin()->teams()->find_by_kurabu_id( 'team-1' );
same( 'Team kennt seine Abteilung aus team_ids', (string) $team->get( 'department_kurabu_id' ), 'cat-1' );
same( 'Teams einer Abteilung auffindbar', count( plugin()->teams()->for_department( 'cat-1' ) ), 1 );

$location = plugin()->locations()->find_by_kurabu_id( 'loc-1' );
same( 'Straße aus der Adresse', (string) $location->get( 'street' ), 'Sportweg 1' );
same( 'PLZ aus der Adresse', (string) $location->get( 'postal_code' ), '96450' );
same( 'Ort aus der Adresse', (string) $location->get( 'city' ), 'Coburg' );

$training = plugin()->trainings()->find_by_kurabu_id( 'ev-tr-1' );
check( 'Training aus dem Kalendertermin', null !== $training );
same( 'Training kennt seine Abteilung', (string) $training->get( 'department_kurabu_id' ), 'cat-1' );
same( 'Training kennt sein Team', (string) $training->get( 'team_kurabu_id' ), 'team-1' );
same( 'Training kennt seine Anlage', (string) $training->get( 'location_kurabu_id' ), 'fac-1' );
check( 'und die Anlage liegt im Ortsverzeichnis', null !== plugin()->locations()->find_by_kurabu_id( 'fac-1' ) );
same( 'Trainer aus den event_leads', (string) $training->get( 'trainer' ), 'Petra Mustermann' );

$time = plugin()->training_times()->find_by_kurabu_id( 'ev-tr-1:3' );
check( 'Trainingszeit je Wochentag der rrule', null !== $time );
same( 'Wochentag als ISO-Nummer', (int) $time->get( 'weekday' ), 3 );
same( 'Zeit gehört zum Training', (string) $time->get( 'training_kurabu_id' ), 'ev-tr-1' );
same( 'Beginn normalisiert', (string) $time->get( 'start_time' ), '15:00:00' );
same( 'Ende normalisiert', (string) $time->get( 'end_time' ), '16:00:00' );
same( 'Gültig ab dem ersten Termin', (string) $time->get( 'valid_from' ), '2026-09-21' );
same( 'Gültig bis zum UNTIL der rrule', (string) $time->get( 'valid_until' ), '2027-07-31' );

$post = get_post( ObjectMap::get_wp_id( ObjectMap::TYPE_POST, 'art-1' ) );
same( 'Beitragstitel', $post->post_title, 'Sommerfest' );
same( 'Beitragsdatum aus publishing_date', $post->post_date, '2026-08-01 00:00:00' );
same( 'Auszug aus preview_text', $post->post_excerpt, 'Ein Nachmittag im Park' );
same( 'KURABU-ID am Beitrag', $GLOBALS['kurabu_meta'][ $post->ID ]['_kurabu_id'] ?? '', 'art-1' );
same(
	'Titelbild als URL, nicht als Upload',
	$GLOBALS['kurabu_meta'][ $post->ID ]['_kurabu_image_url'] ?? '',
	'https://cdn.example.org/fest.jpg'
);
same( 'Interner Artikel wird nicht veröffentlicht', ObjectMap::get( ObjectMap::TYPE_POST, 'art-intern' ), null );

$event = get_post( ObjectMap::get_wp_id( ObjectMap::TYPE_EVENT, 'ev-1' ) );
same( 'Event-Titel', $event->post_title, 'Hallenturnier' );
same( 'Event-Beginn aus Datum und Uhrzeit', $GLOBALS['kurabu_meta'][ $event->ID ]['_kurabu_event_start'] ?? '', '2026-10-04 10:00:00' );
same( 'Event-Ende aus Datum und Uhrzeit', $GLOBALS['kurabu_meta'][ $event->ID ]['_kurabu_event_end'] ?? '', '2026-10-04 16:00:00' );
same( 'Event-Ort aus location_info', $GLOBALS['kurabu_meta'][ $event->ID ]['_kurabu_event_location'] ?? '', 'Angerhalle, Coburg' );
same( 'Training landet nicht im Kalender', ObjectMap::get( ObjectMap::TYPE_EVENT, 'ev-tr-1' ), null );
same( 'Interner Termin landet nicht im Kalender', ObjectMap::get( ObjectMap::TYPE_EVENT, 'ev-intern' ), null );
same( 'Unveröffentlichter Termin landet nicht im Kalender', ObjectMap::get( ObjectMap::TYPE_EVENT, 'ev-entwurf' ), null );

$state = SyncState::get( Resource::DEPARTMENTS );
same( 'Status erfolgreich', (string) ( $state['last_status'] ?? '' ), SyncState::STATUS_SUCCESS );
check( 'Letzter Erfolg vermerkt', '' !== (string) SyncState::last_success( Resource::DEPARTMENTS ) );

section( 'Zweiter Lauf ohne Änderungen' );

$rows_before  = $GLOBALS['wpdb']->data['wp_kurabu_departments'];
$posts_before = count( $GLOBALS['kurabu_posts'] );

$engine->run( array(), RunReport::MODE_MANUAL, true );

same( 'Keine doppelten Abteilungen', plugin()->departments()->count(), 2 );
same( 'Keine doppelten Beiträge', count( $GLOBALS['kurabu_posts'] ), $posts_before );
same( 'Keine doppelten Events', ObjectMap::count( ObjectMap::TYPE_EVENT ), 1 );
same( 'Keine doppelten Trainings', plugin()->trainings()->count(), 1 );
same( 'Keine doppelten Trainingszeiten', plugin()->training_times()->count(), 2 );
same( 'Keine doppelten Orte', plugin()->locations()->count(), 3 );

$rows_after = $GLOBALS['wpdb']->data['wp_kurabu_departments'];
same( 'Prüfsumme unverändert', $rows_after[0]['checksum'], $rows_before[0]['checksum'] );
same( 'Zeile nicht neu geschrieben', $rows_after[0]['updated_at'], $rows_before[0]['updated_at'] );

section( 'Geänderter und gelöschter Datensatz' );

$payloads['categories']['data'][0]['name'] = 'Turnen und Gymnastik';
respond_with( $payloads );
$engine->run( array( Resource::DEPARTMENTS ), RunReport::MODE_MANUAL, true );

same(
	'Änderung übernommen',
	(string) plugin()->departments()->find_by_kurabu_id( 'cat-1' )->get( 'name' ),
	'Turnen und Gymnastik'
);
same( 'Weiterhin zwei Abteilungen', plugin()->departments()->count(), 2 );

array_pop( $payloads['categories']['data'] );
respond_with( $payloads );
$engine->run( array( Resource::DEPARTMENTS ), RunReport::MODE_MANUAL, true );

same( 'Vollständiger Lauf entfernt Verschwundenes', plugin()->departments()->count(), 1 );

section( 'Inkrementeller Lauf löscht nie' );

$payloads['teams']['data'] = array();
respond_with( $payloads );

$result = $engine->run( array( Resource::TEAMS ), RunReport::MODE_MANUAL )->results()[ Resource::TEAMS ];

check( 'Lauf war inkrementell', $result->is_incremental() );
same( 'Team bleibt im Cache', plugin()->teams()->count(), 1 );

section( 'API-Fehler' );

$payloads = club_payloads();
respond_with( $payloads, array( 'calendar-events' ) );

$trainings_before = plugin()->trainings()->count();
$last_success     = SyncState::last_success( Resource::TRAININGS );

$report = $engine->run( array( Resource::TRAININGS, Resource::LOCATIONS ), RunReport::MODE_MANUAL, true );

check( 'Bericht meldet den Fehler', $report->has_errors() );
check( 'Trainings fehlgeschlagen', ! $report->results()[ Resource::TRAININGS ]->is_success() );
check( 'Trainingsorte trotzdem erfolgreich', $report->results()[ Resource::LOCATIONS ]->is_success() );
same( 'Datenbestand bleibt erhalten', plugin()->trainings()->count(), $trainings_before );
same( 'Letzter Erfolg unberührt', SyncState::last_success( Resource::TRAININGS ), $last_success );
same(
	'Status auf Fehler',
	(string) ( SyncState::get( Resource::TRAININGS )['last_status'] ?? '' ),
	SyncState::STATUS_ERROR
);

$errors = Logger::recent( 50, Logger::ERROR );
check( 'Fehler steht im Protokoll', array() !== $errors );
same( 'Protokoll nennt die Datenart', (string) ( $errors[0]['resource'] ?? '' ), Resource::TRAININGS );

section( 'Fehlende Voraussetzungen' );

respond_with( club_payloads() );
Settings::update( array( 'calendar_target' => '' ) );

$result = $engine->run( array( Resource::EVENTS ), RunReport::MODE_MANUAL, true )->results()[ Resource::EVENTS ];

check( 'Ohne Kalenderziel übersprungen, nicht fehlgeschlagen', $result->is_success() );
check( 'Meldung erklärt warum', false !== strpos( $result->summary(), 'Kalenderziel' ), $result->summary() );

Settings::update(
	array(
		'api_base_url' => '',
		'api_token'    => '',
	)
);

$departments_before = plugin()->departments()->count();
$report             = $engine->run( array( Resource::DEPARTMENTS ), RunReport::MODE_MANUAL );

same( 'Ohne Zugangsdaten läuft nichts', count( $report->results() ), 0 );
check( 'Bericht nennt den Grund', false !== strpos( $report->summary(), 'Token' ), $report->summary() );
same( 'Cache unberührt', plugin()->departments()->count(), $departments_before );

Settings::update(
	array(
		'api_base_url'    => 'https://api.kurabu.test/v1',
		'api_token'       => 'geheim',
		'calendar_target' => 'post_type',
	)
);

section( 'Beitrag im Papierkorb' );

$payloads = club_payloads();
$news_id  = ObjectMap::get_wp_id( ObjectMap::TYPE_POST, 'art-1' );

$GLOBALS['kurabu_posts'][ $news_id ]->post_status  = 'trash';
$payloads['articles']['data'][0]['title']          = 'Sommerfest 2026';

respond_with( $payloads );
$engine->run( array( Resource::NEWS ), RunReport::MODE_MANUAL, true );

same( 'Papierkorb wird nicht überschrieben', $GLOBALS['kurabu_posts'][ $news_id ]->post_title, 'Sommerfest' );

$GLOBALS['kurabu_posts'][ $news_id ]->post_status = 'publish';

// --- The screens that drive all of this ------------------------------------

section( 'Backend-Seiten' );

$GLOBALS['kurabu_can_manage'] = true;

$screens = array(
	'API-Konfiguration'      => \Kurabu\WPSync\Admin\Pages\SettingsPage::class,
	'Synchronisation'        => \Kurabu\WPSync\Admin\Pages\SyncPage::class,
	'Synchronisationsstatus' => \Kurabu\WPSync\Admin\Pages\StatusPage::class,
	'Fehlerprotokoll'        => \Kurabu\WPSync\Admin\Pages\LogPage::class,
	'Mapping'                => \Kurabu\WPSync\Admin\Pages\MappingPage::class,
	'Kalenderintegration'    => \Kurabu\WPSync\Admin\Pages\CalendarPage::class,
);

foreach ( $screens as $label => $class ) {
	ob_start();

	try {
		$screen = new $class();
		$screen->handle_request();
		$screen->render();

		$html = (string) ob_get_clean();

		check( sprintf( '%s rendert', $label ), '' !== trim( $html ), 'keine Ausgabe' );
	} catch ( \Throwable $throwable ) {
		ob_end_clean();

		check( sprintf( '%s rendert', $label ), false, $throwable->getMessage() );
	}
}

printf( "\n%d Prüfungen, %d Fehler\n", $checks, $failures );

exit( 0 === $failures ? 0 : 1 );
