<?php
/**
 * Sample values for the template preview.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Template;

defined( 'ABSPATH' ) || exit;

/**
 * A made up Training and Abteilung, used to preview a template.
 *
 * The preview must work before the first sync run and must not depend on the
 * database, so these values are fixed rather than read from the cache.
 */
final class SampleData {

	/**
	 * A sample Training context.
	 *
	 * @return array<string, mixed>
	 */
	public static function training_context(): array {
		return array(
			'values'  => array(
				'name'        => __( 'Eltern-Kind-Turnen', 'kurabu-wp-sync' ),
				'team'        => __( 'Turnen Kinder', 'kurabu-wp-sync' ),
				'department'  => __( 'Turnen', 'kurabu-wp-sync' ),
				'weekday'     => __( 'Montag', 'kurabu-wp-sync' ),
				'start_time'  => '15:00',
				'end_time'    => '16:00',
				'time'        => '15:00 – 16:00',
				'schedule'    => array( __( 'Montag 15:00 – 16:00', 'kurabu-wp-sync' ), __( 'Donnerstag 16:00 – 17:00', 'kurabu-wp-sync' ) ),
				'location'    => __( 'HUK-Halle', 'kurabu-wp-sync' ),
				'room'        => __( 'Gymnastikraum', 'kurabu-wp-sync' ),
				'address'     => __( 'Am Sportplatz 1, 96450 Coburg', 'kurabu-wp-sync' ),
				'trainer'     => __( 'Maria Beispiel', 'kurabu-wp-sync' ),
				'description' => '<p>' . __( 'Spielerische Bewegung für Kinder von zwei bis vier Jahren gemeinsam mit einem Elternteil.', 'kurabu-wp-sync' ) . '</p>',
				'image'       => '',
				'link'        => 'https://example.org/turnen/eltern-kind-turnen',
			),
			'classes' => array( 'kurabu-training', 'kurabu-preview__record' ),
		);
	}

	/**
	 * A sample Abteilung context, including two rendered sample trainings.
	 *
	 * @param Template|null $training_template Template the sample trainings use.
	 *
	 * @return array<string, mixed>
	 */
	public static function department_context( ?Template $training_template = null ): array {
		$trainings = array();

		if ( null !== $training_template ) {
			foreach ( self::sample_trainings() as $context ) {
				$html = Renderer::render( $training_template, $context );

				if ( '' !== $html ) {
					$trainings[] = $html;
				}
			}
		}

		return array(
			'values'  => array(
				'name'          => __( 'Turnen', 'kurabu-wp-sync' ),
				'description'   => '<p>' . __( 'Von der Eltern-Kind-Gruppe bis zum Gerätturnen: die Turnabteilung des TV 1848 Coburg.', 'kurabu-wp-sync' ) . '</p>',
				'image'         => '',
				'teams'         => array( __( 'Turnen Kinder', 'kurabu-wp-sync' ), __( 'Gerätturnen', 'kurabu-wp-sync' ) ),
				'locations'     => array( __( 'HUK-Halle', 'kurabu-wp-sync' ), __( 'Angerhalle', 'kurabu-wp-sync' ) ),
				'contact_name'  => __( 'Maria Beispiel', 'kurabu-wp-sync' ),
				'contact_email' => 'turnen@example.org',
				'contact_phone' => '09561 123456',
				'link'          => 'https://example.org/turnen',
			),
			'blocks'  => array(
				Element::TYPE_TRAININGS => $trainings,
				Element::TYPE_NEWS      => array(),
				Element::TYPE_EVENTS    => array(),
			),
			'classes' => array( 'kurabu-department', 'kurabu-preview__record' ),
		);
	}

	/**
	 * The two trainings shown inside the Abteilung preview.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function sample_trainings(): array {
		$first = self::training_context();

		$second                          = $first;
		$second['values']['name']        = __( 'Gerätturnen', 'kurabu-wp-sync' );
		$second['values']['team']        = __( 'Gerätturnen', 'kurabu-wp-sync' );
		$second['values']['weekday']     = __( 'Dienstag', 'kurabu-wp-sync' );
		$second['values']['start_time']  = '17:00';
		$second['values']['end_time']    = '18:30';
		$second['values']['time']        = '17:00 – 18:30';
		$second['values']['schedule']    = array( __( 'Dienstag 17:00 – 18:30', 'kurabu-wp-sync' ) );
		$second['values']['location']    = __( 'Angerhalle', 'kurabu-wp-sync' );
		$second['values']['room']        = __( 'Turnhalle', 'kurabu-wp-sync' );
		$second['values']['description'] = '<p>' . __( 'Turnen an Boden, Barren, Reck und Sprungtisch für Kinder ab sechs Jahren.', 'kurabu-wp-sync' ) . '</p>';
		$second['values']['link']        = 'https://example.org/turnen/geraetturnen';

		return array( $first, $second );
	}
}
