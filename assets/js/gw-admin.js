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
			// Il body si legge come testo: una risposta vuota o non-JSON
			// (errore PHP fatale, WAF, pagina HTML) deve produrre un messaggio
			// comprensibile, non "JSON.parse: unexpected end of data".
			return response.text().then( function ( text ) {
				var data = null;
				if ( text ) {
					try {
						data = JSON.parse( text );
					} catch ( parseError ) {
						data = null;
					}
				}
				if ( ! response.ok ) {
					var message = ( data && data.message )
						? data.message
						: 'HTTP ' + response.status + ' — ' + ( text ? text.replace( /<[^>]*>/g, ' ' ).trim().slice( 0, 300 ) : cfg.i18n.emptyResponse );
					throw new Error( message );
				}
				if ( null === data && text ) {
					throw new Error( cfg.i18n.badResponse + ' ' + text.slice( 0, 200 ) );
				}
				if ( null === data ) {
					throw new Error( 'HTTP ' + response.status + ' — ' + cfg.i18n.emptyResponse );
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

		if ( el.hasAttribute( 'data-gw-from-input' ) ) {
			var input = el.closest( 'form' ).querySelector( '[name="' + el.getAttribute( 'data-gw-from-input' ) + '"]' );
			body = body || {};
			body[ el.getAttribute( 'data-gw-from-input' ) ] = input ? input.value : '';
		}

		// data-gw-collect='{"chiave":"#selettore"}': valori da campi della
		// pagina (textarea → stringa, checkbox → booleano). Usato dove un
		// form annidato non è possibile (es. meta box dell'editor).
		if ( el.hasAttribute( 'data-gw-collect' ) ) {
			body = body || {};
			var map = JSON.parse( el.getAttribute( 'data-gw-collect' ) );
			Object.keys( map ).forEach( function ( key ) {
				var field = document.querySelector( map[ key ] );
				if ( field ) {
					body[ key ] = 'checkbox' === field.type ? field.checked : field.value;
				}
			} );
			if ( 'feedback' in body && ! String( body.feedback ).trim() ) {
				notify( cfg.i18n.feedback, true );
				return;
			}
		}

		el.disabled = true;
		api( path, method, body )
			.then( function ( data ) {
				el.disabled = false;
				var watch = el.closest( '[data-gw-revise-watch]' );
				if ( watch ) {
					notify( cfg.i18n.queued, false );
					startReviseWatch( watch, el );
					return;
				}
				if ( el.hasAttribute( 'data-gw-noreload' ) ) {
					notify( ( data && data.message ) ? data.message : 'OK', false );
					return;
				}
				notify( cfg.i18n.queued, false );
				reloadSoon();
			} )
			.catch( function ( error ) {
				el.disabled = false;
				notify( cfg.i18n.error + ': ' + error.message, true );
			} );
	} );

	// Assistente AI del capitolo: dopo l'accodamento si osserva il contenuto
	// e si ricarica la pagina quando la riscrittura è arrivata.
	function startReviseWatch( watch, button ) {
		var chapterId = watch.getAttribute( 'data-gw-revise-watch' );
		var status = watch.querySelector( '.gw-assistant-status' );
		if ( status ) {
			status.style.display = '';
		}
		button.disabled = true;

		var snapshot = null;
		function poll() {
			api( '/chapters/' + chapterId ).then( function ( chapter ) {
				var current = JSON.stringify( chapter.content || null );
				if ( null === snapshot ) {
					snapshot = current;
				} else if ( current !== snapshot ) {
					window.location.reload();
					return;
				}
				window.setTimeout( poll, 8000 );
			} ).catch( function () {
				window.setTimeout( poll, 20000 );
			} );
		}
		poll();
	}

	// Tab del progetto: sempre cliccabili, stato ricordato nell'hash.
	document.addEventListener( 'click', function ( event ) {
		var tab = event.target.closest( '[data-gw-tab]' );
		if ( ! tab ) {
			return;
		}
		event.preventDefault();
		var key = tab.getAttribute( 'data-gw-tab' );
		document.querySelectorAll( '[data-gw-tab]' ).forEach( function ( t ) {
			t.classList.toggle( 'nav-tab-active', t === tab );
		} );
		document.querySelectorAll( '[data-gw-panel]' ).forEach( function ( p ) {
			p.style.display = p.getAttribute( 'data-gw-panel' ) === key ? '' : 'none';
		} );
		window.history.replaceState( null, '', '#' + key );
	} );
	if ( window.location.hash ) {
		var initial = document.querySelector( '[data-gw-tab="' + window.location.hash.slice( 1 ) + '"]' );
		if ( initial ) {
			initial.click();
		}
	}

	// Tipo fonte: mostra URL o selettore media.
	document.addEventListener( 'change', function ( event ) {
		var select = event.target.closest( '[data-gw-source-type]' );
		if ( ! select ) {
			return;
		}
		var form = select.closest( 'form' );
		var isMedia = 'media' === select.value;
		form.querySelector( '[data-gw-location-url]' ).style.display = isMedia ? 'none' : '';
		form.querySelector( '[data-gw-location-media]' ).style.display = isMedia ? '' : 'none';
	} );

	// Selettore Media Library.
	document.addEventListener( 'click', function ( event ) {
		var pick = event.target.closest( '[data-gw-pick-media]' );
		if ( ! pick || 'undefined' === typeof window.wp || ! window.wp.media ) {
			return;
		}
		event.preventDefault();
		var frame = window.wp.media( { multiple: false } );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			var form = pick.closest( 'form' );
			form.querySelector( '[name="attachment_id"]' ).value = att.id;
			form.querySelector( '.gw-media-chosen' ).textContent = att.filename || att.title;
			var title = form.querySelector( '[name="title"]' );
			if ( title && '' === title.value ) {
				title.value = att.title || att.filename;
			}
		} );
		frame.open();
	} );

	// Test fonte non ancora registrata: payload dal form.
	document.addEventListener( 'click', function ( event ) {
		var test = event.target.closest( '[data-gw-test-source]' );
		if ( ! test ) {
			return;
		}
		event.preventDefault();
		var form = test.closest( 'form' );
		var data = {};
		new FormData( form ).forEach( function ( value, key ) {
			data[ key ] = value;
		} );
		var body = transforms.addSource( data );
		test.disabled = true;
		api( '/projects/' + test.getAttribute( 'data-gw-test-source' ) + '/sources/test', 'POST', body )
			.then( function ( result ) {
				test.disabled = false;
				notify( result.message, false );
			} )
			.catch( function ( error ) {
				test.disabled = false;
				notify( error.message, true );
			} );
	} );

	// Spunta "tutti gli articoli del sito".
	document.addEventListener( 'change', function ( event ) {
		var box = event.target.closest( '[data-gw-site-posts]' );
		if ( ! box || ! box.checked ) {
			return;
		}
		api( '/projects/' + box.getAttribute( 'data-gw-site-posts' ) + '/sources', 'POST', {
			source: {
				source_id: 'src-articoli-sito',
				type: 'article',
				title: 'Articoli del sito',
				license: 'proprietaria',
				site_posts: true,
			},
		} ).then( function () {
			notify( cfg.i18n.queued, false );
			reloadSoon();
		} ).catch( function ( error ) {
			box.checked = false;
			notify( cfg.i18n.error + ': ' + error.message, true );
		} );
	} );

	// Modello effettivo dal picker: il valore del select attivo, oppure il
	// campo libero quando è selezionato "Personalizzato…".
	function pickedModel( data, key ) {
		if ( '__custom__' === data[ key ] ) {
			return ( data[ key + '_custom' ] || '' ).trim();
		}
		return data[ key ] || '';
	}

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
					model: pickedModel( data, 'model' ),
				},
			};
			if ( data.target_words ) {
				config.brief.target_length = { unit: 'parole', value: parseInt( data.target_words, 10 ) };
			}
			if ( data.image_provider ) {
				config.ai.image_provider = data.image_provider;
				config.ai.image_model = pickedModel( data, 'image_model' );
			}
			// Libro manuale: nessuna chiamata AI. Provider mock solo come
			// segnaposto per lo schema; il flag manual governa la UI.
			if ( 'manual' === data.writing_mode ) {
				config.ai = { provider: 'mock', model: 'manuale', manual: true };
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

		// Rigenerazione indice: keep = indici (0-based) dei capitoli spuntati
		// "Mantieni", che l'AI non deve toccare; feedback guida il resto.
		regenerateOutline: function ( data, form ) {
			var keep = [];
			form.querySelectorAll( '.gw-outline-row' ).forEach( function ( row, index ) {
				var lock = row.querySelector( 'input[name="keep"]' );
				if ( lock && lock.checked ) {
					keep.push( index );
				}
			} );
			return { keep: keep, feedback: data.regen_feedback || '' };
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

		// Impostazioni progetto: aggiornamento parziale della config.
		projectSettings: function ( data, form ) {
			var body = {
				title: data.title,
				brief: {
					thesis: data.thesis || '',
					audience: data.audience || '',
					genre: data.genre,
				},
				ai: {
					provider: data.provider,
					model: pickedModel( data, 'model' ),
					image_provider: data.image_provider || '',
					image_model: pickedModel( data, 'image_model' ),
					auto_advance: !! data.auto_advance,
				},
			};
			if ( data.target_words ) {
				body.brief.target_length = { unit: 'parole', value: parseInt( data.target_words, 10 ) };
			}
			// Formato e blocchi: solo se i campi non sono bloccati (disabled non finisce nel FormData).
			if ( data.trim_width_mm ) {
				body.format = {
					trim_width_mm: parseFloat( data.trim_width_mm ),
					trim_height_mm: parseFloat( data.trim_height_mm ),
					print_ready: !! data.print_ready,
				};
				body.allowed_blocks = [];
				form.querySelectorAll( 'input[name="allowed_blocks[]"]:checked' ).forEach( function ( input ) {
					body.allowed_blocks.push( input.value );
				} );
			}
			return body;
		},

		// Tab Skills del progetto: righe spuntate → { skills: [{skill_id, version, phases}] }.
		projectSkills: function ( data, form ) {
			var skills = [];
			form.querySelectorAll( '.gw-skill-row' ).forEach( function ( row ) {
				var on = row.querySelector( 'input[name="skill_on"]' );
				if ( ! on || ! on.checked ) {
					return;
				}
				var phases = [];
				row.querySelectorAll( 'input[name="phase"]:checked' ).forEach( function ( input ) {
					phases.push( input.value );
				} );
				skills.push( {
					skill_id: row.getAttribute( 'data-skill' ),
					version: row.getAttribute( 'data-version' ),
					phases: phases,
				} );
			} );
			return { skills: skills };
		},

		derive: function ( data ) {
			return { language: data.language };
		},

		// Capitolo manuale: nasce vuoto, dopo il capitolo scelto o in fondo.
		addChapter: function ( data ) {
			return {
				title: data.title,
				brief: data.brief || '',
				after: parseInt( data.after, 10 ) || 0,
			};
		},

		// Nuova fonte: source_id dal titolo; URL, media WP o path in base al tipo.
		addSource: function ( data ) {
			var source = {
				source_id: 'src-' + data.title.toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' ).slice( 0, 40 ),
				type: 'media' === data.type ? 'pdf' : data.type,
				title: data.title,
				license: data.license,
				attribution_required: !! data.attribution_required,
			};
			if ( 'media' === data.type && data.attachment_id ) {
				source.attachment_id = parseInt( data.attachment_id, 10 );
			} else if ( 'pdf' === data.type ) {
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

		// Un secondo submit nello stesso form può puntare a un altro endpoint:
		// <button data-gw-alt-form="POST /..." data-gw-alt-transform="...">.
		var submitter = event.submitter;
		var altForm = submitter && submitter.getAttribute( 'data-gw-alt-form' );
		if ( altForm && submitter.hasAttribute( 'data-gw-confirm' ) && ! window.confirm( cfg.i18n.confirm ) ) {
			return;
		}

		var parts = ( altForm || form.getAttribute( 'data-gw-form' ) ).split( ' ' );
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

		var transform = ( altForm && submitter.getAttribute( 'data-gw-alt-transform' ) ) || form.getAttribute( 'data-gw-transform' );
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
			var unresolvedFigure = 'figura' === block.type && ! ( block.props && block.props.attachment_id );
			html += '<tr>'
				+ '<td style="width:110px"><code>' + escapeHtml( block.type ) + '</code><br/><span class="gw-muted">v' + ( block.version || 1 ) + '</span></td>'
				+ '<td>' + escapeHtml( blockExcerpt( block ) ) + '</td>'
				+ '<td style="width:260px">'
				+ ( unresolvedFigure ? '<button class="button button-small button-primary" data-gw-action="POST /chapters/' + chapterId + '/blocks/' + encodeURIComponent( block.id ) + '/image" data-gw-confirm>Genera immagine</button> ' : '' )
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

	// Modalità di scrittura (creazione progetto): manuale → niente box AI.
	document.addEventListener( 'change', function ( event ) {
		var radio = event.target.closest( '.gw-writing-mode' );
		if ( ! radio ) {
			return;
		}
		document.querySelectorAll( '.gw-ai-only' ).forEach( function ( box ) {
			box.style.display = 'manual' === radio.value ? 'none' : '';
		} );
	} );

	// Modelli AI: al cambio provider si mostra il select dei suoi modelli
	// (gli altri restano disabled, fuori dal FormData); "Personalizzato…"
	// apre il campo libero per un ID modello non ancora in catalogo.
	function syncModelPicker( picker, provider ) {
		var active = null;
		picker.querySelectorAll( 'select[data-gw-models-for]' ).forEach( function ( select ) {
			var match = select.getAttribute( 'data-gw-models-for' ) === provider;
			select.disabled = ! match;
			select.hidden = ! match;
			if ( match ) {
				active = select;
			}
		} );
		var custom = picker.querySelector( '.gw-model-custom' );
		if ( custom ) {
			custom.hidden = ! active || '__custom__' !== active.value;
		}
	}

	document.addEventListener( 'change', function ( event ) {
		var target = event.target;
		var picker = target.closest( '.gw-model-picker' );
		if ( picker ) {
			syncModelPicker( picker, ( function () {
				var scope = picker.closest( 'form' ) || document;
				var field = scope.querySelector( '[name="' + picker.getAttribute( 'data-gw-provider-field' ) + '"]' );
				return field ? field.value : '';
			}() ) );
			return;
		}
		if ( 'provider' !== target.name && 'image_provider' !== target.name ) {
			return;
		}
		var scope = target.closest( 'form' ) || document;
		scope.querySelectorAll( '.gw-model-picker[data-gw-provider-field="' + target.name + '"]' ).forEach( function ( box ) {
			syncModelPicker( box, target.value );
		} );
	} );

	// Formato libro: i preset compilano i campi mm, "Personalizzato" li mostra.
	document.addEventListener( 'change', function ( event ) {
		var select = event.target.closest( '.gw-format-preset' );
		if ( ! select ) {
			return;
		}
		var custom = 'custom' === select.value;
		var wrap = select.parentNode.querySelector( '.gw-format-custom' );
		if ( wrap ) {
			wrap.style.display = custom ? '' : 'none';
		}
		if ( ! custom ) {
			var dims = select.value.split( 'x' );
			var scope = select.closest( 'form' ) || document;
			var width = scope.querySelector( '[name="trim_width_mm"]' );
			var height = scope.querySelector( '[name="trim_height_mm"]' );
			if ( width && height ) {
				width.value = dims[ 0 ];
				height.value = dims[ 1 ];
			}
		}
	} );

	// "Immagine AI" nell'editor del capitolo: modale prompt+dimensione,
	// generazione in coda, polling e inserimento della figura nel testo.
	( function () {
		var modal = document.getElementById( 'gw-ai-image-modal' );
		if ( ! modal ) {
			return;
		}
		var chapterId = modal.getAttribute( 'data-gw-image-chapter' );
		var generate = document.getElementById( 'gw-ai-image-generate' );
		var status = modal.querySelector( '.gw-ai-image-status' );

		function toggle( show ) {
			modal.style.display = show ? '' : 'none';
		}

		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '#gw-ai-image-open' ) ) {
				event.preventDefault();
				toggle( true );
			}
			if ( event.target.closest( '#gw-ai-image-cancel' ) || event.target.classList.contains( 'gw-modal-backdrop' ) ) {
				toggle( false );
			}
		} );

		function insertFigure( data, size ) {
			var html = '<figure data-gw-type="figura"><img class="wp-image-' + data.attachment_id + '" data-gw-size="' + size + '" src="' + data.url + '" alt=""/>'
				+ '<figcaption></figcaption></figure><p>&nbsp;</p>';
			window.wpActiveEditor = 'content';
			if ( window.send_to_editor ) {
				window.send_to_editor( html );
			} else {
				var textarea = document.getElementById( 'content' );
				if ( textarea ) {
					textarea.value += '\n\n' + html;
				}
			}
		}

		function poll( requestId, size ) {
			api( '/chapters/' + chapterId + '/image/' + requestId ).then( function ( data ) {
				if ( 'ready' === data.status ) {
					insertFigure( data, size );
					generate.disabled = false;
					status.style.display = 'none';
					toggle( false );
					notify( 'Immagine inserita nel capitolo.', false );
					return;
				}
				if ( 'error' === data.status ) {
					generate.disabled = false;
					status.style.display = 'none';
					notify( cfg.i18n.error + ': ' + data.message, true );
					return;
				}
				window.setTimeout( function () {
					poll( requestId, size );
				}, 5000 );
			} ).catch( function () {
				window.setTimeout( function () {
					poll( requestId, size );
				}, 15000 );
			} );
		}

		if ( generate ) {
			generate.addEventListener( 'click', function () {
				var prompt = document.getElementById( 'gw-ai-image-prompt' ).value.trim();
				var size = document.getElementById( 'gw-ai-image-size' ).value;
				if ( ! prompt ) {
					notify( cfg.i18n.error + ': descrivi l\'immagine da generare.', true );
					return;
				}
				generate.disabled = true;
				status.style.display = '';
				api( '/chapters/' + chapterId + '/image', 'POST', { prompt: prompt, size: size } )
					.then( function ( data ) {
						poll( data.request_id, size );
					} )
					.catch( function ( error ) {
						generate.disabled = false;
						status.style.display = 'none';
						notify( cfg.i18n.error + ': ' + error.message, true );
					} );
			} );
		}
	}() );

	// Widget "Lavori in corso": polling di GET /projects/{id}/queue. Aggiorna
	// la lista dei job attivi (tentativo, prossimo passaggio) e ricarica la
	// pagina quando lo stato del progetto cambia o la coda si svuota.
	( function () {
		var widget = document.querySelector( '[data-gw-queue]' );
		if ( ! widget ) {
			return;
		}
		var projectId = widget.getAttribute( 'data-gw-project' );
		var initialState = widget.getAttribute( 'data-gw-state' );
		var hadJobs = 'none' !== widget.style.display;
		var queueI18n = cfg.i18n.queue || {};

		function jobLine( job ) {
			var text = '<strong>' + escapeHtml( job.label ) + '</strong> — '
				+ ( 'in-progress' === job.status ? queueI18n.running : queueI18n.queued );
			if ( job.attempt > 1 ) {
				text += ' · ' + ( queueI18n.attempt || '' ).replace( '%1$d', job.attempt ).replace( '%2$d', '3' );
			}
			if ( job.next_run ) {
				text += ' · ' + ( queueI18n.nextRun || '' ).replace( '%s', job.next_run );
			}
			return '<p class="gw-queue-job">' + text + '</p>';
		}

		function poll() {
			api( '/projects/' + projectId + '/queue' ).then( function ( data ) {
				if ( data.state !== initialState || ( hadJobs && 0 === data.jobs.length ) ) {
					window.location.reload();
					return;
				}
				if ( 0 === data.jobs.length ) {
					widget.style.display = 'none';
					window.setTimeout( poll, 15000 );
					return;
				}
				hadJobs = true;
				var html = data.jobs.map( jobLine ).join( '' );
				if ( data.last_issue && data.last_issue.error && 'job_attempt_failed' === data.last_issue.event ) {
					html += '<p class="gw-queue-issue">' + escapeHtml( ( queueI18n.lastError || '' ) + ' ' + data.last_issue.error ) + '</p>';
				}
				widget.querySelector( '.gw-queue-body' ).innerHTML = html;
				widget.style.display = '';
				window.setTimeout( poll, 8000 );
			} ).catch( function () {
				window.setTimeout( poll, 30000 ); // Errore di rete: si riprova con calma.
			} );
		}

		window.setTimeout( poll, 4000 );
	}() );
}() );
