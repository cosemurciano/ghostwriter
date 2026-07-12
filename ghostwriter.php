<?php
/**
 * Plugin Name:       Ghostwriter
 * Plugin URI:        https://github.com/cosemurciano/ghostwriter
 * Description:       Produzione di libri completi (PDF + ePub) in WordPress con AI in background: outline, capitoli, immagini, copertine, traduzioni.
 * Version:           0.5.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Cosè Murciano
 * License:           GPL-2.0-or-later
 * Text Domain:       ghostwriter
 * Domain Path:       /languages
 *
 * @package Ghostwriter
 */

defined( 'ABSPATH' ) || exit;

define( 'GHOSTWRITER_VERSION', '0.5.0' );
define( 'GHOSTWRITER_PLUGIN_FILE', __FILE__ );
define( 'GHOSTWRITER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GHOSTWRITER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Action Scheduler va caricato QUI, nel file principale del plugin: il suo
// bootstrap si aggancia a plugins_loaded (priorità 0/1), quindi caricarlo
// dentro un callback di plugins_loaded lo lascerebbe inerte e le funzioni
// as_*() non esisterebbero (fatal al primo accodamento di un job).
$gw_action_scheduler = GHOSTWRITER_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( file_exists( $gw_action_scheduler ) ) {
	require_once $gw_action_scheduler;
}
unset( $gw_action_scheduler );

/**
 * Verifica i requisiti minimi. Se mancano, il plugin non si avvia
 * e mostra una notice in admin invece di un fatal error.
 *
 * @return string[] Elenco dei problemi riscontrati (vuoto = ok).
 */
function ghostwriter_requirements_errors(): array {
	$errors = array();

	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		$errors[] = sprintf( 'Ghostwriter richiede PHP 8.1+ (versione corrente: %s).', PHP_VERSION );
	}

	global $wp_version;
	if ( isset( $wp_version ) && version_compare( $wp_version, '6.4', '<' ) ) {
		$errors[] = sprintf( 'Ghostwriter richiede WordPress 6.4+ (versione corrente: %s).', $wp_version );
	}

	if ( ! file_exists( GHOSTWRITER_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
		$errors[] = 'Dipendenze Composer mancanti: eseguire "composer install" nella cartella del plugin.';
	}

	return $errors;
}

/**
 * Bootstrap del plugin.
 */
function ghostwriter_boot(): void {
	$errors = ghostwriter_requirements_errors();

	if ( ! empty( $errors ) ) {
		add_action(
			'admin_notices',
			static function () use ( $errors ): void {
				foreach ( $errors as $error ) {
					printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
				}
			}
		);
		return;
	}

	require_once GHOSTWRITER_PLUGIN_DIR . 'vendor/autoload.php';

	\Ghostwriter\Core\Plugin::instance()->register();
}
add_action( 'plugins_loaded', 'ghostwriter_boot', 5 );

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( ! empty( ghostwriter_requirements_errors() ) ) {
			return;
		}
		require_once GHOSTWRITER_PLUGIN_DIR . 'vendor/autoload.php';
		\Ghostwriter\Core\Activator::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( \Ghostwriter\Core\Deactivator::class ) ) {
			\Ghostwriter\Core\Deactivator::deactivate();
		}
	}
);
