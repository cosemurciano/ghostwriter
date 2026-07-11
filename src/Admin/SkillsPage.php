<?php
declare(strict_types=1);

namespace Ghostwriter\Admin;

use Ghostwriter\Ai\SkillsManager;
use Ghostwriter\Core\Capabilities;

/**
 * Pagina Skills: registry locale e import (SKILL.md incollato).
 */
final class SkillsPage {

	public function __construct( private SkillsManager $skills ) {
	}

	public function render(): void {
		echo '<div class="wrap"><div id="gw-notice" class="notice"></div>';
		echo '<h1>' . esc_html__( 'Skills', 'ghostwriter' ) . '</h1>';
		echo '<p class="gw-muted">' . esc_html__( 'Le skills guidano stile e metodo per fase (outline, draft, review, translation…). I progetti le referenziano a versione bloccata.', 'ghostwriter' ) . '</p>';

		$registry = $this->skills->registry();
		if ( empty( $registry ) ) {
			echo '<p class="gw-muted">' . esc_html__( 'Nessuna skill nel registry locale.', 'ghostwriter' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
				. '<th>' . esc_html__( 'Skill', 'ghostwriter' ) . '</th>'
				. '<th>' . esc_html__( 'Versioni', 'ghostwriter' ) . '</th>'
				. '</tr></thead><tbody>';
			foreach ( $registry as $skill_id => $versions ) {
				sort( $versions );
				echo '<tr><td><strong>' . esc_html( (string) $skill_id ) . '</strong></td><td>' . esc_html( implode( ', ', $versions ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			echo '<h2 style="margin-top:2em">' . esc_html__( 'Importa/aggiorna skill', 'ghostwriter' ) . '</h2>';
			echo '<form class="gw-box" style="max-width:720px" data-gw-form="POST /skills/import" data-gw-transform="importSkill">'
				. '<p><label>' . esc_html__( 'Identificativo', 'ghostwriter' ) . ' <input type="text" name="skill_id" placeholder="stile-divulgazione" required pattern="[A-Za-z0-9._-]+" /></label> '
				. '<label>' . esc_html__( 'Versione', 'ghostwriter' ) . ' <input type="text" name="version" placeholder="1" required pattern="[A-Za-z0-9._-]+" size="6" /></label></p>'
				. '<p><label>' . esc_html__( 'Contenuto (SKILL.md)', 'ghostwriter' ) . '<br/><textarea name="content" rows="12" class="large-text code" required></textarea></label></p>'
				. '<p><button class="button button-primary">' . esc_html__( 'Salva skill', 'ghostwriter' ) . '</button></p>'
				. '</form>';
		}

		echo '</div>';
	}
}
