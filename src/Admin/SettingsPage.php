<?php
declare(strict_types=1);

namespace Ghostwriter\Admin;

use Ghostwriter\Ai\ApiKeys;

/**
 * Pagina Impostazioni: stato delle chiavi API (che vivono SOLO in
 * wp-config.php) e diagnostica della coda.
 */
final class SettingsPage {

	public function render(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Impostazioni Ghostwriter', 'ghostwriter' ) . '</h1>';

		echo '<div class="gw-box" style="max-width:760px"><h2>' . esc_html__( 'Chiavi API', 'ghostwriter' ) . '</h2>';
		echo '<p>' . esc_html__( 'Le chiavi si definiscono in wp-config.php e non vengono mai salvate nel database né nei log:', 'ghostwriter' ) . '</p>';
		echo '<pre class="code">define( \'GHOSTWRITER_ANTHROPIC_API_KEY\', \'sk-ant-...\' );' . "\n" . 'define( \'GHOSTWRITER_OPENAI_API_KEY\',    \'sk-...\' );</pre>';

		echo '<table class="widefat striped" style="max-width:560px"><tbody>';
		foreach ( array(
			'anthropic' => 'Anthropic (Claude)',
			'openai'    => 'OpenAI',
		) as $provider => $label ) {
			$key = ApiKeys::for_provider( $provider );
			echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td><td>';
			if ( null !== $key ) {
				echo '<span class="gw-key-ok">✓ ' . esc_html__( 'configurata', 'ghostwriter' ) . '</span> <code>' . esc_html( ApiKeys::mask( $key ) ) . '</code>';
			} else {
				echo '<span class="gw-key-missing">✗ ' . esc_html__( 'assente', 'ghostwriter' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="gw-muted">' . esc_html__( 'Senza chiave si può comunque provare tutta la pipeline scegliendo il provider "mock" alla creazione del progetto.', 'ghostwriter' ) . '</p>';
		echo '</div>';

		echo '<div class="gw-box" style="max-width:760px;margin-top:16px"><h2>' . esc_html__( 'Coda (Action Scheduler)', 'ghostwriter' ) . '</h2>';
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			$tools_url = admin_url( 'tools.php?page=action-scheduler&status=pending&s=gw_job' );
			echo '<p>' . esc_html__( 'La pipeline gira su Action Scheduler: i job in coda sono visibili in', 'ghostwriter' )
				. ' <a href="' . esc_url( $tools_url ) . '">' . esc_html__( 'Strumenti → Scheduled Actions', 'ghostwriter' ) . '</a>.</p>';
			echo '<p class="gw-muted">' . esc_html__( 'Se i job restano "pending", il cron del sito non gira: verificare wp-cron o configurare un cron di sistema.', 'ghostwriter' ) . '</p>';
		} else {
			echo '<p class="gw-key-missing">' . esc_html__( 'Action Scheduler non risulta caricato: eseguire composer install nella cartella del plugin.', 'ghostwriter' ) . '</p>';
		}
		echo '</div>';

		echo '</div>';
	}
}
