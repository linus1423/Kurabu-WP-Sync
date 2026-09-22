<?php
/**
 * The templates the plugin ships with.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the templates a fresh installation starts with.
 *
 * They exist so the shortcodes produce sensible output before anybody opened
 * the Baukasten. As soon as a template is saved in the backend, the stored
 * version wins; the defaults are only a starting point.
 */
final class DefaultTemplates {

	/**
	 * All default templates, grouped by record type.
	 *
	 * @return array<string, array<string, Template>>
	 */
	public static function all(): array {
		return array(
			TemplateStore::TYPE_TRAINING   => array(
				'standard' => self::training_standard(),
				'compact'  => self::training_compact(),
				'detail'   => self::training_detail(),
			),
			TemplateStore::TYPE_DEPARTMENT => array(
				'standard' => self::department_standard(),
				'overview' => self::department_overview(),
			),
		);
	}

	/**
	 * The default template of one record type, used when none was requested.
	 */
	public const FALLBACK_KEY = 'standard';

	/**
	 * Training – Standard: the layout from the specification.
	 */
	private static function training_standard(): Template {
		return new Template(
			'standard',
			TemplateStore::TYPE_TRAINING,
			__( 'Standard', 'kurabu-wp-sync' ),
			array(
				Element::field(
					'name',
					array(
						'tag'     => 'h3',
						'spacing' => 'none',
					)
				),
				Element::field(
					'team',
					array(
						'css_class' => 'kurabu-training__team',
						'spacing'   => 'medium',
					)
				),
				Element::field(
					'weekday',
					array(
						'icon'    => '📅',
						'spacing' => 'none',
					)
				),
				Element::field(
					'time',
					array(
						'icon'    => '🕐',
						'spacing' => 'none',
					)
				),
				Element::field(
					'location',
					array(
						'icon'    => '📍',
						'spacing' => 'medium',
					)
				),
				Element::field( 'description' ),
			)
		);
	}

	/**
	 * Training – Kompakt: one line per training, for lists.
	 */
	private static function training_compact(): Template {
		return new Template(
			'compact',
			TemplateStore::TYPE_TRAINING,
			__( 'Kompakt', 'kurabu-wp-sync' ),
			array(
				Element::field(
					'name',
					array(
						'tag'     => 'h4',
						'spacing' => 'none',
					)
				),
				Element::field(
					'schedule',
					array(
						'separator' => ' · ',
						'spacing'   => 'none',
					)
				),
				Element::field(
					'location',
					array(
						'icon'    => '📍',
						'spacing' => 'none',
					)
				),
			)
		);
	}

	/**
	 * Training – Detail: everything KURABU delivers.
	 */
	private static function training_detail(): Template {
		return new Template(
			'detail',
			TemplateStore::TYPE_TRAINING,
			__( 'Detail', 'kurabu-wp-sync' ),
			array(
				Element::field( 'image', array( 'spacing' => 'medium' ) ),
				Element::field(
					'name',
					array(
						'tag'     => 'h3',
						'spacing' => 'none',
					)
				),
				Element::field( 'team', array( 'spacing' => 'none' ) ),
				Element::field( 'department', array( 'spacing' => 'medium' ) ),
				new Element(
					Element::TYPE_HEADING,
					array(
						'text'    => __( 'Trainingszeiten', 'kurabu-wp-sync' ),
						'tag'     => 'h4',
						'spacing' => 'none',
					)
				),
				Element::field( 'schedule', array( 'spacing' => 'medium' ) ),
				Element::field(
					'location',
					array(
						'label'   => __( 'Trainingsort', 'kurabu-wp-sync' ),
						'icon'    => '📍',
						'width'   => 'half',
						'spacing' => 'none',
					)
				),
				Element::field(
					'room',
					array(
						'label'   => __( 'Halle / Raum', 'kurabu-wp-sync' ),
						'width'   => 'half',
						'spacing' => 'none',
					)
				),
				Element::field(
					'address',
					array(
						'width'   => 'half',
						'spacing' => 'none',
					)
				),
				Element::field(
					'trainer',
					array(
						'label'   => __( 'Trainer', 'kurabu-wp-sync' ),
						'icon'    => '👤',
						'width'   => 'half',
						'spacing' => 'medium',
					)
				),
				new Element( Element::TYPE_DIVIDER, array( 'spacing' => 'medium' ) ),
				Element::field( 'description', array( 'spacing' => 'medium' ) ),
				Element::field(
					'link',
					array(
						'text'  => __( 'Mehr erfahren', 'kurabu-wp-sync' ),
						'style' => 'button',
					)
				),
			)
		);
	}

	/**
	 * Abteilung – Standard: the layout from the specification.
	 */
	private static function department_standard(): Template {
		return new Template(
			'standard',
			TemplateStore::TYPE_DEPARTMENT,
			__( 'Standard', 'kurabu-wp-sync' ),
			array(
				Element::field(
					'name',
					array(
						'tag'     => 'h2',
						'spacing' => 'medium',
					)
				),
				Element::field( 'description', array( 'spacing' => 'large' ) ),
				// The headings belong to their block, so a block without content
				// leaves no empty heading behind on the page.
				new Element(
					Element::TYPE_TRAININGS,
					array(
						'text'    => __( 'Trainingsangebote', 'kurabu-wp-sync' ),
						'tag'     => 'h3',
						'spacing' => 'large',
					)
				),
				new Element(
					Element::TYPE_NEWS,
					array(
						'text'    => __( 'Aktuelles', 'kurabu-wp-sync' ),
						'tag'     => 'h3',
						'spacing' => 'large',
					)
				),
				new Element(
					Element::TYPE_EVENTS,
					array(
						'text'    => __( 'Termine', 'kurabu-wp-sync' ),
						'tag'     => 'h3',
						'spacing' => 'large',
					)
				),
				new Element( Element::TYPE_DIVIDER, array( 'spacing' => 'medium' ) ),
				Element::field(
					'contact_name',
					array(
						'label'   => __( 'Ansprechpartner', 'kurabu-wp-sync' ),
						'icon'    => '👤',
						'spacing' => 'none',
					)
				),
				Element::field(
					'contact_email',
					array(
						'icon'    => '✉️',
						'spacing' => 'none',
					)
				),
				Element::field( 'contact_phone', array( 'icon' => '📞' ) ),
			)
		);
	}

	/**
	 * Abteilung – Übersicht: name, groups and locations, without trainings.
	 */
	private static function department_overview(): Template {
		return new Template(
			'overview',
			TemplateStore::TYPE_DEPARTMENT,
			__( 'Übersicht', 'kurabu-wp-sync' ),
			array(
				Element::field(
					'name',
					array(
						'tag'     => 'h2',
						'spacing' => 'small',
					)
				),
				Element::field( 'description', array( 'spacing' => 'medium' ) ),
				Element::field(
					'teams',
					array(
						'label'   => __( 'Trainingsgruppen', 'kurabu-wp-sync' ),
						'width'   => 'half',
						'spacing' => 'medium',
					)
				),
				Element::field(
					'locations',
					array(
						'label'   => __( 'Trainingsorte', 'kurabu-wp-sync' ),
						'icon'    => '📍',
						'width'   => 'half',
						'spacing' => 'medium',
					)
				),
				Element::field(
					'link',
					array(
						'text'  => __( 'Zur Abteilung', 'kurabu-wp-sync' ),
						'style' => 'button',
					)
				),
			)
		);
	}
}
