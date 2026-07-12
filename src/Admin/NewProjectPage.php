<?php
declare(strict_types=1);

namespace Ghostwriter\Admin;

use Ghostwriter\Ai\ApiKeys;

/**
 * Creazione progetto: form a sezioni in stile WordPress (form-table),
 * con descrizioni e default sensati. Il submit costruisce la config
 * conforme allo schema e la invia alla REST API.
 */
final class NewProjectPage {

	public function render(): void {
		echo '<div class="wrap gw-new-project"><div id="gw-notice" class="notice"></div>';
		echo '<h1>' . esc_html__( 'Aggiungi progetto', 'ghostwriter' ) . '</h1>';
		echo '<p class="gw-muted">' . esc_html__( 'Definisci il libro: brief, formato fisico, blocchi ammessi e motore AI. Tutto il resto (indice, capitoli, copertina) nasce dalla pipeline.', 'ghostwriter' ) . '</p>';

		echo '<form data-gw-form="POST /projects" data-gw-transform="newProject" data-gw-goto-project>';

		// --- Modalità di scrittura ---
		$this->box_open( __( 'Come scrivi questo libro?', 'ghostwriter' ) );
		echo '<table class="form-table" role="presentation">';
		$this->row(
			__( 'Modalità', 'ghostwriter' ),
			'<label style="display:block;margin-bottom:6px"><input type="radio" name="writing_mode" value="ai" class="gw-writing-mode" checked/> <strong>' . esc_html__( 'Con l\'AI', 'ghostwriter' ) . '</strong> — ' . esc_html__( 'indice proposto, capitoli generati e revisionati, immagini e copertina', 'ghostwriter' ) . '</label>'
			. '<label style="display:block"><input type="radio" name="writing_mode" value="manual" class="gw-writing-mode"/> <strong>' . esc_html__( 'Manualmente, senza AI', 'ghostwriter' ) . '</strong> — ' . esc_html__( 'scrivi tu ogni capitolo nell\'editor di WordPress, con upload di immagini; il plugin cura struttura, impaginazione ed export PDF/ePub', 'ghostwriter' ) . '</label>'
		);
		echo '</table>';
		$this->box_close();

		// --- Contenuto ---
		$this->box_open( __( 'Contenuto', 'ghostwriter' ) );
		echo '<table class="form-table" role="presentation">';
		$this->row(
			__( 'Titolo del libro', 'ghostwriter' ),
			'<input type="text" name="title" class="regular-text" required autofocus />',
			__( 'Titolo di lavoro: potrà cambiare prima dell\'export.', 'ghostwriter' )
		);
		$this->row(
			__( 'Tesi / obiettivo', 'ghostwriter' ),
			'<textarea name="thesis" rows="3" class="large-text" required placeholder="' . esc_attr__( 'Cosa dimostra o insegna questo libro, in 2-4 frasi. Governa indice e capitoli.', 'ghostwriter' ) . '"></textarea>'
		);
		$this->row(
			__( 'Pubblico', 'ghostwriter' ),
			'<input type="text" name="audience" class="regular-text" placeholder="' . esc_attr__( 'Es. lettori curiosi senza formazione tecnica', 'ghostwriter' ) . '" />'
		);
		$genres = '';
		foreach ( array( 'divulgazione', 'saggistica', 'manualistica', 'guida', 'narrativa', 'altro' ) as $genre ) {
			$genres .= '<option value="' . esc_attr( $genre ) . '">' . esc_html( $genre ) . '</option>';
		}
		$this->row(
			__( 'Genere e lingua', 'ghostwriter' ),
			'<select name="genre">' . $genres . '</select> <input type="text" name="language" value="it" size="4" aria-label="' . esc_attr__( 'Lingua', 'ghostwriter' ) . '" />'
		);
		$this->row(
			__( 'Obiettivo di lunghezza', 'ghostwriter' ),
			'<input type="number" name="target_words" min="1000" step="1000" placeholder="40000" /> ' . esc_html__( 'parole', 'ghostwriter' ),
			__( 'Facoltativo: orienta il numero e la profondità dei capitoli.', 'ghostwriter' )
		);
		echo '</table>';
		$this->box_close();

		// --- Formato e struttura ---
		$this->box_open( __( 'Formato e struttura', 'ghostwriter' ) );
		echo '<table class="form-table" role="presentation">';
		$this->row(
			__( 'Formato fisico', 'ghostwriter' ),
			self::format_picker( 152, 229, '' ),
			__( 'I tre formati più usati su Amazon KDP, o dimensioni personalizzate. Fissato all\'avvio: vincola copertina e risoluzione immagini. L\'ePub è reflowable e lo ignora.', 'ghostwriter' )
		);

		$blocks_html = '<fieldset class="gw-blocks-fieldset">';
		$labels      = array(
			'paragrafo'           => __( 'Paragrafi', 'ghostwriter' ),
			'heading'             => __( 'Sezioni (heading)', 'ghostwriter' ),
			'citazione'           => __( 'Citazioni', 'ghostwriter' ),
			'box_approfondimento' => __( 'Box di approfondimento', 'ghostwriter' ),
			'figura'              => __( 'Figure (immagini AI)', 'ghostwriter' ),
			'tabella'             => __( 'Tabelle', 'ghostwriter' ),
			'elenco'              => __( 'Elenchi', 'ghostwriter' ),
			'esercizio'           => __( 'Esercizi', 'ghostwriter' ),
			'codice'              => __( 'Codice', 'ghostwriter' ),
			'separatore'          => __( 'Separatori', 'ghostwriter' ),
		);
		$defaults    = array( 'paragrafo', 'heading', 'citazione', 'box_approfondimento', 'figura', 'elenco' );
		foreach ( $labels as $value => $label ) {
			$blocks_html .= '<label><input type="checkbox" name="allowed_blocks[]" value="' . esc_attr( $value ) . '"'
				. ( in_array( $value, $defaults, true ) ? ' checked' : '' ) . '/> ' . esc_html( $label ) . '</label>';
		}
		$blocks_html .= '</fieldset>';
		$this->row(
			__( 'Blocchi ammessi', 'ghostwriter' ),
			$blocks_html,
			__( 'Il profilo strutturale: l\'AI può usare solo questi tipi di blocco, e il tema scelto all\'export deve coprirli tutti.', 'ghostwriter' )
		);
		echo '</table>';
		$this->box_close();

		// --- Motore AI (nascosto in modalità manuale) ---
		echo '<div class="gw-ai-only">';
		$this->box_open( __( 'Motore AI', 'ghostwriter' ) );
		echo '<table class="form-table" role="presentation">';

		$anthropic_ok = null !== ApiKeys::anthropic();
		$openai_ok    = null !== ApiKeys::openai();
		$this->row(
			__( 'Provider e modello', 'ghostwriter' ),
			'<select name="provider">'
			. '<option value="anthropic"' . ( $anthropic_ok ? '' : ' disabled' ) . '>Anthropic (Claude)' . ( $anthropic_ok ? '' : ' — ' . esc_html__( 'chiave assente', 'ghostwriter' ) ) . '</option>'
			. '<option value="openai"' . ( $openai_ok ? '' : ' disabled' ) . '>OpenAI' . ( $openai_ok ? '' : ' — ' . esc_html__( 'chiave assente', 'ghostwriter' ) ) . '</option>'
			. '<option value="mock"' . ( $anthropic_ok || $openai_ok ? '' : ' selected' ) . '>' . esc_html__( 'Mock — prova la pipeline senza AI', 'ghostwriter' ) . '</option>'
			. '</select> <input type="text" name="model" value="claude-sonnet-4-5" class="regular-text" aria-label="' . esc_attr__( 'Modello', 'ghostwriter' ) . '" />',
			__( 'Le chiavi API si definiscono in wp-config.php (vedi Impostazioni).', 'ghostwriter' )
		);
		$this->row(
			__( 'Immagini', 'ghostwriter' ),
			'<select name="image_provider"><option value="">' . esc_html__( '— nessuna generazione —', 'ghostwriter' ) . '</option>'
			. '<option value="openai"' . ( $openai_ok ? '' : ' disabled' ) . '>OpenAI</option><option value="mock">mock</option></select> '
			. '<input type="text" name="image_model" placeholder="gpt-image-1" aria-label="' . esc_attr__( 'Modello immagini', 'ghostwriter' ) . '" />',
			__( 'Per figure, artwork di copertina. Con Claude come provider testi serve un provider immagini dedicato.', 'ghostwriter' )
		);
		echo '</table>';
		$this->box_close();
		echo '</div>';

		echo '<p class="submit"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Crea progetto', 'ghostwriter' ) . '</button> '
			. '<a class="button button-hero" href="' . esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_PROJECTS ) ) . '">' . esc_html__( 'Annulla', 'ghostwriter' ) . '</a></p>';
		echo '</form></div>';
	}

	private function box_open( string $title ): void {
		echo '<div class="postbox gw-section"><div class="postbox-header"><h2 class="hndle">' . esc_html( $title ) . '</h2></div><div class="inside">';
	}

	private function box_close(): void {
		echo '</div></div>';
	}

	private function row( string $label, string $field_html, string $description = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $field_html; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * I tre trim size più usati su Amazon KDP + scelta personalizzata.
	 * Il markup è condiviso col dettaglio progetto (stessi name e classi:
	 * il toggle del campo custom vive in gw-admin.js).
	 */
	public static function format_picker( float $width, float $height, string $lock ): string {
		$presets = array(
			'152x229' => __( '6″ × 9″ (15,2 × 22,9 cm) — il più usato su Amazon', 'ghostwriter' ),
			'127x203' => __( '5″ × 8″ (12,7 × 20,3 cm) — romanzi e narrativa', 'ghostwriter' ),
			'140x216' => __( '5,5″ × 8,5″ (14 × 21,6 cm) — saggistica e memoir', 'ghostwriter' ),
		);

		$current = ( (int) round( $width ) ) . 'x' . ( (int) round( $height ) );
		$custom  = ! isset( $presets[ $current ] );

		$html = '<select name="format_preset" class="gw-format-preset"' . $lock . '>';
		foreach ( $presets as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '<option value="custom"' . selected( $custom, true, false ) . '>' . esc_html__( 'Personalizzato…', 'ghostwriter' ) . '</option></select>';

		$html .= ' <span class="gw-format-custom"' . ( $custom ? '' : ' style="display:none"' ) . '>'
			. '<input type="number" name="trim_width_mm" min="80" step="1" value="' . esc_attr( (string) $width ) . '" style="width:80px"' . $lock . '/> × '
			. '<input type="number" name="trim_height_mm" min="100" step="1" value="' . esc_attr( (string) $height ) . '" style="width:80px"' . $lock . '/> mm</span>';

		return $html;
	}
}
