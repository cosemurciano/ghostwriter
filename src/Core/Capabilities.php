<?php
declare(strict_types=1);

namespace Ghostwriter\Core;

/**
 * Capability dedicate del plugin e loro assegnazione ai ruoli.
 */
final class Capabilities {

	public const MANAGE_PROJECTS = 'gw_manage_projects';
	public const APPROVE_CONTENT = 'gw_approve_content';
	public const MANAGE_SETTINGS = 'gw_manage_settings';
	public const EXPORT          = 'gw_export';

	public const EDITOR_ROLE = 'gw_editor';

	/** @return string[] */
	public static function all(): array {
		return array(
			self::MANAGE_PROJECTS,
			self::APPROVE_CONTENT,
			self::MANAGE_SETTINGS,
			self::EXPORT,
		);
	}

	/**
	 * Capability primitive dei CPT (map_meta_cap le deriva da queste).
	 *
	 * @return string[]
	 */
	public static function post_type_caps(): array {
		$caps = array();
		foreach ( array( 'gw_project', 'gw_chapter' ) as $singular ) {
			$plural = $singular . 's';
			$caps[] = "edit_{$singular}";
			$caps[] = "read_{$singular}";
			$caps[] = "delete_{$singular}";
			$caps[] = "edit_{$plural}";
			$caps[] = "edit_others_{$plural}";
			$caps[] = "publish_{$plural}";
			$caps[] = "read_private_{$plural}";
			$caps[] = "delete_{$plural}";
			$caps[] = "delete_private_{$plural}";
			$caps[] = "delete_published_{$plural}";
			$caps[] = "delete_others_{$plural}";
			$caps[] = "edit_private_{$plural}";
			$caps[] = "edit_published_{$plural}";
		}
		return $caps;
	}

	/**
	 * Assegna le capability: tutte ad Administrator, quelle operative
	 * al ruolo dedicato Editor-Ghostwriter (senza gestione impostazioni).
	 */
	public static function grant(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_merge( self::all(), self::post_type_caps() ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		if ( ! get_role( self::EDITOR_ROLE ) ) {
			add_role( self::EDITOR_ROLE, __( 'Editor Ghostwriter', 'ghostwriter' ), array( 'read' => true ) );
		}

		$editor = get_role( self::EDITOR_ROLE );
		if ( $editor ) {
			$editor_caps = array_merge(
				array( self::MANAGE_PROJECTS, self::APPROVE_CONTENT, self::EXPORT ),
				self::post_type_caps()
			);
			foreach ( $editor_caps as $cap ) {
				$editor->add_cap( $cap );
			}
		}
	}

	/**
	 * Rimuove le capability dai ruoli (usato in deactivation/uninstall).
	 */
	public static function revoke(): void {
		foreach ( array( 'administrator', self::EDITOR_ROLE ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( array_merge( self::all(), self::post_type_caps() ) as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
