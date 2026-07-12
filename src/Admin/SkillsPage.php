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
				. '<th style="width:220px">' . esc_html__( 'Skill', 'ghostwriter' ) . '</th>'
				. '<th>' . esc_html__( 'Descrizione', 'ghostwriter' ) . '</th>'
				. '<th style="width:170px">' . esc_html__( 'Fasi predefinite', 'ghostwriter' ) . '</th>'
				. '<th style="width:90px"></th>'
				. '</tr></thead><tbody>';
			foreach ( $registry as $skill_id => $versions ) {
				sort( $versions );
				foreach ( $versions as $version ) {
					$meta = $this->skills->describe( (string) $skill_id, (string) $version );
					echo '<tr><td><strong>' . esc_html( (string) $skill_id ) . '</strong> <span class="gw-muted">v' . esc_html( (string) $version ) . '</span></td>'
						. '<td class="gw-muted">' . esc_html( mb_substr( $meta['description'], 0, 220 ) ) . '</td>'
						. '<td>' . esc_html( implode( ', ', $meta['default_phases'] ) ) . '</td>'
						. '<td>' . ( current_user_can( Capabilities::MANAGE_SETTINGS )
							? '<button class="button button-small" data-gw-action="DELETE /skills/' . esc_attr( (string) $skill_id ) . '/' . esc_attr( (string) $version ) . '" data-gw-confirm>' . esc_html__( 'Elimina', 'ghostwriter' ) . '</button>'
							: '' )
						. '</td></tr>';
				}
			}
			echo '</tbody></table>';
		}

		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			echo '<h2 style="margin-top:2em">' . esc_html__( 'Importa skill (zip)', 'ghostwriter' ) . '</h2>';
			echo '<form class="gw-box" style="max-width:560px;margin-bottom:16px" data-gw-form="POST /skills/import" data-gw-multipart>'
				. '<p>' . esc_html__( 'Pacchetto: cartella con SKILL.md (frontmatter: name, description, metadata.version, x-ghostwriter.default_phases) e asset opzionali.', 'ghostwriter' ) . '</p>'
				. '<p><input type="file" name="bundle" accept=".zip" required /> <button class="button button-primary">' . esc_html__( 'Importa', 'ghostwriter' ) . '</button></p>'
				. '</form>';
			echo '<h2>' . esc_html__( 'Oppure incolla il contenuto', 'ghostwriter' ) . '</h2>';
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
