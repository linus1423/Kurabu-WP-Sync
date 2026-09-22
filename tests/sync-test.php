<?php
/**
 * Runs the sync layer against a simulated KURABU API.
 *
 * Run with: php tests/sync-test.php
 *
 * The real KURABU API is not documented yet and there is no access to it, so
 * the tests install a canned transport and let the real client, mapper,
 * handlers, repositories and engine work on its answers. What is checked is
 * the behaviour the specification asks for: recognising records by their
 * KURABU id, keeping the last good data when the API fails, and never
 * deleting on an incremental run.
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

		// A payload given as a list of pages is served page by page.
		if ( isset( $answer['__pages'] ) ) {
			$page   = (int) ( $query['page'] ?? 1 );
			$answer = $answer['__pages'][ $page ] ?? array( 'data' => array() );
		}

		return array(
			'status' => 200,
			'body'   => (string) wp_json_encode( $answer ),
		);
	};
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
 * The answers a full club looks like, enough for every resource.
 *
 * @return array<string, mixed>
 */
function club_payloads(): array {
	return array(
		'departments'    => array(
			array(
				'id'          => 'dep-1',
				'name'        => 'Turnen',
				'description' => 'Abteilung Turnen',
				'contact'     => array(
					'name'  => 'Petra M.',
					'email' => 'turnen@example.org',
				),
			),
			array(
				'id'       => 'dep-2',
				'name'     => 'Leichtathletik',
				'position' => 2,
			),
		),
		'teams'          => array(
			array(
				'id'            => 'team-1',
				'name'          => 'Jugend',
				'department_id' => 'dep-1',
			),
		),
		'locations'      => array(
			array(
				'id'      => 'loc-1',
				'name'    => 'HUK-Halle',
				'strasse' => 'Sportweg 1',
				'plz'     => '96450',
				'ort'     => 'Coburg',
			),
		),
		'trainings'      => array(
			array(
				'id'         => 'tr-1',
				'name'       => 'Eltern-Kind-Turnen',
				'department' => array( 'id' => 'dep-1' ),
				'team_id'    => 'team-1',
				'location'   => array(
					'id'   => 'loc-1',
					'name' => 'HUK-Halle',
				),
				'trainer'    => array( 'name' => 'Petra M.' ),
			),
		),
		'training-times' => array(
			array(
				'id'          => 'tt-1',
				'training_id' => 'tr-1',
				'wochentag'   => 'Montag',
				'von'         => '15:00',
				'bis'         => '16:00',
			),
			array(
				'id'          => 'tt-2',
				'training_id' => 'tr-1',
				'wochentag'   => 'Mittwoch',
				'von'         => '17:00',
				'bis'         => '18:30',
			),
		),
		'news'           => array(
			array(
				'id'      => 'news-1',
				'title'   => 'Sommerfest',
				'content' => '<p>Es war schön.</p>',
				'date'    => '2026-08-01 10:00:00',
			),
		),
		'events'         => array(
			array(
				'id'       => 'ev-1',
				'title'    => 'Hallenturnier',
				'start'    => '2026-10-04T10:00:00+02:00',
				'end'      => '2026-10-04T16:00:00+02:00',
				'location' => array( 'name' => 'Angerhalle' ),
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
same( 'Zurücksetzen stellt den Standard her', FieldMap::endpoint( Resource::DEPARTMENTS ), 'departments' );

// --- Paging, envelopes and errors ------------------------------------------

section( 'Abruf aus der API' );

Settings::update( array( 'per_page' => 2 ) );

respond_with(
	array(
		'departments' => array(
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
	0 === strpos( (string) ( $urls[0] ?? '' ), 'https://api.kurabu.test/v1/departments?' ),
	(string) ( $urls[0] ?? '' )
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

respond_with( array(), array( 'news' ) );

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
$query = array();
parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

check( '"geändert seit" wird gesendet', isset( $query['modified_since'] ), $url );
same( 'als UTC nach ISO 8601', (string) ( $query['modified_since'] ?? '' ), '2026-09-21T16:00:00+00:00' );
check( 'Seite 1 ohne Seitenparameter', ! isset( $query['page'] ), $url );

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

section( 'Authentifizierung' );

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
same( 'Abteilungen im Cache', plugin()->departments()->count(), 2 );
same( 'Teams im Cache', plugin()->teams()->count(), 1 );
same( 'Trainingsorte im Cache', plugin()->locations()->count(), 1 );
same( 'Trainings im Cache', plugin()->trainings()->count(), 1 );
same( 'Trainingszeiten im Cache', plugin()->training_times()->count(), 2 );
same( 'News als WordPress-Beitrag', ObjectMap::count( ObjectMap::TYPE_POST ), 1 );
same( 'Event im Kalender', ObjectMap::count( ObjectMap::TYPE_EVENT ), 1 );

$department = plugin()->departments()->find_by_kurabu_id( 'dep-1' );
check( 'Abteilung über die KURABU-ID auffindbar', null !== $department );
same( 'Name übernommen', (string) $department->get( 'name' ), 'Turnen' );
same( 'Slug erzeugt', (string) $department->get( 'slug' ), 'turnen' );
same( 'Ansprechpartner aus contact.name', (string) $department->get( 'contact_name' ), 'Petra M.' );
same( 'E-Mail aus contact.email', (string) $department->get( 'contact_email' ), 'turnen@example.org' );
same( 'Rohdatensatz erhalten', $department->payload()['name'] ?? '', 'Turnen' );
check( 'Shortcode-Referenz über den Slug', null !== plugin()->departments()->find_by_reference( 'turnen' ) );
check( 'Shortcode-Referenz über die ID', null !== plugin()->departments()->find_by_reference( 'dep-1' ) );

$training = plugin()->trainings()->find_by_kurabu_id( 'tr-1' );
same( 'Training kennt seine Abteilung', (string) $training->get( 'department_kurabu_id' ), 'dep-1' );
same( 'Training kennt sein Team', (string) $training->get( 'team_kurabu_id' ), 'team-1' );
same( 'Training kennt seinen Ort', (string) $training->get( 'location_kurabu_id' ), 'loc-1' );
same( 'Trainer aus trainer.name', (string) $training->get( 'trainer' ), 'Petra M.' );

$time = plugin()->training_times()->find_by_kurabu_id( 'tt-2' );
same( 'Wochentag als ISO-Nummer', (int) $time->get( 'weekday' ), 3 );
same( 'Beginn normalisiert', (string) $time->get( 'start_time' ), '17:00:00' );

$post = get_post( ObjectMap::get_wp_id( ObjectMap::TYPE_POST, 'news-1' ) );
same( 'Beitragstitel', $post->post_title, 'Sommerfest' );
same( 'Beitragsdatum', $post->post_date, '2026-08-01 10:00:00' );
same( 'KURABU-ID am Beitrag', $GLOBALS['kurabu_meta'][ $post->ID ]['_kurabu_id'] ?? '', 'news-1' );

$event = get_post( ObjectMap::get_wp_id( ObjectMap::TYPE_EVENT, 'ev-1' ) );
same( 'Event-Titel', $event->post_title, 'Hallenturnier' );
same( 'Event-Beginn in Ortszeit', $GLOBALS['kurabu_meta'][ $event->ID ]['_kurabu_event_start'] ?? '', '2026-10-04 10:00:00' );
same( 'Event-Ort', $GLOBALS['kurabu_meta'][ $event->ID ]['_kurabu_event_location'] ?? '', 'Angerhalle' );

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

$rows_after = $GLOBALS['wpdb']->data['wp_kurabu_departments'];
same( 'Prüfsumme unverändert', $rows_after[0]['checksum'], $rows_before[0]['checksum'] );
same( 'Zeile nicht neu geschrieben', $rows_after[0]['updated_at'], $rows_before[0]['updated_at'] );

section( 'Geänderter und gelöschter Datensatz' );

$payloads['departments'][0]['name'] = 'Turnen und Gymnastik';
respond_with( $payloads );
$engine->run( array( Resource::DEPARTMENTS ), RunReport::MODE_MANUAL, true );

same(
	'Änderung übernommen',
	(string) plugin()->departments()->find_by_kurabu_id( 'dep-1' )->get( 'name' ),
	'Turnen und Gymnastik'
);
same( 'Weiterhin zwei Abteilungen', plugin()->departments()->count(), 2 );

array_pop( $payloads['departments'] );
respond_with( $payloads );
$engine->run( array( Resource::DEPARTMENTS ), RunReport::MODE_MANUAL, true );

same( 'Vollständiger Lauf entfernt Verschwundenes', plugin()->departments()->count(), 1 );

section( 'Inkrementeller Lauf löscht nie' );

$payloads['teams'] = array();
respond_with( $payloads );

$result = $engine->run( array( Resource::TEAMS ), RunReport::MODE_MANUAL )->results()[ Resource::TEAMS ];

check( 'Lauf war inkrementell', $result->is_incremental() );
same( 'Team bleibt im Cache', plugin()->teams()->count(), 1 );

section( 'API-Fehler' );

$payloads = club_payloads();
respond_with( $payloads, array( 'trainings' ) );

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
$news_id  = ObjectMap::get_wp_id( ObjectMap::TYPE_POST, 'news-1' );

$GLOBALS['kurabu_posts'][ $news_id ]->post_status = 'trash';
$payloads['news'][0]['title']                     = 'Sommerfest 2026';

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
