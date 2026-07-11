<?php
declare(strict_types=1);

namespace Ghostwriter\Queue\Jobs;

use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Queue\JobInterface;
use Ghostwriter\Rendering\BookAssembler;
use Ghostwriter\Rendering\EpubExporter;
use Ghostwriter\Rendering\PdfExporter;
use Ghostwriter\Rendering\Preflight;
use Ghostwriter\Rendering\Theme;
use Ghostwriter\Rendering\ThemeRegistry;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;

/**
 * Export: assemblaggio → rendering → file in uploads/ghostwriter/{project_id}/.
 * Il preflight completo (§8) arriva in fase 6; qui valgono i guard di base
 * (tema esistente, copertura blocchi, formato supportato dentro PdfExporter).
 */
final class ExportJob implements JobInterface {

	public const META_EXPORTS = '_gw_exports';

	public function __construct(
		private ProjectRepository $projects,
		private \Ghostwriter\Repository\ChapterRepository $chapters,
		private BookAssembler $assembler,
		private ThemeRegistry $themes,
		private Preflight $preflight,
		private PdfExporter $pdf,
		private EpubExporter $epub,
		private StateMachine $states,
		private LogRepository $log
	) {
	}

	public static function name(): string {
		return 'export';
	}

	public function handle( array $args ): void {
		$project_id    = (int) ( $args['project_id'] ?? 0 );
		$theme_id      = (string) ( $args['theme_id'] ?? '' );
		$theme_version = isset( $args['theme_version'] ) ? (string) $args['theme_version'] : null;
		$target        = (string) ( $args['target'] ?? 'pdf' );

		if ( ! in_array( $target, array( 'pdf', 'epub' ), true ) ) {
			throw new \InvalidArgumentException( "Target di export sconosciuto: {$target}" );
		}

		$theme = $this->themes->get( $theme_id, $theme_version );
		if ( null === $theme ) {
			throw new \RuntimeException( "Tema {$theme_id} non trovato nel registry." );
		}

		$config = $this->projects->get_config( $project_id );

		// Preflight (§8): gli errori bloccano l'export (override con force).
		$contents = array();
		foreach ( $this->projects->get_chapter_ids( $project_id ) as $chapter_id ) {
			$content = $this->chapters->get_content( $chapter_id );
			if ( null !== $content ) {
				$contents[ $chapter_id ] = $content;
			}
		}
		$report = $this->preflight->run( $contents, $config, $this->projects->get_dossier( $project_id ) ?? array(), $theme, $target );
		update_post_meta( $project_id, '_gw_preflight', $report );

		if ( ! empty( $report['errors'] ) && empty( $args['force'] ) ) {
			throw new \RuntimeException( 'Preflight fallito: ' . implode( ' | ', array_slice( $report['errors'], 0, 5 ) ) );
		}

		$book = $this->assembler->assemble( $project_id );

		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'ghostwriter/' . $project_id;
		wp_mkdir_p( $dir );

		$filename = sanitize_file_name(
			sprintf( '%s-%s-%s.%s', sanitize_title( $book->title ?: 'libro' ), $theme->id(), gmdate( 'Ymd-His' ), $target )
		);
		$path     = $dir . '/' . $filename;

		if ( 'pdf' === $target ) {
			$this->pdf->export( $book, $theme, $path );
		} else {
			$this->epub->export( $book, $theme, $path );
		}

		// Registro dei file generati (serviti da endpoint autenticato, mai URL diretti).
		$exports   = get_post_meta( $project_id, self::META_EXPORTS, true );
		$exports   = is_array( $exports ) ? $exports : array();
		$exports[] = array(
			'file'          => $filename,
			'target'        => $target,
			'theme'         => $theme->id() . '@' . $theme->version(),
			'size'          => (int) filesize( $path ),
			'created_at'    => gmdate( 'c' ),
		);
		update_post_meta( $project_id, self::META_EXPORTS, $exports );

		// La transizione avviene solo dallo stato che la ammette (re-export incluso).
		$state = $this->states->state_of( $project_id, StateMachine::TYPE_PROJECT );
		if ( StateMachine::can( StateMachine::TYPE_PROJECT, $state, 'exported' ) ) {
			$this->states->transition( $project_id, StateMachine::TYPE_PROJECT, 'exported', array( 'job' => self::name(), 'file' => $filename ) );
		}

		$this->log->log( $project_id, null, LogRepository::LEVEL_INFO, 'export_completed', array( 'file' => $filename, 'target' => $target ) );
	}

	public function on_failure( array $args, \Throwable $e ): void {
		$this->log->log(
			(int) ( $args['project_id'] ?? 0 ),
			null,
			LogRepository::LEVEL_ERROR,
			'export_failed',
			array( 'target' => $args['target'] ?? '', 'error' => $e->getMessage() )
		);
	}
}
