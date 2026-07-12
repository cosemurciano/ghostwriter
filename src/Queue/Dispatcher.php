<?php
declare(strict_types=1);

namespace Ghostwriter\Queue;

use Ghostwriter\Repository\LogRepository;

/**
 * Accodamento e ciclo di vita dei job su Action Scheduler.
 *
 * - Dedup: la chiave {job}:{project_id}:{chapter_id}:{extra} scarta i
 *   duplicati già in coda (i job possono ancora essere rieseguiti dopo il
 *   completamento: l'idempotenza interna del job copre quel caso).
 * - Retry: 3 tentativi con backoff esponenziale (60s, 240s, 960s); esauriti
 *   i tentativi, on_failure() del job porta l'entità in failed.
 *
 * Le funzioni di Action Scheduler sono iniettabili per i test.
 */
final class Dispatcher {

	public const GROUP = 'ghostwriter';

	private const MAX_ATTEMPTS = 3;
	private const BACKOFF_BASE = 60;

	/** @var array<string, class-string<JobInterface>> name → classe */
	private array $jobs = array();

	/** @var callable(string, array, string): mixed */
	private $enqueue_async;

	/** @var callable(int, string, array, string): mixed */
	private $schedule_single;

	/** @var callable(string, array, string): bool */
	private $has_scheduled;

	/**
	 * @param callable(class-string<JobInterface>): JobInterface $job_factory     Risolve l'istanza del job (dal container).
	 * @param LogRepository                                      $log             Log pipeline.
	 * @param callable|null                                      $enqueue_async   Default: as_enqueue_async_action.
	 * @param callable|null                                      $schedule_single Default: as_schedule_single_action.
	 * @param callable|null                                      $has_scheduled   Default: as_has_scheduled_action.
	 */
	public function __construct(
		private $job_factory,
		private LogRepository $log,
		?callable $enqueue_async = null,
		?callable $schedule_single = null,
		?callable $has_scheduled = null
	) {
		$this->enqueue_async   = $enqueue_async ?? static function ( string $hook, array $args, string $group ) {
			self::assert_scheduler_loaded();
			return \as_enqueue_async_action( $hook, $args, $group );
		};
		$this->schedule_single = $schedule_single ?? static function ( int $ts, string $hook, array $args, string $group ) {
			self::assert_scheduler_loaded();
			return \as_schedule_single_action( $ts, $hook, $args, $group );
		};
		$this->has_scheduled   = $has_scheduled ?? static function ( string $hook, array $args, string $group ): bool {
			self::assert_scheduler_loaded();
			return \as_has_scheduled_action( $hook, $args, $group );
		};
	}

	/**
	 * Registra un job: mappa il nome e aggancia l'hook di Action Scheduler.
	 *
	 * @param class-string<JobInterface> $job_class Classe del job.
	 */
	public function register_job( string $job_class ): void {
		$name                = $job_class::name();
		$this->jobs[ $name ] = $job_class;

		add_action(
			self::hook_for( $name ),
			function ( array $args = array() ) use ( $name ): void {
				$this->run( $name, $args );
			},
			10,
			1
		);
	}

	public static function hook_for( string $job_name ): string {
		return 'gw_job_' . $job_name;
	}

	/**
	 * Errore esplicito (invece del fatal "undefined function") se Action
	 * Scheduler non è inizializzato: succede se viene caricato dentro un
	 * callback di plugins_loaded anziché nel file principale del plugin.
	 */
	private static function assert_scheduler_loaded(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			throw new \RuntimeException(
				'Action Scheduler non è inizializzato: impossibile accodare i lavori in background. Aggiorna Ghostwriter e riattiva il plugin; se persiste, verifica la cartella vendor/woocommerce/action-scheduler.'
			);
		}
	}

	/**
	 * Chiave di dedup: {job}:{project_id}:{chapter_id}:{extra ordinati}.
	 * Il tentativo corrente non partecipa (i retry non sono duplicati).
	 *
	 * @param array<string, mixed> $args Argomenti del job.
	 */
	public static function dedup_key( string $job_name, array $args ): string {
		unset( $args['attempt'] );

		$parts = array(
			$job_name,
			(string) ( $args['project_id'] ?? '' ),
			(string) ( $args['chapter_id'] ?? '' ),
		);
		unset( $args['project_id'], $args['chapter_id'] );

		ksort( $args );
		foreach ( $args as $key => $value ) {
			$parts[] = $key . '=' . ( is_scalar( $value ) || null === $value ? (string) $value : md5( (string) wp_json_encode( $value ) ) );
		}

		return implode( ':', $parts );
	}

	/**
	 * Accoda un job (se un'istanza identica non è già in coda).
	 *
	 * @param class-string<JobInterface> $job_class Classe del job.
	 * @param array<string, mixed>       $args      Argomenti serializzabili.
	 * @return bool True se accodato, false se scartato per dedup.
	 */
	public function dispatch( string $job_class, array $args = array() ): bool {
		$name = $job_class::name();
		if ( ! isset( $this->jobs[ $name ] ) ) {
			throw new \InvalidArgumentException( "Job non registrato: {$name}" );
		}

		unset( $args['attempt'] );
		$hook    = self::hook_for( $name );
		$wrapped = array( $args );

		if ( ( $this->has_scheduled )( $hook, $wrapped, self::GROUP ) ) {
			return false;
		}

		( $this->enqueue_async )( $hook, $wrapped, self::GROUP );

		$this->log->log(
			(int) ( $args['project_id'] ?? 0 ),
			isset( $args['chapter_id'] ) ? (int) $args['chapter_id'] : null,
			LogRepository::LEVEL_INFO,
			'job_dispatched',
			array( 'job' => $name, 'dedup_key' => self::dedup_key( $name, $args ) )
		);

		return true;
	}

	/**
	 * Esecuzione di un job (invocato dall'hook Action Scheduler): gestisce
	 * tentativi, backoff e fallimento definitivo.
	 *
	 * @param array<string, mixed> $args Argomenti del job.
	 */
	public function run( string $job_name, array $args ): void {
		$job_class = $this->jobs[ $job_name ] ?? null;
		if ( null === $job_class ) {
			return;
		}

		$attempt = max( 1, (int) ( $args['attempt'] ?? 1 ) );
		$job     = ( $this->job_factory )( $job_class );

		try {
			$job->handle( $args );
		} catch ( \Throwable $e ) {
			$this->log->log(
				(int) ( $args['project_id'] ?? 0 ),
				isset( $args['chapter_id'] ) ? (int) $args['chapter_id'] : null,
				LogRepository::LEVEL_WARNING,
				'job_attempt_failed',
				array(
					'job'     => $job_name,
					'attempt' => $attempt,
					'error'   => $e->getMessage(),
				)
			);

			if ( $attempt < self::MAX_ATTEMPTS ) {
				// Backoff esponenziale: 60s, 240s (60·4^n).
				$delay           = self::BACKOFF_BASE * ( 4 ** ( $attempt - 1 ) );
				$args['attempt'] = $attempt + 1;
				( $this->schedule_single )( time() + $delay, self::hook_for( $job_name ), array( $args ), self::GROUP );
				return;
			}

			$job->on_failure( $args, $e );

			$this->log->log(
				(int) ( $args['project_id'] ?? 0 ),
				isset( $args['chapter_id'] ) ? (int) $args['chapter_id'] : null,
				LogRepository::LEVEL_ERROR,
				'job_failed',
				array(
					'job'   => $job_name,
					'error' => $e->getMessage(),
				)
			);
		}
	}
}
