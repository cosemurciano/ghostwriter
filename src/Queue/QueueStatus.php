<?php
declare(strict_types=1);

namespace Ghostwriter\Queue;

/**
 * Fotografia leggibile della coda di un progetto: quali job sono in
 * esecuzione o in attesa su Action Scheduler, a che tentativo sono e quando
 * è previsto il prossimo passaggio. Alimenta il widget "Lavori in corso"
 * nel dettaglio progetto e l'endpoint di polling.
 *
 * La query verso Action Scheduler è iniettabile per i test.
 */
final class QueueStatus {

	public const MAX_ATTEMPTS = 3;

	/** @var callable(array<string, mixed>): array<int, object> */
	private $query;

	public function __construct( ?callable $query = null ) {
		$this->query = $query ?? static function ( array $args ): array {
			if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
				return array();
			}
			return (array) \as_get_scheduled_actions( $args, OBJECT );
		};
	}

	/**
	 * Job attivi (in esecuzione o in coda) del progetto, i running prima.
	 *
	 * @return list<array{job: string, label: string, status: string, attempt: int, next_run: ?string}>
	 */
	public function for_project( int $project_id ): array {
		$found = array();

		foreach ( array( 'in-progress', 'pending' ) as $status ) {
			$actions = ( $this->query )(
				array(
					'group'    => Dispatcher::GROUP,
					'status'   => $status,
					'per_page' => 50,
					'orderby'  => 'date',
					'order'    => 'ASC',
				)
			);

			foreach ( $actions as $action ) {
				if ( ! is_object( $action ) || ! method_exists( $action, 'get_hook' ) || ! method_exists( $action, 'get_args' ) ) {
					continue;
				}
				$hook = (string) $action->get_hook();
				if ( ! str_starts_with( $hook, 'gw_job_' ) ) {
					continue;
				}
				$wrapped = (array) $action->get_args();
				$args    = (array) ( $wrapped[0] ?? array() );
				if ( (int) ( $args['project_id'] ?? 0 ) !== $project_id ) {
					continue;
				}

				$job     = substr( $hook, strlen( 'gw_job_' ) );
				$found[] = array(
					'job'      => $job,
					'label'    => self::job_label( $job ),
					'status'   => $status,
					'attempt'  => max( 1, (int) ( $args['attempt'] ?? 1 ) ),
					'next_run' => self::next_run( $action ),
				);
			}
		}

		return $found;
	}

	/**
	 * Nome umano (italiano) di un job della pipeline.
	 */
	public static function job_label( string $job ): string {
		return match ( $job ) {
			'propose_outline'      => __( 'Proposta indice', 'ghostwriter' ),
			'materialize_chapters' => __( 'Creazione capitoli dall\'indice', 'ghostwriter' ),
			'draft_chapter'        => __( 'Stesura capitolo', 'ghostwriter' ),
			'review_chapter'       => __( 'Revisione capitolo', 'ghostwriter' ),
			'rewrite_block'        => __( 'Riscrittura blocco', 'ghostwriter' ),
			'synopsis'             => __( 'Sinossi capitolo', 'ghostwriter' ),
			'generate_image'       => __( 'Generazione immagine', 'ghostwriter' ),
			'ingest_source'        => __( 'Ingestione fonti', 'ghostwriter' ),
			'index_chapter'        => __( 'Indicizzazione capitolo (RAG)', 'ghostwriter' ),
			'cover_brief'          => __( 'Copertina: brief creativo', 'ghostwriter' ),
			'cover_artwork'        => __( 'Copertina: artwork', 'ghostwriter' ),
			'cover_compose'        => __( 'Copertina: composizione', 'ghostwriter' ),
			'propose_glossary'     => __( 'Proposta glossario', 'ghostwriter' ),
			'translate_chapter'    => __( 'Traduzione capitolo', 'ghostwriter' ),
			'export'               => __( 'Export PDF/ePub', 'ghostwriter' ),
			default                => $job,
		};
	}

	/**
	 * Orario del prossimo passaggio (HH:MM, fuso del sito) se schedulato;
	 * null per i job async che partono appena il runner è libero.
	 */
	private static function next_run( object $action ): ?string {
		if ( ! method_exists( $action, 'get_schedule' ) ) {
			return null;
		}
		try {
			$schedule = $action->get_schedule();
			$date     = ( is_object( $schedule ) && method_exists( $schedule, 'get_date' ) ) ? $schedule->get_date() : null;
			if ( $date instanceof \DateTimeInterface ) {
				return function_exists( 'wp_date' )
					? (string) wp_date( 'H:i', $date->getTimestamp() )
					: $date->format( 'H:i' );
			}
		} catch ( \Throwable ) {
			// Alcuni schedule (async) non hanno data: nessun orario da mostrare.
		}
		return null;
	}
}
