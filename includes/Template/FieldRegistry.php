<?php
/**
 * The KURABU fields and layout elements the Baukasten offers.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Catalogue of the elements an administrator can put into a template.
 *
 * The registry only describes what exists and how it is rendered; it never
 * reads data. The shortcode layer fills the described fields, the Renderer
 * turns them into markup.
 */
final class FieldRegistry {

	public const KIND_TEXT  = 'text';
	public const KIND_HTML  = 'html';
	public const KIND_LIST  = 'list';
	public const KIND_IMAGE = 'image';
	public const KIND_LINK  = 'link';
	public const KIND_EMAIL = 'email';
	public const KIND_PHONE = 'phone';

	/**
	 * The fields of one record type.
	 *
	 * @param string $type One of TemplateStore::TYPE_*.
	 *
	 * @return array<string, array{label: string, kind: string, icon?: string}>
	 */
	public static function fields( string $type ): array {
		$fields = TemplateStore::TYPE_DEPARTMENT === $type
			? self::department_fields()
			: self::training_fields();

		/**
		 * Filters the KURABU fields offered in the Vorlagen-Baukasten.
		 *
		 * A field added here is offered in the backend and rendered as soon as
		 * the shortcode layer supplies a value for it.
		 *
		 * @param array<string, array<string, string>> $fields Field definitions.
		 * @param string                               $type   Record type.
		 */
		return (array) apply_filters( 'kurabu_wp_sync_template_fields', $fields, $type );
	}

	/**
	 * The fields of a Training.
	 *
	 * @return array<string, array{label: string, kind: string, icon?: string}>
	 */
	private static function training_fields(): array {
		return array(
			'name'        => array(
				'label' => __( 'Trainingsname', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_TEXT,
			),
			'team'        => array(
				'label' => __( 'Trainingsgruppe / Team', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_TEXT,
			),
			'department'  => array(
				'label' => __( 'Abteilung', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_TEXT,
			),
			'weekday'     => array(
				'label' => __( 'Wochentag', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_TEXT,
				'icon'  => '📅',
			),
			'start_time'  => array(
				'label' => __( 'Beginn', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_TEXT,
				'icon'  => '🕐',
			),
			'end_time'    => array(
				'label' => __( 'Ende', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_TEXT,
			),
			'time'        => array(
				'label' => __( 'Beginn – Ende', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_TEXT,
				'icon'  => '🕐',
			),
			'schedule'    => array(
				'label' => __( 'Alle Trainingszeiten', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_LIST,
				'icon'  => '📅',
			),
			'location'    => array(
				'label' => __( 'Trainingsort', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_TEXT,
				'icon'  => '📍',
			),
			'room'        => array(
				'label' => __( 'Halle / Raum', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_TEXT,
			),
			'address'     => array(
				'label' => __( 'Adresse des Trainingsorts', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_TEXT,
			),
			'trainer'     => array(
				'label' => __( 'Trainer / Ansprechpartner', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_TEXT,
				'icon'  => '👤',
			),
			'description' => array(
				'label' => __( 'Beschreibung', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_HTML,
			),
			'image'       => array(
				'label' => __( 'Bild', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_IMAGE,
			),
			'link'        => array(
				'label' => __( 'Link', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_LINK,
			),
		);
	}

	/**
	 * The fields of an Abteilung.
	 *
	 * @return array<string, array{label: string, kind: string, icon?: string}>
	 */
	private static function department_fields(): array {
		return array(
			'name'          => array(
				'label' => __( 'Name der Abteilung', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_TEXT,
			),
			'description'   => array(
				'label' => __( 'Beschreibung', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_HTML,
			),
			'image'         => array(
				'label' => __( 'Bild', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_IMAGE,
			),
			'teams'         => array(
				'label' => __( 'Trainingsgruppen', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_LIST,
			),
			'locations'     => array(
				'label' => __( 'Trainingsorte', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_LIST,
				'icon'  => '📍',
			),
			'contact_name'  => array(
				'label' => __( 'Ansprechpartner', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_TEXT,
				'icon'  => '👤',
			),
			'contact_email' => array(
				'label' => __( 'E-Mail des Ansprechpartners', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_EMAIL,
				'icon'  => '✉️',
			),
			'contact_phone' => array(
				'label' => __( 'Telefon des Ansprechpartners', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_PHONE,
				'icon'  => '📞',
			),
			'link'          => array(
				'label' => __( 'Link', 'kurabu-wp-sync' ),
				'kind'  => self::KIND_LINK,
			),
		);
	}

	/**
	 * The layout and formatting elements of one record type.
	 *
	 * @param string $type One of TemplateStore::TYPE_*.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function layout_elements( string $type ): array {
		$elements = array(
			Element::TYPE_HEADING => array(
				'label'       => __( 'Überschrift', 'kurabu-wp-sync' ),
				'description' => __( 'Fester Text als Überschrift, zum Beispiel „Trainingsangebote".', 'kurabu-wp-sync' ),
			),
			Element::TYPE_TEXT    => array(
				'label'       => __( 'Text', 'kurabu-wp-sync' ),
				'description' => __( 'Fester Text, unabhängig von KURABU.', 'kurabu-wp-sync' ),
			),
			Element::TYPE_DIVIDER => array(
				'label'       => __( 'Trennlinie', 'kurabu-wp-sync' ),
				'description' => __( 'Waagerechte Linie.', 'kurabu-wp-sync' ),
			),
			Element::TYPE_BUTTON  => array(
				'label'       => __( 'Link / Button', 'kurabu-wp-sync' ),
				'description' => __( 'Schaltfläche mit eigenem Text und Ziel.', 'kurabu-wp-sync' ),
			),
			Element::TYPE_SPACER  => array(
				'label'       => __( 'Abstand', 'kurabu-wp-sync' ),
				'description' => __( 'Leerraum zwischen zwei Elementen.', 'kurabu-wp-sync' ),
			),
		);

		if ( TemplateStore::TYPE_DEPARTMENT === $type ) {
			$elements[ Element::TYPE_TRAININGS ] = array(
				'label'       => __( 'Trainings der Abteilung', 'kurabu-wp-sync' ),
				'description' => __( 'Alle Trainings der Abteilung, dargestellt mit der konfigurierten Training-Vorlage.', 'kurabu-wp-sync' ),
			);

			$elements[ Element::TYPE_NEWS ] = array(
				'label'       => __( 'Aktuelle News', 'kurabu-wp-sync' ),
				'description' => __( 'News der Abteilung, sobald die Synchronisation sie als Beiträge anlegt.', 'kurabu-wp-sync' ),
			);

			$elements[ Element::TYPE_EVENTS ] = array(
				'label'       => __( 'Termine', 'kurabu-wp-sync' ),
				'description' => __( 'Termine der Abteilung, sobald die Synchronisation sie in den Kalender schreibt.', 'kurabu-wp-sync' ),
			);
		}

		return $elements;
	}

	/**
	 * The label of one element, for the backend.
	 *
	 * @param Element $element The element.
	 * @param string  $type    Record type.
	 */
	public static function label( Element $element, string $type ): string {
		if ( Element::TYPE_FIELD === $element->type() ) {
			$fields = self::fields( $type );
			$key    = $element->field_key();

			return isset( $fields[ $key ] )
				? (string) $fields[ $key ]['label']
				/* translators: %s: field key that is no longer offered. */
				: sprintf( __( 'Unbekanntes Feld (%s)', 'kurabu-wp-sync' ), $key );
		}

		$layout = self::layout_elements( $type );

		return isset( $layout[ $element->type() ] )
			? (string) $layout[ $element->type() ]['label']
			: $element->type();
	}

	/**
	 * The render kind of a field, e.g. text or html.
	 *
	 * @param string $type  Record type.
	 * @param string $field Field key.
	 */
	public static function kind( string $type, string $field ): string {
		$fields = self::fields( $type );

		return isset( $fields[ $field ]['kind'] ) ? (string) $fields[ $field ]['kind'] : self::KIND_TEXT;
	}

	/**
	 * The suggested icon of a field, empty when it has none.
	 *
	 * @param string $type  Record type.
	 * @param string $field Field key.
	 */
	public static function default_icon( string $type, string $field ): string {
		$fields = self::fields( $type );

		return isset( $fields[ $field ]['icon'] ) ? (string) $fields[ $field ]['icon'] : '';
	}
}
