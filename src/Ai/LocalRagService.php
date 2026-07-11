<?php
declare(strict_types=1);

namespace Ghostwriter\Ai;

use Ghostwriter\Repository\RagChunkRepository;

/**
 * Vector store locale v1: chunking + recupero lessicale (TF con
 * penalizzazione dei termini onnipresenti), nessuna chiamata esterna e
 * nessun lock-in di provider. Le dimensioni sono da libro singolo, non da
 * corpus: lo scoring in memoria è più che sufficiente.
 *
 * Il passaggio a embeddings (vector store del provider) potrà sostituire
 * questa classe dietro la stessa interfaccia.
 */
final class LocalRagService implements RagServiceInterface {

	public const CHUNK_SIZE_CHARS = 1200;
	public const CHUNK_OVERLAP    = 150;

	public function __construct( private RagChunkRepository $chunks ) {
	}

	/**
	 * Ingerisce il testo di una fonte (idempotente: reingestione = replace).
	 *
	 * @return int Numero di frammenti indicizzati.
	 */
	public function ingest_source( int $project_id, string $source_id, string $text ): int {
		$pieces = self::chunk_text( $text );
		$this->chunks->delete_for_source( $project_id, $source_id );
		$this->chunks->insert_chunks( $project_id, $source_id, null, $pieces );
		return count( $pieces );
	}

	/**
	 * Indicizza un capitolo completato (richiamo puntuale nei successivi).
	 *
	 * @return int Numero di frammenti indicizzati.
	 */
	public function ingest_chapter( int $project_id, int $chapter_id, string $text ): int {
		$pieces = self::chunk_text( $text );
		$this->chunks->delete_for_chapter( $project_id, $chapter_id );
		$this->chunks->insert_chunks( $project_id, null, $chapter_id, $pieces );
		return count( $pieces );
	}

	public function query( int $project_id, string $query, int $k = 5 ): array {
		$all = $this->chunks->all_for_project( $project_id );
		if ( empty( $all ) ) {
			return array();
		}

		$query_terms = self::terms( $query );
		if ( empty( $query_terms ) ) {
			return array();
		}

		// Document frequency per penalizzare i termini presenti ovunque.
		$df = array();
		foreach ( $all as $row ) {
			foreach ( array_unique( self::terms( $row['chunk'] ) ) as $term ) {
				$df[ $term ] = ( $df[ $term ] ?? 0 ) + 1;
			}
		}
		$n = count( $all );

		$scored = array();
		foreach ( $all as $i => $row ) {
			$chunk_terms = self::terms( $row['chunk'] );
			$tf          = array_count_values( $chunk_terms );

			$score = 0.0;
			foreach ( $query_terms as $term ) {
				if ( isset( $tf[ $term ] ) ) {
					$idf    = log( 1 + $n / ( 1 + ( $df[ $term ] ?? 0 ) ) );
					$score += $tf[ $term ] * $idf;
				}
			}
			if ( $score > 0 ) {
				$scored[ $i ] = $score / ( 1 + log( 1 + count( $chunk_terms ) ) );
			}
		}

		arsort( $scored );

		$results = array();
		foreach ( array_slice( array_keys( $scored ), 0, $k, true ) as $i ) {
			$results[] = array(
				'text'      => $all[ $i ]['chunk'],
				'source_id' => $all[ $i ]['source_id'] ?? ( null !== $all[ $i ]['chapter_id'] ? 'capitolo:' . $all[ $i ]['chapter_id'] : null ),
			);
		}
		return $results;
	}

	/**
	 * Chunking a finestre di caratteri con overlap, spezzando sui confini
	 * di parola.
	 *
	 * @return string[]
	 */
	public static function chunk_text( string $text ): array {
		$text = trim( $text );
		if ( '' === $text ) {
			return array();
		}

		$chunks = array();
		$offset = 0;
		$length = mb_strlen( $text );

		while ( $offset < $length ) {
			// Ultima finestra: si prende tutto e si chiude (un avanzamento
			// con overlap qui produrrebbe frammenti duplicati).
			if ( $offset + self::CHUNK_SIZE_CHARS >= $length ) {
				$chunk = trim( mb_substr( $text, $offset ) );
				if ( '' !== $chunk ) {
					$chunks[] = $chunk;
				}
				break;
			}

			$window = mb_substr( $text, $offset, self::CHUNK_SIZE_CHARS );

			// Arretra all'ultimo spazio per non spezzare le parole.
			$last_space = mb_strrpos( $window, ' ' );
			if ( false !== $last_space && $last_space > self::CHUNK_SIZE_CHARS / 2 ) {
				$window = mb_substr( $window, 0, $last_space );
			}

			$chunk = trim( $window );
			if ( '' !== $chunk ) {
				$chunks[] = $chunk;
			}

			$offset += max( 1, mb_strlen( $window ) - self::CHUNK_OVERLAP );
		}

		return $chunks;
	}

	/**
	 * Tokenizzazione: minuscolo, solo parole di 3+ caratteri.
	 *
	 * @return string[]
	 */
	private static function terms( string $text ): array {
		preg_match_all( '/\p{L}{3,}/u', mb_strtolower( $text ), $matches );
		return $matches[0];
	}
}
