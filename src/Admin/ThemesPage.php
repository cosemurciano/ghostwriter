<?php
declare(strict_types=1);

namespace Ghostwriter\Admin;

use Ghostwriter\Core\Capabilities;
use Ghostwriter\Rendering\ThemeRegistry;

/**
 * Pagina Temi: registry (bundled + importati) e import zip.
 */
final class ThemesPage {

	public function __construct( private ThemeRegistry $themes ) {
	}

	public function render(): void {
		echo '<div class="wrap"><div id="gw-notice" class="notice"></div>';
		echo '<h1>' . esc_html__( 'Temi grafici', 'ghostwriter' ) . '</h1>';

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
			. '<th>' . esc_html__( 'Nome', 'ghostwriter' ) . '</th>'
			. '<th>' . esc_html__( 'Versione', 'ghostwriter' ) . '</th>'
			. '<th>' . esc_html__( 'Formati', 'ghostwriter' ) . '</th>'
			. '<th>' . esc_html__( 'Blocchi supportati', 'ghostwriter' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $this->themes->all() as $theme ) {
			$formats = array();
			foreach ( (array) ( $theme->raw()['meta']['supports_formats'] ?? array() ) as $format ) {
				$formats[] = (string) ( $format['label'] ?? ( $format['width_mm'] . '×' . $format['height_mm'] ) );
			}
			echo '<tr>'
				. '<td><strong>' . esc_html( $theme->name() ) . '</strong></td>'
				. '<td>' . esc_html( $theme->version() ) . '</td>'
				. '<td>' . esc_html( implode( ', ', $formats ) ) . '</td>'
				. '<td class="gw-muted">' . esc_html( implode( ', ', $theme->supports_blocks() ) ) . '</td>'
				. '</tr>';
		}
		echo '</tbody></table>';

		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			echo '<h2 style="margin-top:2em">' . esc_html__( 'Importa tema (zip)', 'ghostwriter' ) . '</h2>';
			echo '<form class="gw-box" style="max-width:560px" data-gw-form="POST /themes/import" data-gw-multipart>'
				. '<p>' . esc_html__( 'Pacchetto: theme.json + fonts/ + assets/ + pages/. Lo zip viene validato contro lo schema; i bundle con PHP vengono rifiutati.', 'ghostwriter' ) . '</p>'
				. '<p><input type="file" name="bundle" accept=".zip" required /></p>'
				. '<p><button class="button button-primary">' . esc_html__( 'Importa', 'ghostwriter' ) . '</button></p>'
				. '</form>';
		}

		echo '</div>';
	}
}
