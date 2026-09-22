<?php
/**
 * What the plugin needs from KURABU, per resource.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Mapping;

use Kurabu\WPSync\Sync\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The single place that knows how KURABU names things.
 *
 * Every target field lists candidate KURABU field names instead of one fixed
 * name: the first candidate present in a record wins. That keeps the sync
 * working across the German and English spellings an API may use, and the
 * "Mapping" backend screen can pin an exact name per field once the KURABU
 * documentation is at hand. Dots address nested values, e.g. `location.id`.
 */
final class Definition {

	public const TYPE_TEXT     = 'text';
	public const TYPE_HTML     = 'html';
	public const TYPE_ID       = 'id';
	public const TYPE_INT      = 'int';
	public const TYPE_FLOAT    = 'float';
	public const TYPE_BOOL     = 'bool';
	public const TYPE_URL      = 'url';
	public const TYPE_EMAIL    = 'email';
	public const TYPE_DATE     = 'date';
	public const TYPE_TIME     = 'time';
	public const TYPE_DATETIME = 'datetime';
	public const TYPE_WEEKDAY  = 'weekday';

	/**
	 * Cached field definitions.
	 *
	 * @var array<string, array<string, array{label: string, type: string, candidates: string[]}>>|null
	 */
	private static ?array $cache = null;

	/**
	 * The default endpoint path per resource, relative to the API base URL.
	 *
	 * These are informed guesses until the KURABU documentation is available;
	 * they are meant to be corrected on the "Mapping" screen without touching
	 * any code.
	 *
	 * @return array<string, string>
	 */
	public static function endpoints(): array {
		return array(
			Resource::DEPARTMENTS    => 'departments',
			Resource::TEAMS          => 'teams',
			Resource::LOCATIONS      => 'locations',
			Resource::TRAININGS      => 'trainings',
			Resource::TRAINING_TIMES => 'training-times',
			Resource::NEWS           => 'news',
			Resource::EVENTS         => 'events',
		);
	}

	/**
	 * The fields of one resource: label, type and candidate KURABU names.
	 *
	 * @param string $resource Resource key.
	 *
	 * @return array<string, array{label: string, type: string, candidates: string[]}>
	 */
	public static function fields( string $resource ): array {
		$fields = self::all_fields();

		return $fields[ $resource ] ?? array();
	}

	/**
	 * The field definitions of every resource.
	 *
	 * @return array<string, array<string, array{label: string, type: string, candidates: string[]}>>
	 */
	public static function all_fields(): array {
		// A run maps thousands of fields, so the definition is built once.
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$id          = array( 'id', 'uuid', 'kurabu_id', 'kurabuId', 'externalId' );
		$name        = array( 'name', 'title', 'bezeichnung', 'titel' );
		$slug        = array( 'slug', 'permalink', 'shortName', 'kuerzel' );
		$description = array( 'description', 'beschreibung', 'text', 'content', 'inhalt' );
		$image       = array( 'image', 'image_url', 'imageUrl', 'picture', 'bild', 'logo', 'image.url' );
		$link        = array( 'link', 'url', 'website', 'permalink' );
		$order       = array( 'order', 'position', 'sort', 'sortOrder', 'reihenfolge' );

		$definition = array(
			Resource::DEPARTMENTS    => array(
				'kurabu_id'     => self::field( __( 'KURABU-ID', 'kurabu-wp-sync' ), self::TYPE_ID, $id ),
				'name'          => self::field( __( 'Name', 'kurabu-wp-sync' ), self::TYPE_TEXT, $name ),
				'slug'          => self::field( __( 'Slug', 'kurabu-wp-sync' ), self::TYPE_TEXT, $slug ),
				'description'   => self::field( __( 'Beschreibung', 'kurabu-wp-sync' ), self::TYPE_HTML, $description ),
				'image_url'     => self::field( __( 'Bild', 'kurabu-wp-sync' ), self::TYPE_URL, $image ),
				'contact_name'  => self::field(
					__( 'Ansprechpartner', 'kurabu-wp-sync' ),
					self::TYPE_TEXT,
					array( 'contact_name', 'contactName', 'contact.name', 'ansprechpartner', 'contact' )
				),
				'contact_email' => self::field(
					__( 'E-Mail', 'kurabu-wp-sync' ),
					self::TYPE_EMAIL,
					array( 'contact_email', 'contactEmail', 'contact.email', 'email', 'mail' )
				),
				'contact_phone' => self::field(
					__( 'Telefon', 'kurabu-wp-sync' ),
					self::TYPE_TEXT,
					array( 'contact_phone', 'contactPhone', 'contact.phone', 'phone', 'telefon' )
				),
				'link'          => self::field( __( 'Link', 'kurabu-wp-sync' ), self::TYPE_URL, $link ),
				'menu_order'    => self::field( __( 'Reihenfolge', 'kurabu-wp-sync' ), self::TYPE_INT, $order ),
			),

			Resource::TEAMS          => array(
				'kurabu_id'            => self::field( __( 'KURABU-ID', 'kurabu-wp-sync' ), self::TYPE_ID, $id ),
				'department_kurabu_id' => self::field(
					__( 'Abteilung (KURABU-ID)', 'kurabu-wp-sync' ),
					self::TYPE_ID,
					array( 'department_id', 'departmentId', 'department.id', 'department', 'abteilung_id', 'sport_id' )
				),
				'name'                 => self::field( __( 'Name', 'kurabu-wp-sync' ), self::TYPE_TEXT, $name ),
				'slug'                 => self::field( __( 'Slug', 'kurabu-wp-sync' ), self::TYPE_TEXT, $slug ),
				'description'          => self::field( __( 'Beschreibung', 'kurabu-wp-sync' ), self::TYPE_HTML, $description ),
				'menu_order'           => self::field( __( 'Reihenfolge', 'kurabu-wp-sync' ), self::TYPE_INT, $order ),
			),

			Resource::LOCATIONS      => array(
				'kurabu_id'   => self::field( __( 'KURABU-ID', 'kurabu-wp-sync' ), self::TYPE_ID, $id ),
				'name'        => self::field( __( 'Name', 'kurabu-wp-sync' ), self::TYPE_TEXT, $name ),
				'slug'        => self::field( __( 'Slug', 'kurabu-wp-sync' ), self::TYPE_TEXT, $slug ),
				'room'        => self::field(
					__( 'Halle / Raum', 'kurabu-wp-sync' ),
					self::TYPE_TEXT,
					array( 'room', 'hall', 'halle', 'raum', 'building', 'gebaeude' )
				),
				'street'      => self::field(
					__( 'Straße', 'kurabu-wp-sync' ),
					self::TYPE_TEXT,
					array( 'street', 'strasse', 'address', 'adresse', 'address.street', 'street_address' )
				),
				'postal_code' => self::field(
					__( 'PLZ', 'kurabu-wp-sync' ),
					self::TYPE_TEXT,
					array( 'postal_code', 'postalCode', 'zip', 'plz', 'address.postal_code' )
				),
				'city'        => self::field(
					__( 'Ort', 'kurabu-wp-sync' ),
					self::TYPE_TEXT,
					array( 'city', 'ort', 'town', 'address.city' )
				),
				'latitude'    => self::field(
					__( 'Breitengrad', 'kurabu-wp-sync' ),
					self::TYPE_FLOAT,
					array( 'latitude', 'lat', 'geo.lat', 'coordinates.lat' )
				),
				'longitude'   => self::field(
					__( 'Längengrad', 'kurabu-wp-sync' ),
					self::TYPE_FLOAT,
					array( 'longitude', 'lng', 'lon', 'geo.lng', 'coordinates.lng' )
				),
				'notes'       => self::field(
					__( 'Hinweise', 'kurabu-wp-sync' ),
					self::TYPE_HTML,
					array( 'notes', 'note', 'hinweis', 'hinweise', 'description', 'beschreibung' )
				),
			),

			Resource::TRAININGS      => array(
				'kurabu_id'            => self::field( __( 'KURABU-ID', 'kurabu-wp-sync' ), self::TYPE_ID, $id ),
				'department_kurabu_id' => self::field(
					__( 'Abteilung (KURABU-ID)', 'kurabu-wp-sync' ),
					self::TYPE_ID,
					array( 'department_id', 'departmentId', 'department.id', 'department', 'abteilung_id' )
				),
				'team_kurabu_id'       => self::field(
					__( 'Team (KURABU-ID)', 'kurabu-wp-sync' ),
					self::TYPE_ID,
					array( 'team_id', 'teamId', 'team.id', 'group_id', 'groupId', 'gruppe_id' )
				),
				'location_kurabu_id'   => self::field(
					__( 'Trainingsort (KURABU-ID)', 'kurabu-wp-sync' ),
					self::TYPE_ID,
					array( 'location_id', 'locationId', 'location.id', 'venue_id', 'ort_id' )
				),
				'name'                 => self::field( __( 'Name', 'kurabu-wp-sync' ), self::TYPE_TEXT, $name ),
				'slug'                 => self::field( __( 'Slug', 'kurabu-wp-sync' ), self::TYPE_TEXT, $slug ),
				'description'          => self::field( __( 'Beschreibung', 'kurabu-wp-sync' ), self::TYPE_HTML, $description ),
				'trainer'              => self::field(
					__( 'Trainer', 'kurabu-wp-sync' ),
					self::TYPE_TEXT,
					array( 'trainer', 'coach', 'trainer_name', 'trainer.name', 'leader', 'uebungsleiter' )
				),
				'image_url'            => self::field( __( 'Bild', 'kurabu-wp-sync' ), self::TYPE_URL, $image ),
				'link'                 => self::field( __( 'Link', 'kurabu-wp-sync' ), self::TYPE_URL, $link ),
				'menu_order'           => self::field( __( 'Reihenfolge', 'kurabu-wp-sync' ), self::TYPE_INT, $order ),
			),

			Resource::TRAINING_TIMES => array(
				'kurabu_id'          => self::field( __( 'KURABU-ID', 'kurabu-wp-sync' ), self::TYPE_ID, $id ),
				'training_kurabu_id' => self::field(
					__( 'Training (KURABU-ID)', 'kurabu-wp-sync' ),
					self::TYPE_ID,
					array( 'training_id', 'trainingId', 'training.id', 'course_id', 'offer_id' )
				),
				'weekday'            => self::field(
					__( 'Wochentag', 'kurabu-wp-sync' ),
					self::TYPE_WEEKDAY,
					array( 'weekday', 'day_of_week', 'dayOfWeek', 'wochentag', 'day', 'tag' )
				),
				'start_time'         => self::field(
					__( 'Beginn', 'kurabu-wp-sync' ),
					self::TYPE_TIME,
					array( 'start_time', 'startTime', 'start', 'from', 'beginn', 'von' )
				),
				'end_time'           => self::field(
					__( 'Ende', 'kurabu-wp-sync' ),
					self::TYPE_TIME,
					array( 'end_time', 'endTime', 'end', 'to', 'ende', 'bis' )
				),
				'valid_from'         => self::field(
					__( 'Gültig ab', 'kurabu-wp-sync' ),
					self::TYPE_DATE,
					array( 'valid_from', 'validFrom', 'start_date', 'startDate', 'gueltig_ab' )
				),
				'valid_until'        => self::field(
					__( 'Gültig bis', 'kurabu-wp-sync' ),
					self::TYPE_DATE,
					array( 'valid_until', 'validUntil', 'end_date', 'endDate', 'gueltig_bis' )
				),
				'notes'              => self::field(
					__( 'Hinweise', 'kurabu-wp-sync' ),
					self::TYPE_HTML,
					array( 'notes', 'note', 'hinweis', 'comment', 'bemerkung' )
				),
			),

			Resource::NEWS           => array(
				'kurabu_id'            => self::field( __( 'KURABU-ID', 'kurabu-wp-sync' ), self::TYPE_ID, $id ),
				'title'                => self::field( __( 'Titel', 'kurabu-wp-sync' ), self::TYPE_TEXT, $name ),
				'content'              => self::field(
					__( 'Inhalt', 'kurabu-wp-sync' ),
					self::TYPE_HTML,
					array( 'content', 'body', 'text', 'inhalt', 'description', 'html' )
				),
				'excerpt'              => self::field(
					__( 'Auszug', 'kurabu-wp-sync' ),
					self::TYPE_TEXT,
					array( 'excerpt', 'summary', 'teaser', 'anrisstext', 'kurzfassung' )
				),
				'published_at'         => self::field(
					__( 'Veröffentlicht am', 'kurabu-wp-sync' ),
					self::TYPE_DATETIME,
					array( 'published_at', 'publishedAt', 'date', 'created_at', 'createdAt', 'datum' )
				),
				'image_url'            => self::field( __( 'Beitragsbild', 'kurabu-wp-sync' ), self::TYPE_URL, $image ),
				'link'                 => self::field( __( 'Link', 'kurabu-wp-sync' ), self::TYPE_URL, $link ),
				'department_kurabu_id' => self::field(
					__( 'Abteilung (KURABU-ID)', 'kurabu-wp-sync' ),
					self::TYPE_ID,
					array( 'department_id', 'departmentId', 'department.id', 'abteilung_id' )
				),
			),

			Resource::EVENTS         => array(
				'kurabu_id'            => self::field( __( 'KURABU-ID', 'kurabu-wp-sync' ), self::TYPE_ID, $id ),
				'title'                => self::field( __( 'Titel', 'kurabu-wp-sync' ), self::TYPE_TEXT, $name ),
				'description'          => self::field( __( 'Beschreibung', 'kurabu-wp-sync' ), self::TYPE_HTML, $description ),
				'start'                => self::field(
					__( 'Beginn', 'kurabu-wp-sync' ),
					self::TYPE_DATETIME,
					array( 'start', 'start_at', 'startAt', 'start_date', 'startDate', 'from', 'beginn' )
				),
				'end'                  => self::field(
					__( 'Ende', 'kurabu-wp-sync' ),
					self::TYPE_DATETIME,
					array( 'end', 'end_at', 'endAt', 'end_date', 'endDate', 'to', 'ende' )
				),
				'all_day'              => self::field(
					__( 'Ganztägig', 'kurabu-wp-sync' ),
					self::TYPE_BOOL,
					array( 'all_day', 'allDay', 'whole_day', 'ganztaegig' )
				),
				'location_name'        => self::field(
					__( 'Ort (Name)', 'kurabu-wp-sync' ),
					self::TYPE_TEXT,
					array( 'location', 'location_name', 'locationName', 'location.name', 'venue', 'ort' )
				),
				'location_kurabu_id'   => self::field(
					__( 'Ort (KURABU-ID)', 'kurabu-wp-sync' ),
					self::TYPE_ID,
					array( 'location_id', 'locationId', 'location.id', 'venue_id' )
				),
				'department_kurabu_id' => self::field(
					__( 'Abteilung (KURABU-ID)', 'kurabu-wp-sync' ),
					self::TYPE_ID,
					array( 'department_id', 'departmentId', 'department.id', 'abteilung_id' )
				),
				'image_url'            => self::field( __( 'Bild', 'kurabu-wp-sync' ), self::TYPE_URL, $image ),
				'link'                 => self::field( __( 'Link', 'kurabu-wp-sync' ), self::TYPE_URL, $link ),
			),
		);

		/**
		 * Filters the field definitions.
		 *
		 * @param array<string, array<string, array{label: string, type: string, candidates: string[]}>> $definition Definitions per resource.
		 */
		self::$cache = (array) apply_filters( 'kurabu_wp_sync_field_definition', $definition );

		return self::$cache;
	}

	/**
	 * Forgets the cached definitions.
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * Builds one field definition.
	 *
	 * @param string   $label      Label for the backend.
	 * @param string   $type       One of the TYPE_* constants.
	 * @param string[] $candidates Candidate KURABU field names.
	 *
	 * @return array{label: string, type: string, candidates: string[]}
	 */
	private static function field( string $label, string $type, array $candidates ): array {
		return array(
			'label'      => $label,
			'type'       => $type,
			'candidates' => $candidates,
		);
	}
}
