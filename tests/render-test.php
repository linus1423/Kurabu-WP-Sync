<?php
/**
 * Renders the shortcodes against self-made test data.
 *
 * Run with: php tests/render-test.php
 *
 * The KURABU synchronisation does not exist yet, so the tests seed the local
 * tables themselves and then let the real shortcodes, repositories and the
 * template engine work on them.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Tests;

use Kurabu\WPSync\Admin\TemplateEditor;
use Kurabu\WPSync\Database\Repository\AbstractRepository;
use Kurabu\WPSync\Shortcode\Format;
use Kurabu\WPSync\Shortcode\ShortcodeManager;
use Kurabu\WPSync\Template\Element;
use Kurabu\WPSync\Template\Renderer;
use Kurabu\WPSync\Template\SampleData;
use Kurabu\WPSync\Template\TemplateStore;

require_once __DIR__ . '/bootstrap.php';

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

	++$checks;

	if ( $condition ) {
		echo "  ok   {$what}\n";

		return;
	}

	++$failures;

	echo "  FAIL {$what}";
	echo '' !== $detail ? "\n       {$detail}\n" : "\n";
}

/**
 * Asserts that a string contains a needle.
 *
 * @param string $what     What is being checked.
 * @param string $haystack The rendered output.
 * @param string $needle   Expected substring.
 */
function contains( string $what, string $haystack, string $needle ): void {
	check( $what, false !== strpos( $haystack, $needle ), 'nicht gefunden: ' . $needle . "\n       in: " . $haystack );
}

/**
 * Fills the local tables with test data.
 */
function seed(): void {
	global $wpdb;

	$common = array( 'id', 'kurabu_id', 'slug', 'name', 'payload', 'checksum', 'synced_at', 'created_at', 'updated_at', 'menu_order' );

	$wpdb->seed(
		'wp_kurabu_departments',
		array_merge( $common, array( 'description', 'image_url', 'contact_name', 'contact_email', 'contact_phone', 'link' ) ),
		array(
			array(
				'id'            => 1,
				'kurabu_id'     => 'dep-1',
				'slug'          => 'turnen',
				'name'          => 'Turnen',
				'description'   => '<p>Von der Eltern-Kind-Gruppe bis zum Gerätturnen.</p>',
				'contact_name'  => 'Maria Beispiel',
				'contact_email' => 'turnen@example.org',
				'contact_phone' => '09561 123456',
				'menu_order'    => 1,
				'payload'       => wp_json_encode( array( 'kurabu_id' => 'dep-1' ) ),
			),
		)
	);

	$wpdb->seed(
		'wp_kurabu_teams',
		array_merge( $common, array( 'department_kurabu_id', 'description' ) ),
		array(
			array(
				'id'                   => 1,
				'kurabu_id'            => 'team-kids',
				'slug'                 => 'kinder',
				'name'                 => 'Turnen Kinder',
				'department_kurabu_id' => 'dep-1',
				'menu_order'           => 1,
			),
			array(
				'id'                   => 2,
				'kurabu_id'            => 'team-jugend',
				'slug'                 => 'jugend',
				'name'                 => 'Jugend',
				'department_kurabu_id' => 'dep-1',
				'menu_order'           => 2,
			),
		)
	);

	$wpdb->seed(
		'wp_kurabu_locations',
		array_merge( $common, array( 'room', 'street', 'postal_code', 'city', 'latitude', 'longitude', 'notes' ) ),
		array(
			array(
				'id'          => 1,
				'kurabu_id'   => 'loc-huk',
				'slug'        => 'huk-halle',
				'name'        => 'HUK-Halle',
				'room'        => 'Gymnastikraum',
				'street'      => 'Am Sportplatz 1',
				'postal_code' => '96450',
				'city'        => 'Coburg',
			),
		)
	);

	$wpdb->seed(
		'wp_kurabu_trainings',
		array_merge( $common, array( 'department_kurabu_id', 'team_kurabu_id', 'location_kurabu_id', 'description', 'trainer', 'image_url', 'link' ) ),
		array(
			array(
				'id'                   => 1,
				'kurabu_id'            => '12345',
				'slug'                 => 'eltern-kind-turnen',
				'name'                 => 'Eltern-Kind-Turnen',
				'department_kurabu_id' => 'dep-1',
				'team_kurabu_id'       => 'team-kids',
				'location_kurabu_id'   => 'loc-huk',
				'trainer'              => 'Maria Beispiel',
				'description'          => '<p>Spielerische Bewegung für Kinder von zwei bis vier Jahren.</p>',
				'link'                 => 'https://example.org/turnen/eltern-kind-turnen',
				'menu_order'           => 1,
			),
			array(
				'id'                   => 2,
				'kurabu_id'            => '12346',
				'slug'                 => 'geraetturnen',
				'name'                 => 'Gerätturnen',
				'department_kurabu_id' => 'dep-1',
				'team_kurabu_id'       => 'team-jugend',
				'location_kurabu_id'   => 'loc-huk',
				'menu_order'           => 2,
			),
			array(
				'id'                   => 3,
				'kurabu_id'            => '12347',
				'slug'                 => 'boeses-training',
				'name'                 => 'Turnen <script>alert(1)</script>',
				'department_kurabu_id' => 'dep-2',
				'menu_order'           => 3,
			),
		)
	);

	$wpdb->seed(
		'wp_kurabu_training_times',
		array_merge( $common, array( 'training_kurabu_id', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_until', 'notes' ) ),
		array(
			array(
				'id'                 => 1,
				'kurabu_id'          => 'time-1',
				'training_kurabu_id' => '12345',
				'weekday'            => 1,
				'start_time'         => '15:00:00',
				'end_time'           => '16:00:00',
			),
			array(
				'id'                 => 2,
				'kurabu_id'          => 'time-2',
				'training_kurabu_id' => '12345',
				'weekday'            => 4,
				'start_time'         => '16:00:00',
				'end_time'           => '17:00:00',
			),
			array(
				'id'                 => 3,
				'kurabu_id'          => 'time-3',
				'training_kurabu_id' => '12346',
				'weekday'            => 2,
				'start_time'         => '17:00:00',
				'end_time'           => '18:30:00',
			),
		)
	);

	$wpdb->seed(
		'wp_kurabu_sync_state',
		array( 'id', 'resource', 'last_attempt_at', 'last_success_at', 'last_cursor', 'last_status', 'items_synced', 'message', 'updated_at' ),
		array(
			array(
				'id'              => 1,
				'resource'        => 'trainings',
				'last_success_at' => '2026-09-22 09:00:00',
				'last_status'     => 'success',
			),
		)
	);

	AbstractRepository::flush_columns_cache();
}

seed();
ShortcodeManager::register();

// The cache is exercised in its own section; everything else runs without it.
add_filter(
	'kurabu_wp_sync_shortcode_cache_ttl',
	static function (): int {
		return 0;
	}
);

echo "Formatierung\n";

check( 'Wochentag 1 ist Montag', 'Montag' === Format::weekday( 1 ) );
check( 'Wochentag 0 ist Sonntag', 'Sonntag' === Format::weekday( 0 ) );
check( 'Wochentag 9 bleibt leer', '' === Format::weekday( 9 ) );
check( 'Zeitspanne wird gekürzt', '15:00 – 16:00' === Format::time_range( '15:00:00', '16:00:00' ) );
check( 'Zeitspanne ohne Ende', '15:00' === Format::time_range( '15:00:00', '' ) );

echo "\n[kurabu_training]\n";

$single = \kurabu_do_shortcode( 'kurabu_training', array( 'id' => '12345' ) );

contains( 'Name des Trainings', $single, 'Eltern-Kind-Turnen' );
contains( 'Trainingsgruppe', $single, 'Turnen Kinder' );
contains( 'Wochentag der ersten Zeit', $single, 'Montag' );
contains( 'Uhrzeit', $single, '15:00 – 16:00' );
contains( 'Trainingsort', $single, 'HUK-Halle' );
contains( 'Beschreibung als HTML', $single, '<p>Spielerische Bewegung' );
contains( 'Vorlagenklasse', $single, 'kurabu-template--training-standard' );
check( 'genau ein Trainingsblock', 1 === substr_count( $single, 'kurabu-el--name' ) );

$by_slug = \kurabu_do_shortcode( 'kurabu_training', array( 'id' => 'eltern-kind-turnen' ) );

check( 'Slug liefert dasselbe wie die KURABU-ID', $by_slug === $single );

$by_team = \kurabu_do_shortcode( 'kurabu_training', array( 'team' => 'jugend' ) );

contains( 'Auswahl über das Team', $by_team, 'Gerätturnen' );
check( 'Auswahl über das Team zeigt nur dieses Training', false === strpos( $by_team, 'Eltern-Kind-Turnen' ) );

$compact = \kurabu_do_shortcode(
	'kurabu_training',
	array(
		'id'       => '12345',
		'template' => 'compact',
	)
);

contains( 'Kompakt-Vorlage listet alle Zeiten', $compact, 'Montag 15:00 – 16:00' );
contains( 'Kompakt-Vorlage listet die zweite Zeit', $compact, 'Donnerstag 16:00 – 17:00' );
check( 'Kompakt-Vorlage ohne Beschreibung', false === strpos( $compact, 'Spielerische Bewegung' ) );

$unknown = \kurabu_do_shortcode( 'kurabu_training', array( 'id' => 'gibtsnicht' ) );

check( 'unbekannte Kennung bleibt für Besucher stumm', '' === $unknown );

$GLOBALS['kurabu_can_manage'] = true;
$unknown_admin                = \kurabu_do_shortcode( 'kurabu_training', array( 'id' => 'gibtsnicht' ) );
$GLOBALS['kurabu_can_manage'] = false;

contains( 'Redaktion sieht den Hinweis', $unknown_admin, 'Kein Training mit der Kennung' );

$escaped = \kurabu_do_shortcode( 'kurabu_training', array( 'id' => '12347' ) );

check( 'Skript im Namen wird escaped', false === strpos( $escaped, '<script>' ) );
contains( 'Skript erscheint als Text', $escaped, '&lt;script&gt;' );

echo "\n[kurabu_trainings]\n";

$list = \kurabu_do_shortcode( 'kurabu_trainings', array( 'department' => 'turnen' ) );

contains( 'Liste enthält das erste Training', $list, 'Eltern-Kind-Turnen' );
contains( 'Liste enthält das zweite Training', $list, 'Gerätturnen' );
check( 'Liste hat zwei Einträge', 2 === substr_count( $list, 'kurabu-trainings__item' ) );
check( 'fremde Abteilung bleibt draußen', false === strpos( $list, 'alert(1)' ) );

$limited = \kurabu_do_shortcode(
	'kurabu_trainings',
	array(
		'department' => 'turnen',
		'limit'      => '1',
	)
);

check( 'limit begrenzt die Liste', 1 === substr_count( $limited, 'kurabu-trainings__item' ) );

$sorted = \kurabu_do_shortcode(
	'kurabu_trainings',
	array(
		'department' => 'turnen',
		'orderby'    => 'name',
	)
);

check(
	'orderby=name sortiert alphabetisch',
	strpos( $sorted, 'Eltern-Kind-Turnen' ) < strpos( $sorted, 'Gerätturnen' )
);

echo "\n[kurabu_department]\n";

$department = \kurabu_do_shortcode(
	'kurabu_department',
	array(
		'id'        => 'turnen',
		'trainings' => 'true',
	)
);

contains( 'Name der Abteilung', $department, 'Turnen' );
contains( 'Beschreibung der Abteilung', $department, 'Von der Eltern-Kind-Gruppe' );
contains( 'Überschrift aus der Vorlage', $department, 'Trainingsangebote' );
contains( 'Ansprechpartner', $department, 'Maria Beispiel' );
contains( 'E-Mail als Link', $department, 'mailto:turnen@example.org' );
contains( 'Telefon als Link', $department, 'tel:09561123456' );
check( 'beide Trainings sind enthalten', 2 === substr_count( $department, 'kurabu-trainings__item' ) );
contains( 'Trainings nutzen die Training-Vorlage', $department, 'kurabu-template--training-standard' );
check( 'leere News bleiben unsichtbar', false === strpos( $department, 'kurabu-news' ) );

$without_trainings = \kurabu_do_shortcode(
	'kurabu_department',
	array(
		'id'        => 'turnen',
		'trainings' => 'false',
	)
);

check( 'trainings="false" lässt die Trainings weg', false === strpos( $without_trainings, 'kurabu-trainings' ) );
check( 'trainings="false" lässt auch die Überschrift weg', false === strpos( $without_trainings, 'Trainingsangebote' ) );

add_filter(
	'kurabu_wp_sync_department_news',
	static function (): array {
		return array( '<article>Turnfest 2026</article>' );
	}
);

$with_news = \kurabu_do_shortcode(
	'kurabu_department',
	array(
		'id'   => 'turnen',
		'news' => 'true',
	)
);

contains( 'News kommen aus dem Filter', $with_news, 'Turnfest 2026' );
remove_all_filters( 'kurabu_wp_sync_department_news' );

$overview = \kurabu_do_shortcode(
	'kurabu_department',
	array(
		'id'       => 'turnen',
		'template' => 'overview',
	)
);

contains( 'Übersicht listet die Gruppen', $overview, 'Turnen Kinder' );
contains( 'Übersicht listet die Trainingsorte', $overview, 'HUK-Halle' );
check( 'Übersicht ohne Trainings', false === strpos( $overview, 'kurabu-trainings' ) );

echo "\nVorlagen\n";

$standard = TemplateStore::resolve( TemplateStore::TYPE_TRAINING );

check( 'Standard-Vorlage ist mitgeliefert', null !== $standard && TemplateStore::is_default( TemplateStore::TYPE_TRAINING, 'standard' ) );
check( 'unbekannte Vorlage fällt auf Standard zurück', 'standard' === TemplateStore::resolve( TemplateStore::TYPE_TRAINING, 'gibtsnicht' )->key() );

// Hide the description and move the location to the top, as the backend does.
$reduced  = array();
$location = null;

foreach ( $standard->elements() as $element ) {
	if ( 'description' === $element->field_key() ) {
		$reduced[] = $element->with_options( array( 'visible' => false ) );
		continue;
	}

	if ( 'location' === $element->field_key() ) {
		$location = $element;
		continue;
	}

	$reduced[] = $element;
}

array_unshift( $reduced, $location );

TemplateStore::save( $standard->with_elements( $reduced )->with_name( 'Standard (angepasst)' ) );

$changed = \kurabu_do_shortcode( 'kurabu_training', array( 'id' => '12345' ) );

check( 'ausgeblendetes Element verschwindet', false === strpos( $changed, 'Spielerische Bewegung' ) );
check( 'geänderte Reihenfolge greift', strpos( $changed, 'HUK-Halle' ) < strpos( $changed, 'Eltern-Kind-Turnen' ) );
check( 'Vorlage gilt als angepasst', ! TemplateStore::is_default( TemplateStore::TYPE_TRAINING, 'standard' ) );

TemplateStore::delete( TemplateStore::TYPE_TRAINING, 'standard' );

$restored = \kurabu_do_shortcode( 'kurabu_training', array( 'id' => '12345' ) );

contains( 'Zurücksetzen stellt die Auslieferung wieder her', $restored, 'Spielerische Bewegung' );

$new_template = TemplateStore::resolve( TemplateStore::TYPE_TRAINING )->with_elements(
	array(
		Element::field( 'name', array( 'tag' => 'h3' ) ),
		new Element( Element::TYPE_BUTTON, array( 'text' => 'Anmelden', 'url' => 'https://example.org/anmeldung' ) ),
		new Element( Element::TYPE_BUTTON, array( 'text' => 'Ohne Ziel' ) ),
	)
);

$custom = Renderer::render( $new_template, SampleData::training_context() );

contains( 'Button wird gerendert', $custom, '<a class="kurabu-button" href="https://example.org/anmeldung">' );
check( 'Button ohne Ziel wird weggelassen', false === strpos( $custom, 'Ohne Ziel' ) );

$columns = $new_template->with_elements(
	array(
		Element::field( 'location', array( 'width' => 'half' ) ),
		Element::field( 'room', array( 'width' => 'half' ) ),
		Element::field( 'name' ),
	)
);

$column_html = Renderer::render( $columns, SampleData::training_context() );

check( 'Spalten werden in eine Reihe gefasst', 1 === substr_count( $column_html, 'kurabu-row' ) );
contains( 'volle Breite bleibt außerhalb der Reihe', $column_html, '</div></div><div class="kurabu-el kurabu-el--field kurabu-space--small kurabu-el--name"' );

echo "\nBaukasten-Formular\n";

$posted = array(
	1 => array(
		'type'      => 'field',
		'field'     => 'name',
		'visible'   => '1',
		'tag'       => 'h3',
		'width'     => 'kaputt',
		'spacing'   => 'large',
		'css_class' => 'mein-titel',
	),
	0 => array(
		'type'    => 'heading',
		'text'    => 'Trainingszeiten',
		'tag'     => 'h4',
		'visible' => '',
	),
);

$parsed = TemplateEditor::elements_from_post( $posted );

check( 'Reihenfolge folgt den Formularschlüsseln', 'heading' === $parsed[0]->type() && 'name' === $parsed[1]->field_key() );
check( 'fehlendes Häkchen blendet aus', ! $parsed[0]->is_visible() );
check( 'unerlaubte Breite fällt auf full zurück', 'full' === $parsed[1]->width() );
check( 'Abstand wird übernommen', 'large' === $parsed[1]->spacing() );
check( 'CSS-Klasse wird übernommen', 'mein-titel' === $parsed[1]->option( 'css_class' ) );

$added = TemplateEditor::element_from_choice( TemplateStore::TYPE_TRAINING, 'field:trainer' );

check( 'Feld aus der Auswahl wird angelegt', null !== $added && 'trainer' === $added->field_key() );
check( 'unbekanntes Feld wird abgelehnt', null === TemplateEditor::element_from_choice( TemplateStore::TYPE_TRAINING, 'field:gibtsnicht' ) );
check( 'Trainings-Element gibt es nur bei Abteilungen', null === TemplateEditor::element_from_choice( TemplateStore::TYPE_TRAINING, 'trainings' ) );

echo "\nZwischenspeicher\n";

remove_all_filters( 'kurabu_wp_sync_shortcode_cache_ttl' );

$before = \kurabu_do_shortcode( 'kurabu_training', array( 'id' => '12345' ) );

$GLOBALS['wpdb']->data['wp_kurabu_trainings'][0]['name'] = 'Umbenanntes Training';

$cached = \kurabu_do_shortcode( 'kurabu_training', array( 'id' => '12345' ) );

check( 'zweiter Aufruf kommt aus dem Zwischenspeicher', $cached === $before );

TemplateStore::save( TemplateStore::resolve( TemplateStore::TYPE_TRAINING )->with_name( 'Standard' ) );

$after = \kurabu_do_shortcode( 'kurabu_training', array( 'id' => '12345' ) );

contains( 'gespeicherte Vorlage verwirft den Zwischenspeicher', $after, 'Umbenanntes Training' );

echo "\nTrennung der Schichten\n";

check( 'kein Aufruf der KURABU-API beim Rendern', 0 === $GLOBALS['kurabu_http_requests'] );
check( 'die Sync-Schicht wurde nicht geladen', ! class_exists( 'Kurabu\WPSync\Sync\Client', false ) );

echo "\n";
printf( "%d Prüfungen, %d Fehler\n", (int) $checks, (int) $failures );

exit( $failures > 0 ? 1 : 0 );
