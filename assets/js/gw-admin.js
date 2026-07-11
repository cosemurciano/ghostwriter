/**
 * Ghostwriter admin: tutte le mutazioni passano dalla REST API con nonce.
 * Nessuna dipendenza: delega su [data-gw-action] e submit dei form [data-gw-form].
 */
( function () {
	'use strict';

	if ( 'undefined' === typeof window.gwAdmin ) {
		return;
	}
	var cfg = window.gwAdmin;

	function api( path, method, body, isMultipart ) {
		var options = {
			method: method || 'GET',
			headers: { 'X-WP-Nonce': cfg.nonce },
			credentials: 'same-origin',
		};
		if ( body && ! isMultipart ) {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( body );
		}
		if ( body && isMultipart ) {
			options.body = body; // FormData
		}
		return fetch( cfg.restRoot + path, options ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					var message = ( data && data.message ) ? data.message : response.statusText;
					throw new Error( message );
				}
				return data;
			} );
		} );
	}

	function notify( message, isError ) {
		var box = document.getElementById( 'gw-notice' );
		if ( ! box ) {
			window.alert( message );
			return;
		}
		box.textContent = message;
		box.className = 'notice ' + ( isError ? 'notice-error' : 'notice-success' );
		box.style.display = 'block';
		box.scrollIntoView( { block: 'nearest' } );
	}

	function reloadSoon() {
		window.setTimeout( function () {
			window.location.reload();
		}, 900 );
	}

	// Azioni puntuali: <button data-gw-action="POST /projects/7/outline/approve" data-gw-confirm data-gw-prompt-feedback>
	document.addEventListener( 'click', function ( event ) {
		var el = event.target.closest( '[data-gw-action]' );
		if ( ! el ) {
			return;
		}
		event.preventDefault();

		if ( el.hasAttribute( 'data-gw-confirm' ) && ! window.confirm( cfg.i18n.confirm ) ) {
			return;
		}

		var parts = el.getAttribute( 'data-gw-action' ).split( ' ' );
		var method = parts[ 0 ];
		var path = parts[ 1 ];
		var body = null;

		if ( el.hasAttribute( 'data-gw-body' ) ) {
			body = JSON.parse( el.getAttribute( 'data-gw-body' ) );
		}
		if ( el.hasAttribute( 'data-gw-prompt-feedback' ) ) {
			var feedback = window.prompt( cfg.i18n.feedback );
			if ( ! feedback ) {
				return;
			}
			body = body || {};
			body.feedback = feedback;
		}

		el.disabled = true;
		api( path, method, body )
			.then( function () {
				notify( cfg.i18n.queued, false );
				reloadSoon();
			} )
			.catch( function ( error ) {
				el.disabled = false;
				notify( cfg.i18n.error + ': ' + error.message, true );
			} );
	} );

	// Form JSON: <form data-gw-form="POST /projects" data-gw-transform="newProject">
	var transforms = {
		// Nuovo progetto: dai campi del form alla config conforme allo schema.
		newProject: function ( data ) {
			var blocks = [];
			document.querySelectorAll( 'input[name="allowed_blocks[]"]:checked' ).forEach( function ( input ) {
				blocks.push( input.value );
			} );
			var config = {
				schema_version: '1.0',
				language: data.language || 'it',
				brief: {
					thesis: data.thesis || '',
					audience: data.audience || '',
					genre: data.genre || 'divulgazione',
				},
				format: {
					trim_width_mm: parseFloat( data.trim_width_mm ),
					trim_height_mm: parseFloat( data.trim_height_mm ),
				},
				structural_profile: { allowed_blocks: blocks },
				skills: [],
				ai: {
					provider: data.provider,
					model: data.model,
				},
			};
			if ( data.target_words ) {
				config.brief.target_length = { unit: 'parole', value: parseInt( data.target_words, 10 ) };
			}
			if ( data.image_provider ) {
				config.ai.image_provider = data.image_provider;
				config.ai.image_model = data.image_model || '';
			}
			if ( data.max_cost_eur ) {
				config.ai.budget = { max_cost_eur: parseFloat( data.max_cost_eur ) };
			}
			return { title: data.title, config: config };
		},

		// Outline: righe title/brief → PUT /projects/{id}/outline.
		outline: function ( data, form ) {
			var outline = [];
			form.querySelectorAll( '.gw-outline-row' ).forEach( function ( row, index ) {
				outline.push( {
					chapter_id: parseInt( row.getAttribute( 'data-chapter-id' ), 10 ),
					order: index,
					title: row.querySelector( '[name="title"]' ).value,
					brief: row.querySelector( '[name="brief"]' ).value,
					status: row.getAttribute( 'data-status' ) || 'planned',
				} );
			} );
			return { outline: outline };
		},

		// Glossario: righe source/target/note → PUT /projects/{id}/glossary.
		glossary: function ( data, form ) {
			var glossary = [];
			form.querySelectorAll( '.gw-glossary-row' ).forEach( function ( row ) {
				var source = row.querySelector( '[name="source_term"]' ).value.trim();
				var target = row.querySelector( '[name="target_term"]' ).value.trim();
				if ( '' === source || '' === target ) {
					return;
				}
				var entry = { source_term: source, target_term: target };
				var note = row.querySelector( '[name="note"]' ).value.trim();
				if ( note ) {
					entry.note = note;
				}
				glossary.push( entry );
			} );
			return { glossary: glossary };
		},

		derive: function ( data ) {
			return { language: data.language };
		},

		// Nuova fonte: source_id derivato dal titolo, URL o path in base al tipo.
		addSource: function ( data ) {
			var source = {
				source_id: 'src-' + data.title.toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' ).slice( 0, 40 ),
				type: 'open_data' === data.type ? 'open_data' : data.type,
				title: data.title,
				license: data.license,
				attribution_required: !! data.attribution_required,
			};
			if ( 'pdf' === data.type ) {
				source.file_path = data.location;
			} else {
				source.url = data.location;
			}
			return { source: source };
		},

		exportBook: function ( data ) {
			var pair = data.theme.split( '@' );
			return { theme_id: pair[ 0 ], theme_version: pair[ 1 ] || null, target: data.target };
		},

		importSkill: function ( data ) {
			return { skill_id: data.skill_id, version: data.version, content: data.content };
		},
	};

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target.closest( 'form[data-gw-form]' );
		if ( ! form ) {
			return;
		}
		event.preventDefault();

		var parts = form.getAttribute( 'data-gw-form' ).split( ' ' );
		var method = parts[ 0 ];
		var path = parts[ 1 ];

		// Upload multipart (import tema).
		if ( form.hasAttribute( 'data-gw-multipart' ) ) {
			api( path, method, new FormData( form ), true )
				.then( function () {
					notify( cfg.i18n.queued, false );
					reloadSoon();
				} )
				.catch( function ( error ) {
					notify( cfg.i18n.error + ': ' + error.message, true );
				} );
			return;
		}

		var data = {};
		new FormData( form ).forEach( function ( value, key ) {
			data[ key ] = value;
		} );

		var transform = form.getAttribute( 'data-gw-transform' );
		var body = transform && transforms[ transform ] ? transforms[ transform ]( data, form ) : data;

		api( path, method, body )
			.then( function ( response ) {
				notify( cfg.i18n.queued, false );
				// Nuovo progetto: si va dritti al dettaglio.
				if ( response && response.id && form.hasAttribute( 'data-gw-goto-project' ) ) {
					window.location.href = window.location.pathname + '?page=ghostwriter&project=' + response.id;
					return;
				}
				reloadSoon();
			} )
			.catch( function ( error ) {
				notify( cfg.i18n.error + ': ' + error.message, true );
			} );
	} );

	// Preflight on-demand: report sotto il form export.
	document.addEventListener( 'click', function ( event ) {
		var el = event.target.closest( '[data-gw-preflight]' );
		if ( ! el ) {
			return;
		}
		event.preventDefault();
		var form = el.closest( 'form' );
		var theme = form.querySelector( '[name="theme"]' ).value;
		var target = form.querySelector( '[name="target"]' ).value;
		var box = form.parentElement.querySelector( '.gw-preflight-report' );

		el.disabled = true;
		api( '/projects/' + el.getAttribute( 'data-gw-preflight' ) + '/preflight?theme=' + encodeURIComponent( theme ) + '&target=' + target )
			.then( function ( report ) {
				el.disabled = false;
				var html = '';
				( report.errors || [] ).forEach( function ( message ) {
					html += '<p style="color:#d63638">✗ ' + escapeHtml( message ) + '</p>';
				} );
				( report.warnings || [] ).forEach( function ( message ) {
					html += '<p style="color:#996800">⚠ ' + escapeHtml( message ) + '</p>';
				} );
				if ( '' === html ) {
					html = '<p style="color:#00a32a">✓ Preflight superato: pronto per l\'export.</p>';
				}
				box.innerHTML = html;
				box.style.display = 'block';
			} )
			.catch( function ( error ) {
				el.disabled = false;
				notify( cfg.i18n.error + ': ' + error.message, true );
			} );
	} );

	// Blocchi di un capitolo: elenco espandibile con riscrittura e versioni.
	function escapeHtml( text ) {
		var div = document.createElement( 'div' );
		div.textContent = String( text );
		return div.innerHTML;
	}

	function blockExcerpt( block ) {
		var props = block.props || {};
		var text = props.text || props.title || props.caption || props.code || ( props.items ? props.items.join( ' · ' ) : '' ) || props.image_brief || '';
		text = String( text );
		return text.length > 140 ? text.slice( 0, 140 ) + '…' : text;
	}

	function renderBlocks( container, chapterId, content ) {
		if ( ! content || ! content.blocks || ! content.blocks.length ) {
			container.textContent = 'Nessun contenuto ancora generato.';
			return;
		}
		var html = '<table class="widefat striped"><tbody>';
		content.blocks.forEach( function ( block ) {
			html += '<tr>'
				+ '<td style="width:110px"><code>' + escapeHtml( block.type ) + '</code><br/><span class="gw-muted">v' + ( block.version || 1 ) + '</span></td>'
				+ '<td>' + escapeHtml( blockExcerpt( block ) ) + '</td>'
				+ '<td style="width:200px">'
				+ '<button class="button button-small" data-gw-action="POST /chapters/' + chapterId + '/blocks/' + encodeURIComponent( block.id ) + '/rewrite" data-gw-prompt-feedback>Riscrivi</button> '
				+ '<button class="button button-small" data-gw-block-versions="' + chapterId + '|' + escapeHtml( block.id ) + '">Versioni</button>'
				+ '</td></tr>'
				+ '<tr class="gw-versions-row" data-block="' + escapeHtml( block.id ) + '" style="display:none"><td colspan="3"><div class="gw-versions-target gw-muted">…</div></td></tr>';
		} );
		container.innerHTML = html + '</tbody></table>';
	}

	document.addEventListener( 'click', function ( event ) {
		var toggle = event.target.closest( '[data-gw-chapter-blocks]' );
		if ( toggle ) {
			event.preventDefault();
			var chapterId = toggle.getAttribute( 'data-gw-chapter-blocks' );
			var row = document.querySelector( '.gw-blocks-row[data-chapter="' + chapterId + '"]' );
			if ( 'none' !== row.style.display ) {
				row.style.display = 'none';
				return;
			}
			row.style.display = '';
			api( '/chapters/' + chapterId ).then( function ( chapter ) {
				renderBlocks( row.querySelector( '.gw-blocks-target' ), chapterId, chapter.content );
			} ).catch( function ( error ) {
				row.querySelector( '.gw-blocks-target' ).textContent = error.message;
			} );
			return;
		}

		var versions = event.target.closest( '[data-gw-block-versions]' );
		if ( versions ) {
			event.preventDefault();
			var pair = versions.getAttribute( 'data-gw-block-versions' ).split( '|' );
			var versionsRow = versions.closest( 'tr' ).nextElementSibling;
			if ( 'none' !== versionsRow.style.display ) {
				versionsRow.style.display = 'none';
				return;
			}
			versionsRow.style.display = '';
			var target = versionsRow.querySelector( '.gw-versions-target' );
			api( '/chapters/' + pair[ 0 ] + '/blocks/' + encodeURIComponent( pair[ 1 ] ) + '/versions' ).then( function ( data ) {
				if ( ! data.versions || ! data.versions.length ) {
					target.textContent = 'Nessuna versione precedente: il blocco non è mai stato riscritto.';
					return;
				}
				var html = '<table class="widefat"><tbody>';
				data.versions.forEach( function ( revision ) {
					html += '<tr>'
						+ '<td style="width:70px">v' + revision.version + '<br/><span class="gw-muted">' + escapeHtml( revision.origin || '' ) + '</span></td>'
						+ '<td>' + escapeHtml( blockExcerpt( revision.block || {} ) )
						+ ( revision.feedback ? '<br/><em class="gw-muted">Feedback: ' + escapeHtml( revision.feedback ) + '</em>' : '' ) + '</td>'
						+ '<td style="width:110px"><button class="button button-small" data-gw-action="POST /chapters/' + pair[ 0 ] + '/blocks/' + encodeURIComponent( pair[ 1 ] ) + '/restore" data-gw-body=\'{"version":' + revision.version + '}\' data-gw-confirm>Ripristina</button></td>'
						+ '</tr>';
				} );
				target.innerHTML = html + '</tbody></table>';
			} ).catch( function ( error ) {
				target.textContent = error.message;
			} );
		}
	} );

	// Righe dinamiche del glossario.
	document.addEventListener( 'click', function ( event ) {
		var add = event.target.closest( '[data-gw-add-glossary-row]' );
		if ( ! add ) {
			return;
		}
		event.preventDefault();
		var table = document.querySelector( '.gw-glossary-rows' );
		var row = table.querySelector( '.gw-glossary-row' ).cloneNode( true );
		row.querySelectorAll( 'input' ).forEach( function ( input ) {
			input.value = '';
		} );
		table.appendChild( row );
	} );
}() );
