<?php
/**
 * Vorlagen screen: the Baukasten for trainings and Abteilungen.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Admin\Pages;

use Kurabu\WPSync\Admin\TemplateEditor;
use Kurabu\WPSync\Plugin;
use Kurabu\WPSync\Template\Assets;
use Kurabu\WPSync\Template\DefaultTemplates;
use Kurabu\WPSync\Template\FieldRegistry;
use Kurabu\WPSync\Template\Renderer;
use Kurabu\WPSync\Template\SampleData;
use Kurabu\WPSync\Template\Template;
use Kurabu\WPSync\Template\TemplateStore;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the administrator define how a training and an Abteilung look.
 *
 * The screen has two views: the list of the stored templates and the editor
 * of one template. Everything is saved centrally through the TemplateStore,
 * so a change here reaches every shortcode that uses the template.
 */
final class TemplatesPage extends AbstractPage {

	private const NONCE = 'kurabu_wp_sync_templates';

	public function slug(): string {
		return 'kurabu-wp-sync-templates';
	}

	public function menu_title(): string {
		return __( 'Vorlagen', 'kurabu-wp-sync' );
	}

	public function page_title(): string {
		$template = $this->current_template();

		if ( null === $template ) {
			return __( 'Vorlagen', 'kurabu-wp-sync' );
		}

		return sprintf(
			/* translators: 1: record type, 2: template name. */
			__( 'Vorlage bearbeiten: %1$s – %2$s', 'kurabu-wp-sync' ),
			TemplateStore::types()[ $template->type() ] ?? $template->type(),
			$template->name()
		);
	}

	/**
	 * Handles every form submission of this screen.
	 */
	public function handle_request(): void {
		Assets::enqueue();

		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			return;
		}

		$action = isset( $_REQUEST['kurabu_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['kurabu_action'] ) ) : '';

		if ( '' === $action ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$type = isset( $_REQUEST['type'] ) ? sanitize_key( wp_unslash( $_REQUEST['type'] ) ) : '';
		$key  = isset( $_REQUEST['template'] ) ? sanitize_key( wp_unslash( $_REQUEST['template'] ) ) : '';

		switch ( $action ) {
			case 'create':
				$this->create( $type );
				return;

			case 'duplicate':
				$this->duplicate( $type, $key );
				return;

			case 'delete':
				TemplateStore::delete( $type, $key );
				$this->redirect_to_list( 'deleted' );
				return;

			case 'edit':
				$this->save( $type, $key );
				return;
		}
	}

	/**
	 * Creates a template, optionally as a copy of an existing one.
	 *
	 * @param string $type Record type.
	 */
	private function create( string $type ): void {
		if ( ! TemplateStore::is_type( $type ) ) {
			$this->redirect_to_list( 'unknown-type' );
		}

		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$name = '' !== $name ? $name : __( 'Neue Vorlage', 'kurabu-wp-sync' );
		$key  = TemplateStore::unique_key( $type, $name );
		$from = sanitize_key( wp_unslash( $_POST['copy_from'] ?? '' ) );

		$source = '' !== $from ? TemplateStore::get( $type, $from ) : null;

		$template = null !== $source
			? $source->duplicate( $key, $name )
			: new Template( $key, $type, $name );

		TemplateStore::save( $template );

		$this->redirect_to_editor( $type, $key, 'created' );
	}

	/**
	 * Copies an existing template.
	 *
	 * @param string $type Record type.
	 * @param string $key  Template key.
	 */
	private function duplicate( string $type, string $key ): void {
		$source = TemplateStore::get( $type, $key );

		if ( null === $source ) {
			$this->redirect_to_list( 'unknown-template' );
		}

		/* translators: %s: name of the copied template. */
		$name = sprintf( __( '%s (Kopie)', 'kurabu-wp-sync' ), $source->name() );
		$copy = $source->duplicate( TemplateStore::unique_key( $type, $name ), $name );

		TemplateStore::save( $copy );

		$this->redirect_to_editor( $type, $copy->key(), 'created' );
	}

	/**
	 * Saves the editor form, including the reorder and remove buttons.
	 *
	 * Every button writes the whole form, so nothing typed is lost when an
	 * element is moved or removed.
	 *
	 * @param string $type Record type.
	 * @param string $key  Template key.
	 */
	private function save( string $type, string $key ): void {
		$template = TemplateStore::get( $type, $key );

		if ( null === $template ) {
			$this->redirect_to_list( 'unknown-template' );
		}

		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

		if ( '' !== $name ) {
			$template = $template->with_name( $name );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitised in TemplateEditor.
		$posted   = isset( $_POST['elements'] ) && is_array( $_POST['elements'] ) ? wp_unslash( $_POST['elements'] ) : array();
		$elements = TemplateEditor::elements_from_post( $posted );

		$row_action = sanitize_text_field( wp_unslash( $_POST['row_action'] ?? '' ) );

		if ( '' !== $row_action ) {
			$elements = $this->apply_row_action( $elements, $row_action );
		}

		$added = sanitize_text_field( wp_unslash( $_POST['new_element'] ?? '' ) );

		if ( '' !== $added && isset( $_POST['add_element'] ) ) {
			$element = TemplateEditor::element_from_choice( $type, $added );

			if ( null !== $element ) {
				$elements[] = $element;
			}
		}

		TemplateStore::save( $template->with_elements( $elements ) );

		$this->redirect_to_editor( $type, $key, 'saved' );
	}

	/**
	 * Applies a move or remove button.
	 *
	 * @param Element[] $elements   Elements in submitted order.
	 * @param string    $row_action Button value, e.g. "up:2".
	 *
	 * @return Element[]
	 */
	private function apply_row_action( array $elements, string $row_action ): array {
		list( $what, $index ) = array_pad( explode( ':', $row_action, 2 ), 2, '' );

		$index = (int) $index;

		if ( ! isset( $elements[ $index ] ) ) {
			return $elements;
		}

		switch ( $what ) {
			case 'remove':
				unset( $elements[ $index ] );
				break;

			case 'up':
				if ( $index > 0 ) {
					$swap                      = $elements[ $index - 1 ];
					$elements[ $index - 1 ]    = $elements[ $index ];
					$elements[ $index ]        = $swap;
				}
				break;

			case 'down':
				if ( isset( $elements[ $index + 1 ] ) ) {
					$swap                   = $elements[ $index + 1 ];
					$elements[ $index + 1 ] = $elements[ $index ];
					$elements[ $index ]     = $swap;
				}
				break;
		}

		return array_values( $elements );
	}

	/**
	 * Renders either the list or the editor.
	 */
	protected function render_body(): void {
		$this->render_notice();

		$template = $this->current_template();

		if ( null === $template ) {
			$this->render_list();

			return;
		}

		$this->render_editor( $template );
	}

	/**
	 * Renders the list of all templates, grouped by record type.
	 */
	private function render_list(): void {
		echo '<p>' . esc_html__( 'Vorlagen bestimmen, wie Trainings und Abteilungen auf der Website dargestellt werden. Eine Vorlage wird im Shortcode über den Parameter template ausgewählt.', 'kurabu-wp-sync' ) . '</p>';

		foreach ( TemplateStore::types() as $type => $label ) {
			echo '<h2>' . esc_html( $label ) . '</h2>';

			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Vorlage', 'kurabu-wp-sync' ) . '</th>';
			echo '<th>' . esc_html__( 'Shortcode', 'kurabu-wp-sync' ) . '</th>';
			echo '<th>' . esc_html__( 'Elemente', 'kurabu-wp-sync' ) . '</th>';
			echo '<th>' . esc_html__( 'Herkunft', 'kurabu-wp-sync' ) . '</th>';
			echo '<th>' . esc_html__( 'Aktion', 'kurabu-wp-sync' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( TemplateStore::all( $type ) as $key => $template ) {
				$visible = 0;

				foreach ( $template->elements() as $element ) {
					$visible += $element->is_visible() ? 1 : 0;
				}

				printf(
					'<tr><td><strong><a href="%s">%s</a></strong></td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_url( $this->editor_url( $type, (string) $key ) ),
					esc_html( $template->name() ),
					esc_html( $this->shortcode_example( $type, (string) $key ) ),
					esc_html(
						sprintf(
							/* translators: 1: visible elements, 2: total elements. */
							__( '%1$d von %2$d sichtbar', 'kurabu-wp-sync' ),
							$visible,
							count( $template->elements() )
						)
					),
					esc_html(
						TemplateStore::is_default( $type, (string) $key )
							? __( 'mitgeliefert', 'kurabu-wp-sync' )
							: __( 'angepasst', 'kurabu-wp-sync' )
					),
					wp_kses_post( $this->row_actions( $type, (string) $key ) )
				);
			}

			echo '</tbody></table>';

			$this->render_create_form( $type );
		}
	}

	/**
	 * Renders the links behind one template.
	 *
	 * @param string $type Record type.
	 * @param string $key  Template key.
	 */
	private function row_actions( string $type, string $key ): string {
		$links = array(
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->editor_url( $type, $key ) ),
				esc_html__( 'Bearbeiten', 'kurabu-wp-sync' )
			),
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->action_url( 'duplicate', $type, $key ) ),
				esc_html__( 'Duplizieren', 'kurabu-wp-sync' )
			),
		);

		if ( ! TemplateStore::is_default( $type, $key ) ) {
			$is_shipped = isset( DefaultTemplates::all()[ $type ][ $key ] );

			$links[] = sprintf(
				'<a href="%s" class="submitdelete">%s</a>',
				esc_url( $this->action_url( 'delete', $type, $key ) ),
				esc_html(
					$is_shipped
						? __( 'Auf Auslieferungszustand zurücksetzen', 'kurabu-wp-sync' )
						: __( 'Löschen', 'kurabu-wp-sync' )
				)
			);
		}

		return implode( ' | ', $links );
	}

	/**
	 * Renders the form that creates a new template of one type.
	 *
	 * @param string $type Record type.
	 */
	private function render_create_form( string $type ): void {
		echo '<form method="post" class="kurabu-create">';
		wp_nonce_field( self::NONCE );
		printf( '<input type="hidden" name="kurabu_action" value="create"><input type="hidden" name="type" value="%s">', esc_attr( $type ) );

		printf(
			'<label>%s <input type="text" name="name" class="regular-text" required></label> ',
			esc_html__( 'Name der neuen Vorlage', 'kurabu-wp-sync' )
		);

		echo '<label>' . esc_html__( 'Kopie von', 'kurabu-wp-sync' ) . ' <select name="copy_from">';
		echo '<option value="">' . esc_html__( 'leer beginnen', 'kurabu-wp-sync' ) . '</option>';

		foreach ( TemplateStore::all( $type ) as $key => $template ) {
			printf( '<option value="%s">%s</option>', esc_attr( (string) $key ), esc_html( $template->name() ) );
		}

		echo '</select></label> ';

		submit_button( __( 'Vorlage anlegen', 'kurabu-wp-sync' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Renders the editor of one template.
	 *
	 * @param Template $template The template being edited.
	 */
	private function render_editor( Template $template ): void {
		$type = $template->type();

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( $this->list_url() ),
			esc_html__( '← Zurück zur Übersicht', 'kurabu-wp-sync' )
		);

		echo '<p>' . esc_html__( 'Diese Vorlage wird im Shortcode so ausgewählt:', 'kurabu-wp-sync' )
			. ' <code>' . esc_html( $this->shortcode_example( $type, $template->key() ) ) . '</code></p>';

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		printf(
			'<input type="hidden" name="kurabu_action" value="edit"><input type="hidden" name="type" value="%s"><input type="hidden" name="template" value="%s">',
			esc_attr( $type ),
			esc_attr( $template->key() )
		);

		printf(
			'<p><label><strong>%s</strong> <input type="text" name="name" class="regular-text" value="%s"></label></p>',
			esc_html__( 'Name', 'kurabu-wp-sync' ),
			esc_attr( $template->name() )
		);

		TemplateEditor::render_rows( $template );

		$this->render_add_element( $template );

		submit_button( __( 'Vorlage speichern', 'kurabu-wp-sync' ) );

		echo '</form>';

		$this->render_preview( $template );
	}

	/**
	 * Renders the dropdown that adds an element.
	 *
	 * @param Template $template The template being edited.
	 */
	private function render_add_element( Template $template ): void {
		$type = $template->type();

		echo '<h2>' . esc_html__( 'Element hinzufügen', 'kurabu-wp-sync' ) . '</h2>';
		echo '<p><select name="new_element">';

		echo '<optgroup label="' . esc_attr__( 'KURABU-Felder', 'kurabu-wp-sync' ) . '">';

		$available = 0;

		foreach ( FieldRegistry::fields( $type ) as $field => $definition ) {
			if ( $template->has_element( 'field:' . $field ) ) {
				continue;
			}

			++$available;

			printf(
				'<option value="field:%s">%s</option>',
				esc_attr( (string) $field ),
				esc_html( (string) $definition['label'] )
			);
		}

		if ( 0 === $available ) {
			echo '<option value="" disabled>' . esc_html__( 'alle Felder sind bereits enthalten', 'kurabu-wp-sync' ) . '</option>';
		}

		echo '</optgroup>';

		echo '<optgroup label="' . esc_attr__( 'Layout', 'kurabu-wp-sync' ) . '">';

		foreach ( FieldRegistry::layout_elements( $type ) as $layout => $definition ) {
			printf(
				'<option value="%s">%s</option>',
				esc_attr( (string) $layout ),
				esc_html( (string) $definition['label'] )
			);
		}

		echo '</optgroup></select> ';

		submit_button( __( 'Hinzufügen', 'kurabu-wp-sync' ), 'secondary', 'add_element', false );
		echo '</p>';
	}

	/**
	 * Renders the preview with sample data.
	 *
	 * @param Template $template The template being edited.
	 */
	private function render_preview( Template $template ): void {
		echo '<h2>' . esc_html__( 'Vorschau', 'kurabu-wp-sync' ) . '</h2>';
		echo '<p>' . esc_html__( 'Die Vorschau zeigt Beispieldaten, nicht den lokalen Datenbestand.', 'kurabu-wp-sync' ) . '</p>';

		$context = TemplateStore::TYPE_DEPARTMENT === $template->type()
			? SampleData::department_context( TemplateStore::resolve( TemplateStore::TYPE_TRAINING ) )
			: SampleData::training_context();

		$html = Renderer::render( $template, $context );

		if ( '' === $html ) {
			$this->render_placeholder( __( 'Diese Vorlage enthält kein sichtbares Element, deshalb gibt der Shortcode nichts aus.', 'kurabu-wp-sync' ) );

			return;
		}

		echo '<div class="kurabu-preview">' . wp_kses_post( $html ) . '</div>';
	}

	/**
	 * Shows the result of the last action.
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$message = isset( $_GET['kurabu_message'] ) ? sanitize_key( wp_unslash( $_GET['kurabu_message'] ) ) : '';

		$messages = array(
			'saved'            => __( 'Vorlage gespeichert.', 'kurabu-wp-sync' ),
			'created'          => __( 'Vorlage angelegt.', 'kurabu-wp-sync' ),
			'deleted'          => __( 'Vorlage entfernt.', 'kurabu-wp-sync' ),
			'unknown-template' => __( 'Diese Vorlage gibt es nicht.', 'kurabu-wp-sync' ),
			'unknown-type'     => __( 'Unbekannter Vorlagentyp.', 'kurabu-wp-sync' ),
		);

		if ( ! isset( $messages[ $message ] ) ) {
			return;
		}

		$class = in_array( $message, array( 'saved', 'created', 'deleted' ), true ) ? 'notice-success' : 'notice-error';

		echo '<div class="notice ' . esc_attr( $class ) . ' inline"><p>' . esc_html( $messages[ $message ] ) . '</p></div>';
	}

	/**
	 * The template the request asks to edit, or null for the list view.
	 */
	private function current_template(): ?Template {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$key  = isset( $_GET['template'] ) ? sanitize_key( wp_unslash( $_GET['template'] ) ) : '';

		if ( '' === $type || '' === $key ) {
			return null;
		}

		return TemplateStore::get( $type, $key );
	}

	/**
	 * An example shortcode that uses the given template.
	 *
	 * @param string $type Record type.
	 * @param string $key  Template key.
	 */
	private function shortcode_example( string $type, string $key ): string {
		return TemplateStore::TYPE_DEPARTMENT === $type
			? '[kurabu_department id="…" template="' . $key . '"]'
			: '[kurabu_training id="…" template="' . $key . '"]';
	}

	/**
	 * URL of the list view.
	 */
	private function list_url(): string {
		return admin_url( 'admin.php?page=' . $this->slug() );
	}

	/**
	 * URL of the editor of one template.
	 *
	 * @param string $type Record type.
	 * @param string $key  Template key.
	 */
	private function editor_url( string $type, string $key ): string {
		return add_query_arg(
			array(
				'type'     => $type,
				'template' => $key,
			),
			$this->list_url()
		);
	}

	/**
	 * A nonce protected action URL.
	 *
	 * @param string $action Action name.
	 * @param string $type   Record type.
	 * @param string $key    Template key.
	 */
	private function action_url( string $action, string $type, string $key ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'kurabu_action' => $action,
					'type'          => $type,
					'template'      => $key,
				),
				$this->list_url()
			),
			self::NONCE
		);
	}

	/**
	 * Redirects back to the list view.
	 *
	 * @param string $message Message key.
	 */
	private function redirect_to_list( string $message ): void {
		wp_safe_redirect( add_query_arg( 'kurabu_message', $message, $this->list_url() ) );
		exit;
	}

	/**
	 * Redirects back to the editor.
	 *
	 * @param string $type    Record type.
	 * @param string $key     Template key.
	 * @param string $message Message key.
	 */
	private function redirect_to_editor( string $type, string $key, string $message ): void {
		wp_safe_redirect( add_query_arg( 'kurabu_message', $message, $this->editor_url( $type, $key ) ) );
		exit;
	}
}
