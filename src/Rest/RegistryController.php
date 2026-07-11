<?php
declare(strict_types=1);

namespace Ghostwriter\Rest;

use Ghostwriter\Ai\SkillsManager;
use Ghostwriter\Core\Capabilities;
use Ghostwriter\Rendering\ThemeRegistry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Endpoint registry (§9): temi (elenco + import zip) e skills
 * (elenco + import). Import riservati a gw_manage_settings.
 */
final class RegistryController {

	public function __construct(
		private ThemeRegistry $themes,
		private SkillsManager $skills
	) {
	}

	public function register_routes(): void {
		$manage_settings = static fn(): bool => current_user_can( Capabilities::MANAGE_SETTINGS );
		$manage          = static fn(): bool => current_user_can( Capabilities::MANAGE_PROJECTS );

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/themes',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_themes' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/themes/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_theme' ),
				'permission_callback' => $manage_settings,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/skills',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_skills' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/skills/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_skill' ),
				'permission_callback' => $manage_settings,
			)
		);
	}

	public function list_themes( WP_REST_Request $request ): WP_REST_Response {
		$themes = array();
		foreach ( $this->themes->all() as $key => $theme ) {
			$raw      = $theme->raw();
			$themes[] = array(
				'key'              => $key,
				'id'               => $theme->id(),
				'name'             => $theme->name(),
				'version'          => $theme->version(),
				'supports_formats' => $raw['meta']['supports_formats'] ?? array(),
				'supports_blocks'  => $theme->supports_blocks(),
			);
		}
		return new WP_REST_Response( array( 'themes' => $themes ) );
	}

	/**
	 * Import tema da zip caricato (multipart, campo "bundle").
	 */
	public function import_theme( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$files = $request->get_file_params();
		$file  = $files['bundle'] ?? null;

		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== ( $file['error'] ?? -1 ) ) {
			return new WP_Error( 'gw_invalid_upload', 'Caricare lo zip del tema nel campo "bundle".', array( 'status' => 400 ) );
		}

		try {
			$theme = $this->themes->import_zip( (string) $file['tmp_name'] );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'gw_theme_rejected', $e->getMessage(), array( 'status' => 422 ) );
		}

		return new WP_REST_Response(
			array(
				'id'      => $theme->id(),
				'name'    => $theme->name(),
				'version' => $theme->version(),
			),
			201
		);
	}

	public function list_skills( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( array( 'skills' => $this->skills->registry() ) );
	}

	public function import_skill( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$skill_id = (string) $request->get_param( 'skill_id' );
		$version  = (string) $request->get_param( 'version' );
		$content  = (string) $request->get_param( 'content' );

		if ( '' === $skill_id || '' === $version || '' === trim( $content ) ) {
			return new WP_Error( 'gw_invalid_params', 'skill_id, version e content sono obbligatori.', array( 'status' => 400 ) );
		}

		try {
			$this->skills->put( $skill_id, $version, $content );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'gw_skill_rejected', $e->getMessage(), array( 'status' => 422 ) );
		}

		return new WP_REST_Response( array( 'skill_id' => $skill_id, 'version' => $version ), 201 );
	}
}
