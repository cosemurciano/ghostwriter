<?php
declare(strict_types=1);

namespace Ghostwriter\Domain;

use Ghostwriter\Repository\LogRepository;

/**
 * Macchina a stati unica per progetto, capitolo, copertina e traduzione.
 *
 * Regole (ARCHITECTURE.md §4):
 * - ogni transizione passa da transition(): valida, persiste, scrive su gw_log
 *   e lancia do_action('gw_state_changed', ...);
 * - nessun job scrive stati direttamente;
 * - le transizioni sono le uniche a poter accodare il job successivo
 *   (i listener di gw_state_changed accodano, la pipeline avanza per eventi).
 *
 * Gli eventi "a memoria" (budget_exceeded/failed) salvano lo stato di
 * provenienza; budget_resumed/retry vi ritornano.
 */
final class StateMachine {

	public const TYPE_PROJECT     = 'project';
	public const TYPE_CHAPTER     = 'chapter';
	public const TYPE_COVER       = 'cover';
	public const TYPE_TRANSLATION = 'translation';

	public const META_STATE          = '_gw_state';
	public const META_COVER_STATE    = '_gw_cover_state';
	public const META_PREVIOUS_STATE = '_gw_state_previous';
	public const META_STATE_HISTORY  = '_gw_state_history';

	private const INITIAL = array(
		self::TYPE_PROJECT     => 'setup',
		self::TYPE_CHAPTER     => 'planned',
		self::TYPE_COVER       => 'pending',
		self::TYPE_TRANSLATION => 'setup',
	);

	/**
	 * Mappe di transizione: [tipo][stato][evento] => nuovo stato.
	 */
	private const MAP = array(
		self::TYPE_PROJECT     => array(
			'setup'             => array(
				'sources_ingest_started' => 'sources_ingesting',
				'outline_proposed'       => 'outline_proposed',
				'manual_started'         => 'generating', // Libro manuale: si scrive subito, senza outline AI.
			),
			'sources_ingesting' => array(
				'outline_proposed' => 'outline_proposed',
			),
			'outline_proposed'  => array(
				'outline_proposed' => 'outline_proposed', // Ri-proposta dopo modifica.
				'outline_approved' => 'outline_approved',
			),
			'outline_approved'  => array(
				'generation_started' => 'generating',
			),
			'generating'        => array(
				'generation_completed' => 'review',
			),
			'review'            => array(
				'review_completed' => 'cover_pending',
			),
			'cover_pending'     => array(
				'cover_approved' => 'ready_to_export',
			),
			'ready_to_export'   => array(
				'exported' => 'exported',
			),
			'exported'          => array(
				'exported' => 'exported', // Re-export con altro tema/target.
			),
		),
		self::TYPE_CHAPTER     => array(
			'planned'        => array(
				'draft_started'    => 'drafting',
				'manual_completed' => 'complete', // Scritto a mano nell'editor.
			),
			'drafting'       => array(
				'draft_ready'      => 'draft_ready',
				'manual_completed' => 'complete',
			),
			'draft_ready'    => array(
				'review_started'   => 'in_review',
				'images_requested' => 'images_pending',
				'completed'        => 'complete',
			),
			'in_review'      => array(
				'review_completed' => 'revised',
			),
			'revised'        => array(
				'images_requested' => 'images_pending',
				'completed'        => 'complete',
			),
			'images_pending' => array(
				'completed' => 'complete',
			),
			'complete'       => array(),
		),
		self::TYPE_COVER       => array(
			'pending'       => array(
				'brief_ready' => 'brief_ready',
			),
			'brief_ready'   => array(
				'artwork_ready' => 'artwork_ready',
			),
			'artwork_ready' => array(
				'composed'      => 'composed',
				'artwork_ready' => 'artwork_ready', // Rigenerazione artwork.
			),
			'composed'      => array(
				'approved' => 'approved',
				'rejected'  => 'brief_ready', // Si riparte dal brief.
			),
			'approved'      => array(),
		),
		self::TYPE_TRANSLATION => array(
			'setup'             => array(
				'glossary_proposed' => 'glossary_proposed',
			),
			'glossary_proposed' => array(
				'glossary_proposed' => 'glossary_proposed', // Ri-proposta dopo modifica.
				'glossary_approved' => 'glossary_approved',
			),
			'glossary_approved' => array(
				'translation_started' => 'translating',
			),
			'translating'       => array(
				'translation_completed' => 'review',
			),
			'review'            => array(
				'review_completed' => 'ready_to_export',
			),
			'ready_to_export'   => array(
				'exported' => 'exported',
			),
			'exported'          => array(
				'exported' => 'exported',
			),
		),
	);

	/**
	 * Eventi "a memoria": [tipo][evento] => [stato di parcheggio, evento di ritorno].
	 * Ammessi da qualunque stato non di parcheggio; il ritorno ripristina lo stato salvato.
	 */
	private const MEMORY = array(
		self::TYPE_PROJECT     => array(
			'budget_exceeded' => array( 'paused_budget', 'budget_resumed' ),
		),
		self::TYPE_TRANSLATION => array(
			'budget_exceeded' => array( 'paused_budget', 'budget_resumed' ),
		),
		self::TYPE_CHAPTER     => array(
			'failed' => array( 'failed', 'retry' ),
		),
	);

	public function __construct( private LogRepository $log ) {
	}

	public static function initial_state( string $entity_type ): string {
		self::assert_type( $entity_type );
		return self::INITIAL[ $entity_type ];
	}

	/**
	 * Calcolo puro del nuovo stato (nessun side effect). Usato da transition()
	 * e direttamente testabile.
	 *
	 * @param string      $entity_type    Tipo entità (project|chapter|cover|translation).
	 * @param string      $state          Stato corrente.
	 * @param string      $event          Evento.
	 * @param string|null $previous_state Stato salvato per gli eventi di ritorno (retry/budget_resumed).
	 *
	 * @throws InvalidTransitionException Se l'evento non è ammesso.
	 */
	public static function apply( string $entity_type, string $state, string $event, ?string $previous_state = null ): string {
		self::assert_type( $entity_type );

		$memory = self::MEMORY[ $entity_type ] ?? array();

		// Evento di parcheggio (failed / budget_exceeded): ammesso da ogni stato operativo.
		foreach ( $memory as $park_event => [$park_state, $return_event] ) {
			if ( $event === $park_event ) {
				if ( $state === $park_state ) {
					throw InvalidTransitionException::create( $entity_type, $state, $event );
				}
				return $park_state;
			}
			// Evento di ritorno: solo dallo stato di parcheggio, verso lo stato salvato.
			if ( $event === $return_event ) {
				if ( $state !== $park_state || null === $previous_state ) {
					throw InvalidTransitionException::create( $entity_type, $state, $event );
				}
				return $previous_state;
			}
		}

		$next = self::MAP[ $entity_type ][ $state ][ $event ] ?? null;
		if ( null === $next ) {
			throw InvalidTransitionException::create( $entity_type, $state, $event );
		}
		return $next;
	}

	public static function can( string $entity_type, string $state, string $event, ?string $previous_state = null ): bool {
		try {
			self::apply( $entity_type, $state, $event, $previous_state );
			return true;
		} catch ( InvalidTransitionException ) {
			return false;
		}
	}

	/**
	 * Esegue la transizione su un'entità persistita: valida, salva lo stato,
	 * aggiorna la cronologia, logga su gw_log e lancia gw_state_changed.
	 *
	 * @param int                  $post_id     ID del post gw_project o gw_chapter.
	 * @param string               $entity_type Tipo entità.
	 * @param string               $event       Evento.
	 * @param array<string, mixed> $context     Contesto aggiuntivo per il log.
	 *
	 * @return string Il nuovo stato.
	 * @throws InvalidTransitionException Se l'evento non è ammesso.
	 */
	public function transition( int $post_id, string $entity_type, string $event, array $context = array() ): string {
		$state_meta = self::TYPE_COVER === $entity_type ? self::META_COVER_STATE : self::META_STATE;

		$current = (string) get_post_meta( $post_id, $state_meta, true );
		if ( '' === $current ) {
			$current = self::initial_state( $entity_type );
		}

		$previous_meta  = self::META_PREVIOUS_STATE . ( self::TYPE_COVER === $entity_type ? '_cover' : '' );
		$previous_state = (string) get_post_meta( $post_id, $previous_meta, true ) ?: null;

		$next = self::apply( $entity_type, $current, $event, $previous_state );

		// Gli eventi di parcheggio memorizzano da dove si arriva; quelli di ritorno consumano la memoria.
		$memory = self::MEMORY[ $entity_type ] ?? array();
		foreach ( $memory as $park_event => [$park_state, $return_event] ) {
			if ( $event === $park_event ) {
				update_post_meta( $post_id, $previous_meta, $current );
			} elseif ( $event === $return_event ) {
				delete_post_meta( $post_id, $previous_meta );
			}
		}

		update_post_meta( $post_id, $state_meta, $next );

		$history   = get_post_meta( $post_id, self::META_STATE_HISTORY, true );
		$history   = is_array( $history ) ? $history : array();
		$history[] = array(
			'entity' => $entity_type,
			'from'   => $current,
			'to'     => $next,
			'event'  => $event,
			'at'     => gmdate( 'c' ),
		);
		update_post_meta( $post_id, self::META_STATE_HISTORY, $history );

		$project_id = self::TYPE_CHAPTER === $entity_type
			? (int) get_post_meta( $post_id, '_gw_project_id', true )
			: $post_id;

		$this->log->log(
			$project_id,
			self::TYPE_CHAPTER === $entity_type ? $post_id : null,
			'info',
			'state_changed',
			array_merge(
				$context,
				array(
					'entity' => $entity_type,
					'from'   => $current,
					'to'     => $next,
					'event'  => $event,
				)
			)
		);

		/**
		 * La pipeline avanza per eventi: i listener accodano il job successivo.
		 *
		 * @param int    $post_id     ID entità.
		 * @param string $entity_type project|chapter|cover|translation.
		 * @param string $from        Stato di partenza.
		 * @param string $next        Nuovo stato.
		 * @param string $event       Evento applicato.
		 */
		do_action( 'gw_state_changed', $post_id, $entity_type, $current, $next, $event );

		return $next;
	}

	/**
	 * Stato corrente persistito (o iniziale se mai transitato).
	 */
	public function state_of( int $post_id, string $entity_type ): string {
		$state_meta = self::TYPE_COVER === $entity_type ? self::META_COVER_STATE : self::META_STATE;
		$state      = (string) get_post_meta( $post_id, $state_meta, true );
		return '' !== $state ? $state : self::initial_state( $entity_type );
	}

	private static function assert_type( string $entity_type ): void {
		if ( ! isset( self::INITIAL[ $entity_type ] ) ) {
			throw new \InvalidArgumentException( "Tipo di entità sconosciuto: {$entity_type}" );
		}
	}
}
