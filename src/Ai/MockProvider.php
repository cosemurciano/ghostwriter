<?php
declare(strict_types=1);

namespace Ghostwriter\Ai;

/**
 * Provider finto per sviluppo e test della pipeline (fase 3): output
 * deterministico, sempre conforme agli schemi, nessuna rete.
 */
final class MockProvider implements ProviderInterface {

	public function complete( AiRequest $request ): AiResult {
		$content = match ( $request->phase ) {
			AiRequest::PHASE_OUTLINE  => $this->outline( $request ),
			AiRequest::PHASE_DRAFT    => $this->draft( $request ),
			AiRequest::PHASE_SYNOPSIS => $this->synopsis( $request ),
			AiRequest::PHASE_REVIEW   => $this->review( $request ),
			AiRequest::PHASE_REWRITE  => $this->rewrite( $request ),
			AiRequest::PHASE_GLOSSARY => $this->glossary( $request ),
			AiRequest::PHASE_COVER    => array(
				'creative_brief' => 'Illustrazione (mock) evocativa del tema del libro, stile acquerello, palette terrosa, nessun testo.',
			),
			AiRequest::PHASE_TRANSLATION => $this->translation( $request ),
			default                   => throw new \InvalidArgumentException( "Fase sconosciuta: {$request->phase}" ),
		};

		return new AiResult(
			content: $content,
			input_tokens: 1000,
			output_tokens: 800,
			model: 'mock-1'
		);
	}

	/**
	 * @return array<string, mixed> Proposta di indice: {chapters: [{title, brief, planned_sources}]}.
	 */
	private function outline( AiRequest $request ): array {
		$thesis = (string) ( $request->context['brief']['thesis'] ?? 'il tema del libro' );

		$chapters = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$chapters[] = array(
				'title'           => "Capitolo {$i} (mock)",
				'brief'           => "Obiettivo del capitolo {$i}: sviluppare {$thesis}.",
				'planned_sources' => array(),
			);
		}

		return array( 'chapters' => $chapters );
	}

	/**
	 * @return array<string, mixed> Formato intermedio completo del capitolo.
	 */
	private function draft( AiRequest $request ): array {
		$chapter_id = (int) ( $request->chapter_id ?? 0 );
		$title      = (string) ( $request->context['chapter_brief']['title'] ?? "Capitolo {$chapter_id}" );

		return array(
			'schema_version' => '1.0',
			'chapter_id'     => $chapter_id,
			'meta'           => array(
				'title'          => $title,
				'word_count'     => 42,
				'generated_with' => array(
					'model'  => 'mock-1',
					'skills' => array(),
				),
			),
			'blocks'         => array(
				array(
					'id'      => self::block_id( $chapter_id, 1 ),
					'type'    => 'paragrafo',
					'version' => 1,
					'props'   => array(
						'text' => "Paragrafo di apertura (mock) per *{$title}*.",
						'role' => 'lead',
					),
				),
				array(
					'id'      => self::block_id( $chapter_id, 2 ),
					'type'    => 'heading',
					'version' => 1,
					'props'   => array(
						'text'  => 'Prima sezione (mock)',
						'level' => 2,
					),
				),
				array(
					'id'      => self::block_id( $chapter_id, 3 ),
					'type'    => 'paragrafo',
					'version' => 1,
					'props'   => array(
						'text' => 'Contenuto della sezione, generato dal provider mock per esercitare la pipeline end-to-end.',
						'role' => 'normal',
					),
				),
			),
		);
	}

	/**
	 * @return array<string, mixed> {synopsis, continuity}.
	 */
	private function synopsis( AiRequest $request ): array {
		$title = (string) ( $request->context['chapter_title'] ?? 'il capitolo' );

		return array(
			'synopsis'   => "Sinossi (mock) di {$title}: i punti chiave trattati e il filo che porta al capitolo successivo.",
			'continuity' => array(
				'terminology' => array(),
				'promises'    => array(),
			),
		);
	}

	/**
	 * La revisione mock restituisce il contenuto invariato (idempotente).
	 *
	 * @return array<string, mixed>
	 */
	private function review( AiRequest $request ): array {
		$content = (array) ( $request->context['content'] ?? array() );
		if ( empty( $content ) ) {
			throw new \InvalidArgumentException( 'Contesto review senza contenuto.' );
		}
		return $content;
	}

	/**
	 * Riscrittura: stesso id e stesso type, testo che incorpora il feedback.
	 *
	 * @return array<string, mixed> Il SOLO blocco riscritto.
	 */
	private function rewrite( AiRequest $request ): array {
		$block    = (array) ( $request->context['block'] ?? array() );
		$feedback = (string) ( $request->context['feedback'] ?? '' );
		if ( empty( $block['id'] ) || empty( $block['type'] ) ) {
			throw new \InvalidArgumentException( 'Contesto rewrite senza blocco.' );
		}

		if ( 'paragrafo' === $block['type'] || 'citazione' === $block['type'] ) {
			$block['props']['text'] = 'Versione riscritta (mock) tenendo conto del feedback: ' . $feedback;
		}

		return $block;
	}

	/**
	 * PNG 1x1 trasparente: sufficiente per esercitare la pipeline immagini
	 * (Media Library, blocchi figura) senza dipendenze grafiche.
	 */
	public function generate_image( ImageRequest $request ): ImageResult {
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
		return new ImageResult( (string) $png, 'image/png', 'mock-image-1' );
	}

	/**
	 * @return array<string, mixed> {glossary: [{source_term, target_term}]}.
	 */
	private function glossary( AiRequest $request ): array {
		$glossary = array();
		foreach ( (array) ( $request->context['candidate_terms'] ?? array() ) as $candidate ) {
			$term       = (string) ( $candidate['term'] ?? '' );
			$glossary[] = array(
				'source_term' => $term,
				'target_term' => '[tr] ' . $term,
				'note'        => '',
			);
		}
		if ( empty( $glossary ) ) {
			$glossary[] = array(
				'source_term' => 'termine',
				'target_term' => '[tr] termine',
			);
		}
		return array( 'glossary' => $glossary );
	}

	/**
	 * Traduzione mock: stessi id e type, testi prefissati con la lingua target.
	 *
	 * @return array<string, mixed>
	 */
	private function translation( AiRequest $request ): array {
		$source = (array) ( $request->context['source_content'] ?? array() );
		$lang   = (string) ( $request->context['target_language'] ?? 'xx' );
		if ( empty( $source['blocks'] ) ) {
			throw new \InvalidArgumentException( 'Contesto translation senza source_content.' );
		}

		if ( isset( $source['meta']['title'] ) ) {
			$source['meta']['title'] = "[{$lang}] " . $source['meta']['title'];
		}
		$source['blocks'] = self::translate_blocks( $source['blocks'], $lang );

		return $source;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Blocchi sorgente.
	 * @return array<int, array<string, mixed>>
	 */
	private static function translate_blocks( array $blocks, string $lang ): array {
		foreach ( $blocks as $i => $block ) {
			$block = (array) $block;
			// Props vuote = stdClass (normalizzazione schema): cast obbligato.
			$props                   = (array) ( $block['props'] ?? array() );
			$blocks[ $i ]['version'] = 1;
			foreach ( array( 'text', 'title', 'caption' ) as $key ) {
				if ( ! empty( $props[ $key ] ) && is_string( $props[ $key ] ) ) {
					$blocks[ $i ]['props'][ $key ] = "[{$lang}] " . $props[ $key ];
				}
			}
			if ( ! empty( $props['blocks'] ) && is_array( $props['blocks'] ) ) {
				$blocks[ $i ]['props']['blocks'] = self::translate_blocks( $props['blocks'], $lang );
			}
		}
		return $blocks;
	}

	private static function block_id( int $chapter_id, int $n ): string {
		return sprintf( 'mock-%08d-%04d', $chapter_id, $n );
	}
}
