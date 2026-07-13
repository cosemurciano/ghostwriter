<?php
/**
 * Catalogo dei modelli AI disponibili per provider.
 *
 * @package Ghostwriter
 */

declare(strict_types=1);

namespace Ghostwriter\Ai;

/**
 * Elenco curato dei modelli correnti per ogni provider, per popolare i menu
 * a tendina dell'admin. L'opzione "Personalizzato…" (valore CUSTOM) apre un
 * campo libero, così un modello nuovo resta usabile anche prima di un
 * aggiornamento del plugin. Il catalogo è filtrabile via gw_model_catalog.
 */
final class ModelCatalog {

	/** Valore sentinella dell'opzione "Personalizzato…" nei select. */
	public const CUSTOM = '__custom__';

	/**
	 * Modelli testo per provider: provider => [model_id => etichetta].
	 * Il primo modello di ogni provider è il default consigliato.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function text_models(): array {
		$catalog = array(
			'anthropic' => array(
				'claude-opus-4-8'   => __( 'Claude Opus 4.8 — consigliato', 'ghostwriter' ),
				'claude-sonnet-5'   => __( 'Claude Sonnet 5 — veloce ed economico', 'ghostwriter' ),
				'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
				'claude-haiku-4-5'  => __( 'Claude Haiku 4.5 — il più economico', 'ghostwriter' ),
				'claude-fable-5'    => __( 'Claude Fable 5 — il più capace', 'ghostwriter' ),
			),
			'openai'    => array(
				'gpt-5'      => 'GPT-5',
				'gpt-5-mini' => 'GPT-5 mini',
				'gpt-4o'     => 'GPT-4o',
			),
			'mock'      => array(
				'mock' => __( 'Mock — nessuna chiamata AI', 'ghostwriter' ),
			),
		);

		/**
		 * Filtra il catalogo dei modelli mostrati nei menu a tendina.
		 *
		 * @param array<string, array<string, string>> $catalog provider => [id => etichetta].
		 * @param string                               $kind    'text' o 'image'.
		 */
		return apply_filters( 'gw_model_catalog', $catalog, 'text' );
	}

	/**
	 * Modelli immagine per provider: provider => [model_id => etichetta].
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function image_models(): array {
		$catalog = array(
			'openai' => array(
				'gpt-image-1' => 'GPT Image 1',
			),
			'mock'   => array(
				'mock' => __( 'Mock — immagine segnaposto', 'ghostwriter' ),
			),
		);

		/** Documentato in text_models(). */
		return apply_filters( 'gw_model_catalog', $catalog, 'image' );
	}

	/**
	 * Il modello di default (il primo in catalogo) per un provider.
	 */
	public static function default_for( string $provider, string $kind = 'text' ): string {
		$catalog = 'image' === $kind ? self::image_models() : self::text_models();
		$models  = $catalog[ $provider ] ?? array();

		return '' === $provider || array() === $models ? '' : (string) array_key_first( $models );
	}

	/**
	 * Markup del selettore modello: un select per provider (visibile solo
	 * quello del provider attivo, gli altri disabled così non finiscono nel
	 * FormData) + campo libero per "Personalizzato…". Il toggle al cambio di
	 * provider e sull'opzione custom vive in gw-admin.js.
	 *
	 * @param string $kind           'text' o 'image'.
	 * @param string $name           Nome del campo (model | image_model).
	 * @param string $provider       Provider attualmente selezionato ('' = nessuno).
	 * @param string $current        Modello attualmente configurato.
	 * @param string $provider_field Nome del select provider da osservare.
	 */
	public static function picker( string $kind, string $name, string $provider, string $current, string $provider_field ): string {
		$catalog = 'image' === $kind ? self::image_models() : self::text_models();

		$active_models = $catalog[ $provider ] ?? array();
		$is_custom     = '' !== $provider && '' !== $current && ! isset( $active_models[ $current ] );

		$html = '<span class="gw-model-picker" data-gw-provider-field="' . esc_attr( $provider_field ) . '">';

		foreach ( $catalog as $prov => $models ) {
			$active = $prov === $provider;
			$html  .= '<select name="' . esc_attr( $name ) . '" data-gw-models-for="' . esc_attr( $prov ) . '"'
				. ( $active ? '' : ' disabled hidden' )
				. ' aria-label="' . esc_attr__( 'Modello', 'ghostwriter' ) . '">';

			$first = true;
			foreach ( $models as $id => $label ) {
				$selected = $active && ! $is_custom && ( $current === $id || ( '' === $current && $first ) );
				$html    .= '<option value="' . esc_attr( $id ) . '"' . ( $selected ? ' selected' : '' ) . '>'
					. esc_html( $label ) . '</option>';
				$first    = false;
			}

			$html .= '<option value="' . esc_attr( self::CUSTOM ) . '"' . ( $active && $is_custom ? ' selected' : '' ) . '>'
				. esc_html__( 'Personalizzato…', 'ghostwriter' ) . '</option></select>';
		}

		$html .= ' <input type="text" name="' . esc_attr( $name ) . '_custom" class="gw-model-custom" '
			. 'value="' . esc_attr( $is_custom ? $current : '' ) . '"'
			. ( $is_custom ? '' : ' hidden' )
			. ' placeholder="model-id" aria-label="' . esc_attr__( 'Modello personalizzato', 'ghostwriter' ) . '" />';

		return $html . '</span>';
	}
}
