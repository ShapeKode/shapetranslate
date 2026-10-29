/**
 * Language panel for the WordPress Site Editor.
 *
 * Names the language of the template on screen and lets that be changed, and
 * lists the languages beside it: the version where it exists, and where it does
 * not, either starting one or adopting something the site already built. This is
 * the only place a template's languages are managed, so the work happens here
 * rather than sending an editor to a settings screen.
 *
 * Every write ends in a reload or a redirect. The Site Editor is one page that
 * never reloads, and all three of these operations rename a template, so the
 * address it was opened on stops being true the moment the request succeeds.
 *
 * Plain ES5, like the rest of ShapeTranslate: no build step, and `createElement`
 * rather than JSX for the same reason.
 *
 * @package ShapeTranslate
 */
( function ( wp ) {
	'use strict';

	var config = window.shapeTranslateSiteEditor;

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.components || ! config ) {
		return;
	}

	/*
	 * WordPress 6.6 moved the editor slots to `wp.editor`, where both editors
	 * share them. Older releases keep the Site Editor's own copies, and a site
	 * on one of those is still supported.
	 */
	var editor = wp.editor || {};
	var editSite = wp.editSite || {};
	var PluginSidebar = editor.PluginSidebar || editSite.PluginSidebar;
	var PluginSidebarMoreMenuItem = editor.PluginSidebarMoreMenuItem || editSite.PluginSidebarMoreMenuItem;

	if ( ! PluginSidebar ) {
		return;
	}

	var createElement = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;

	/*
	 * The Site Editor is one page that never reloads, so the address it started
	 * on stops describing what is open the moment an editor walks from the
	 * template list into a header. `useSelect` is how the panel hears about that
	 * — where the editor is old enough to be missing it, the panel still
	 * describes whatever the page loaded on, which is what it always did.
	 */
	var useSelect = wp.data && wp.data.useSelect ? wp.data.useSelect : null;
	var Button = wp.components.Button;
	var Spinner = wp.components.Spinner;
	var Notice = wp.components.Notice;
	var SelectControl = wp.components.SelectControl;

	/*
	 * A combobox filters as you type, which is the whole point of a list that may
	 * hold every template a theme ships. Where the editor is too old to have one,
	 * the same choice is offered as a plain select rather than not offered.
	 */
	var ComboboxControl = wp.components.ComboboxControl || null;

	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	// The one thing this panel manages that is named by identifier, not by slug.
	var MENU_POST_TYPE = 'wp_navigation';

	/**
	 * Returns one of the editor's own icons, by name where it has them.
	 *
	 * Dashicon names are accepted by every version this plugin supports, and
	 * they are the icons the editor already draws everywhere else.
	 *
	 * @param {string} name Dashicon name.
	 * @return {string} Icon reference.
	 */
	function icon( name ) {
		return name;
	}

	/**
	 * Returns the full path of one of the panel's routes.
	 *
	 * The paths belong to the description rather than to the panel, because a
	 * header and the template naming it are managed by routes of their own and
	 * the editor can walk from one to the other without reloading.
	 *
	 * @param {Object} view Description of the open template.
	 * @param {string} name Route name.
	 * @return {string} Request path.
	 */
	function route( view, name ) {
		return '/' + view.routes[ name ];
	}

	/**
	 * Returns the message an editor should be shown for a failed request.
	 *
	 * @param {Object} response Rejected response.
	 * @param {string} fallback Message to use when the server sent none.
	 * @return {string} Message.
	 */
	function reason( response, fallback ) {
		return response && response.message ? response.message : fallback;
	}

	/**
	 * Renders a language's flag, or its code when it has no flag.
	 *
	 * @param {Object} language Language entry.
	 * @return {Object} Element.
	 */
	function renderFlag( language ) {
		if ( language.flagUrl ) {
			return createElement( 'img', {
				className: 'shapetranslate-site-editor__flag',
				src: language.flagUrl,
				alt: '',
				width: 18,
				height: 12,
				loading: 'lazy',
				decoding: 'async',
			} );
		}

		return createElement(
			'abbr',
			{ className: 'shapetranslate-site-editor__code', title: language.name },
			language.code
		);
	}

	/**
	 * Returns the language the open template belongs to.
	 *
	 * @param {Object} view Description of the open template.
	 * @return {Object|null} Language entry.
	 */
	function currentLanguage( view ) {
		var index;

		for ( index = 0; index < view.languages.length; index++ ) {
			if ( view.languages[ index ].isCurrent ) {
				return view.languages[ index ];
			}
		}

		return null;
	}

	/**
	 * Returns what the editor has open, as a type and a handle.
	 *
	 * The handle is whatever names that thing to the routes behind this panel: a
	 * slug for a template or a part of one, and an identifier for a menu, which
	 * is a post and is named the way posts are. Two menus called "Navigation" are
	 * ordinary, so a slug would name the wrong one.
	 *
	 * Both editors are asked. The Site Editor keeps what it is editing in the
	 * editor store like any other post, and older releases keep it in a store of
	 * their own under a combined identifier — `theme//header` — whose second half
	 * is the slug.
	 *
	 * @param {Function} select Data registry selector.
	 * @return {Object} Type and handle, either of which may be empty.
	 */
	function editedTemplate( select ) {
		var store = select( 'core/editor' );
		var post = store && store.getCurrentPost ? store.getCurrentPost() : null;
		var type = post && post.type ? String( post.type ) : '';
		var slug = post && post.slug ? String( post.slug ) : '';
		var id = post && post.id ? String( post.id ) : '';
		var site;
		var identifier;

		if ( '' === type || ( '' === slug && '' === id ) ) {
			site = select( 'core/edit-site' );

			if ( site && site.getEditedPostType ) {
				type = '' !== type ? type : String( site.getEditedPostType() || '' );
				identifier = String( site.getEditedPostId() || '' );

				if ( identifier.indexOf( '//' ) > -1 ) {
					slug = identifier.split( '//' ).pop();
				} else if ( '' !== identifier ) {
					id = identifier;
				}
			}
		}

		if ( config.postTypes.indexOf( type ) === -1 ) {
			return { type: '', slug: '' };
		}

		return { type: type, slug: MENU_POST_TYPE === type ? id : slug };
	}

	/**
	 * Returns the description of a panel with nothing to describe.
	 *
	 * @return {Object} Description.
	 */
	function nothingOpen() {
		return {
			isOpen: false,
			languages: [],
			canManage: config.canManage,
			panelRoute: config.panelRoute,
			postTypes: config.postTypes,
		};
	}

	/**
	 * Tells the rest of the editor which language is now being worked in.
	 *
	 * The middleware that tags REST requests with a language reads this, and it
	 * was written once, when the page loaded. The Site Editor never reloads, so
	 * walking from an English header into its Bengali translation left every list
	 * in the canvas still being answered in English — the page list a navigation
	 * block falls back to, the link search, the menus offered to it.
	 *
	 * Lists already fetched stay as they are: the editor caches them under the
	 * request it made, and the language is not part of that request. New ones are
	 * asked for in the language on screen.
	 *
	 * @param {Object} view Description of what is now open.
	 * @return {void}
	 */
	function rememberEditorLanguage( view ) {
		var editor = window.shapeTranslateEditor;

		if ( ! editor ) {
			return;
		}

		editor.language = view.isOpen && view.language ? view.language : editor.defaultLanguage || '';
	}

	/**
	 * Leaves for wherever a write says the template now lives.
	 *
	 * @param {Object} response Successful response.
	 * @return {void}
	 */
	function follow( response ) {
		if ( response && response.editUrl ) {
			window.location.href = response.editUrl;
			return;
		}

		window.location.reload();
	}

	/**
	 * The panel.
	 *
	 * @return {Object} Element.
	 */
	function Panel() {
		/*
		 * What the panel is describing: whatever the page loaded on to begin
		 * with, and from then on whatever the editor moves to.
		 */
		var viewState = useState( config );
		var view = viewState[ 0 ];
		var setView = viewState[ 1 ];

		var loadingState = useState( false );
		var loading = loadingState[ 0 ];
		var setLoading = loadingState[ 1 ];

		var busyState = useState( '' );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];

		// The language whose row has the "adopt an existing one" field open.
		var linkingState = useState( '' );
		var linking = linkingState[ 0 ];
		var setLinking = linkingState[ 1 ];

		// Null until the list has been asked for, which is not on every panel.
		var candidatesState = useState( null );
		var candidates = candidatesState[ 0 ];
		var setCandidates = candidatesState[ 1 ];

		var chosenState = useState( '' );
		var chosen = chosenState[ 0 ];
		var setChosen = chosenState[ 1 ];

		/*
		 * A hook behind a condition, which is allowed here and nowhere else:
		 * `useSelect` either exists for the whole life of the page or never
		 * does, so the same branch is taken on every render and the order of
		 * hooks never changes. Where it is missing the panel falls back to the
		 * address, and describes the screen the editor was opened on.
		 */
		var open = useSelect
			? useSelect( function ( select ) {
					var edited = editedTemplate( select );

					// A string, so a re-render is not provoked by a new object.
					return edited.type + '|' + edited.slug;
			  }, [] )
			: '';

		useEffect(
			function () {
				var cancelled = false;
				var opened;
				var separator = open.indexOf( '|' );
				var type = separator > -1 ? open.slice( 0, separator ) : '';
				var slug = separator > -1 ? open.slice( separator + 1 ) : '';

				if ( ! useSelect ) {
					return undefined;
				}

				// Already describing this one, which is the usual first render.
				if ( type === ( view.postType || '' ) && slug === ( view.slug || '' ) ) {
					return undefined;
				}

				/*
				 * Everything below belongs to the template being left: a row
				 * waiting on a request, a failure worth reporting, and the list
				 * of templates that were free to be adopted into it.
				 */
				setBusy( '' );
				setError( '' );
				setLinking( '' );
				setChosen( '' );
				setCandidates( null );

				if ( '' === type || '' === slug ) {
					setView( nothingOpen() );
					rememberEditorLanguage( nothingOpen() );
					return undefined;
				}

				setLoading( true );

				wp.apiFetch( {
					path:
						'/' +
						config.panelRoute +
						'?postType=' +
						encodeURIComponent( type ) +
						'&slug=' +
						encodeURIComponent( slug ),
				} ).then(
					function ( response ) {
						// An editor who has already moved on again is not waiting for this.
						if ( cancelled ) {
							return;
						}

						setLoading( false );

						opened = response && response.isOpen ? response : nothingOpen();

						setView( opened );
						rememberEditorLanguage( opened );
					},
					function () {
						if ( cancelled ) {
							return;
						}

						setLoading( false );
						setView( nothingOpen() );
					}
				);

				return function () {
					cancelled = true;
				};
			},
			[ open ]
		);

		/**
		 * Reports a failed request and releases whatever row was waiting.
		 *
		 * @param {Object} response Rejected response.
		 * @param {string} fallback Message to use when the server sent none.
		 * @return {void}
		 */
		function fail( response, fallback ) {
			setBusy( '' );
			setError( reason( response, fallback ) );
		}

		/**
		 * Moves the open template into another language.
		 *
		 * @param {string} languageId Language to move it into.
		 * @return {void}
		 */
		function moveToLanguage( languageId ) {
			var language = find( languageId );

			if ( ! language || language.isCurrent ) {
				return;
			}

			var question = sprintf(
				/* translators: %s: language name. */
				__(
					'Move this to %s? It is renamed to match, so anything that names it by its old name will fall back to the original.',
					'shapetranslate'
				),
				language.nativeName
			);

			// eslint-disable-next-line no-alert
			if ( ! window.confirm( question ) ) {
				return;
			}

			setError( '' );
			setBusy( languageId );

			wp.apiFetch( {
				path: route( view, 'language' ),
				method: 'POST',
				data: { slug: view.slug, language: languageId },
			} ).then( follow, function ( response ) {
				fail( response, __( 'ShapeTranslate could not change that language.', 'shapetranslate' ) );
			} );
		}

		/**
		 * Starts a language's version of the open template, then opens it.
		 *
		 * @param {Object} language Language to create.
		 * @return {void}
		 */
		function addTranslation( language ) {
			setError( '' );
			setBusy( language.id );

			wp.apiFetch( {
				path: route( view, 'translation' ),
				method: 'POST',
				data: { slug: view.slug, language: language.id },
			} ).then( follow, function ( response ) {
				fail( response, __( 'ShapeTranslate could not create that template.', 'shapetranslate' ) );
			} );
		}

		/**
		 * Adopts something the site already has as a language's version.
		 *
		 * @param {Object} language Language the adopted template is in.
		 * @return {void}
		 */
		function linkTranslation( language ) {
			if ( ! chosen ) {
				return;
			}

			setError( '' );
			setBusy( language.id );

			wp.apiFetch( {
				path: route( view, 'link' ),
				method: 'POST',
				data: { slug: view.slug, language: language.id, target: chosen },
			} ).then( follow, function ( response ) {
				fail( response, __( 'ShapeTranslate could not link that template.', 'shapetranslate' ) );
			} );
		}

		/**
		 * Removes a language's version of the open template.
		 *
		 * @param {Object} language Language to remove.
		 * @return {void}
		 */
		function deleteTranslation( language ) {
			var question = sprintf(
				/* translators: %s: language name. */
				__( 'Delete the %s version of this? This cannot be undone.', 'shapetranslate' ),
				language.nativeName
			);

			if ( language.isDefault ) {
				question += '\n\n' + __(
					'This is the version every other language falls back to, so its translations are deleted with it.',
					'shapetranslate'
				);
			}

			// eslint-disable-next-line no-alert
			if ( ! window.confirm( question ) ) {
				return;
			}

			setError( '' );
			setBusy( language.id );

			wp.apiFetch( {
				path: route( view, 'translation' ),
				method: 'DELETE',
				data: { slug: view.slug, language: language.id },
			} ).then(
				function ( response ) {
					// What is on screen may be the thing just deleted.
					if ( language.isCurrent && response && response.editUrl ) {
						window.location.href = response.editUrl;
						return;
					}

					window.location.reload();
				},
				function ( response ) {
					fail( response, __( 'ShapeTranslate could not delete that template.', 'shapetranslate' ) );
				}
			);
		}

		/**
		 * Opens the adopt field on one row, asking for the list the first time.
		 *
		 * @param {Object} language Language whose row was opened.
		 * @return {void}
		 */
		function openLinking( language ) {
			setError( '' );
			setChosen( '' );
			setLinking( linking === language.id ? '' : language.id );

			if ( null !== candidates ) {
				return;
			}

			wp.apiFetch( {
				path: route( view, 'candidates' ) + '?slug=' + encodeURIComponent( view.slug ),
			} ).then(
				function ( response ) {
					setCandidates( Array.isArray( response ) ? response : [] );
				},
				function () {
					setCandidates( [] );
				}
			);
		}

		/**
		 * Returns one language entry by identifier.
		 *
		 * @param {string} languageId Language identifier.
		 * @return {Object|null} Language entry.
		 */
		function find( languageId ) {
			var index;

			for ( index = 0; index < view.languages.length; index++ ) {
				if ( view.languages[ index ].id === languageId ) {
					return view.languages[ index ];
				}
			}

			return null;
		}

		/**
		 * Renders the language the open template belongs to, and lets it change.
		 *
		 * @return {Object|null} Element.
		 */
		function renderCurrent() {
			var current = currentLanguage( view );

			if ( ! current ) {
				return null;
			}

			var options = view.languages
				.filter( function ( language ) {
					return language.isEnabled;
				} )
				.map( function ( language ) {
					return { label: language.nativeName, value: language.id };
				} );

			var movable = view.canManage && view.canMove && ! busy;

			return createElement(
				'div',
				{ className: 'shapetranslate-site-editor__section' },
				createElement( 'h2', { className: 'shapetranslate-site-editor__heading' }, __( 'Language', 'shapetranslate' ) ),
				createElement(
					'div',
					{ className: 'shapetranslate-site-editor__current' },
					renderFlag( current ),
					createElement( SelectControl, {
						className: 'shapetranslate-site-editor__select',
						label: __( 'Language', 'shapetranslate' ),
						hideLabelFromVision: true,
						value: current.id,
						options: options,
						disabled: ! movable,
						__nextHasNoMarginBottom: true,
						onChange: moveToLanguage,
					} )
				),
				view.canMove
					? null
					: createElement( 'p', { className: 'shapetranslate-site-editor__empty' }, view.moveNotice )
			);
		}

		/**
		 * Renders the field that adopts an existing template into one language.
		 *
		 * @param {Object} language Language whose row is open.
		 * @return {Object} Element.
		 */
		function renderLinking( language ) {
			if ( null === candidates ) {
				return createElement( Spinner, null );
			}

			if ( ! candidates.length ) {
				return createElement(
					'p',
					{ className: 'shapetranslate-site-editor__empty' },
					__( 'Nothing here is free to be adopted as a translation.', 'shapetranslate' )
				);
			}

			var options = candidates.map( function ( candidate ) {
				return { label: candidate.title, value: candidate.slug };
			} );

			var field;

			if ( ComboboxControl ) {
				field = createElement( ComboboxControl, {
					label: __( 'Search for an existing one', 'shapetranslate' ),
					hideLabelFromVision: true,
					placeholder: __( 'Search for an existing one', 'shapetranslate' ),
					value: chosen,
					options: options,
					allowReset: false,
					__nextHasNoMarginBottom: true,
					onChange: function ( value ) {
						setChosen( value || '' );
					},
				} );
			} else {
				field = createElement( SelectControl, {
					label: __( 'Search for an existing one', 'shapetranslate' ),
					hideLabelFromVision: true,
					value: chosen,
					options: [ { label: __( 'Select one…', 'shapetranslate' ), value: '' } ].concat( options ),
					__nextHasNoMarginBottom: true,
					onChange: function ( value ) {
						setChosen( value || '' );
					},
				} );
			}

			return createElement(
				'div',
				{ className: 'shapetranslate-site-editor__linking' },
				field,
				createElement(
					Button,
					{
						variant: 'secondary',
						isSecondary: true,
						disabled: ! chosen || busy === language.id,
						onClick: function () {
							linkTranslation( language );
						},
					},
					sprintf(
						/* translators: %s: language name. */
						__( 'Use as %s', 'shapetranslate' ),
						language.nativeName
					)
				)
			);
		}

		/**
		 * Renders one language row.
		 *
		 * @param {Object} language Language entry.
		 * @return {Object} Element.
		 */
		function renderRow( language ) {
			var working = busy === language.id;
			var cells = [
				createElement(
					'th',
					{ key: 'flag', className: 'shapetranslate-site-editor__flag-cell', scope: 'row' },
					renderFlag( language )
				),
				createElement(
					'td',
					{ key: 'name', className: 'shapetranslate-site-editor__name-cell' },
					language.nativeName
				),
			];

			var action;

			if ( working ) {
				action = createElement( Spinner, null );
			} else if ( language.slug ) {
				action = createElement( Button, {
					icon: icon( 'edit' ),
					href: language.editUrl,
					disabled: ! language.editUrl,
					className: 'shapetranslate-site-editor__button is-edit',
					label: sprintf(
						/* translators: %s: language name. */
						__( 'Edit the %s version', 'shapetranslate' ),
						language.nativeName
					),
					showTooltip: true,
				} );
			} else {
				action = createElement( Button, {
					icon: icon( 'plus-alt2' ),
					disabled: ! view.canManage,
					className: 'shapetranslate-site-editor__button is-add',
					label: sprintf(
						/* translators: %s: language name. */
						__( 'Add a translation in %s', 'shapetranslate' ),
						language.nativeName
					),
					showTooltip: true,
					onClick: function () {
						addTranslation( language );
					},
				} );
			}

			cells.push(
				createElement( 'td', { key: 'action', className: 'shapetranslate-site-editor__icon-cell' }, action )
			);

			/*
			 * Adopting is offered only where there is nothing yet, because it is
			 * the other answer to the same question the plus button answers: this
			 * language has no version, and one either gets written or gets named.
			 */
			cells.push(
				createElement(
					'td',
					{ key: 'link', className: 'shapetranslate-site-editor__icon-cell' },
					language.slug
						? null
						: createElement( Button, {
								icon: icon( 'admin-links' ),
								disabled: working || ! view.canManage,
								isPressed: linking === language.id,
								className: 'shapetranslate-site-editor__button is-link',
								label: sprintf(
									/* translators: %s: language name. */
									__( 'Use an existing one as the %s version', 'shapetranslate' ),
									language.nativeName
								),
								showTooltip: true,
								onClick: function () {
									openLinking( language );
								},
						  } )
				)
			);

			cells.push(
				createElement(
					'td',
					{ key: 'delete', className: 'shapetranslate-site-editor__icon-cell' },
					createElement( Button, {
						icon: icon( 'trash' ),
						disabled: working || ! language.isDeletable || ! view.canManage,
						className: 'shapetranslate-site-editor__button is-delete',
						label: sprintf(
							/* translators: %s: language name. */
							__( 'Delete the %s version', 'shapetranslate' ),
							language.nativeName
						),
						showTooltip: true,
						onClick: function () {
							deleteTranslation( language );
						},
					} )
				)
			);

			cells.push(
				createElement(
					'td',
					{ key: 'default', className: 'shapetranslate-site-editor__icon-cell' },
					language.isDefault
						? createElement(
								'span',
								{
									className: 'shapetranslate-site-editor__default',
									title: __( 'Default language', 'shapetranslate' ),
								},
								createElement(
									'span',
									{ className: 'screen-reader-text' },
									__( 'Default language', 'shapetranslate' )
								)
						  )
						: null
				)
			);

			var row = createElement(
				'tr',
				{ key: language.id, className: language.isCurrent ? 'is-current' : '' },
				cells
			);

			if ( linking !== language.id ) {
				return row;
			}

			return createElement(
				Fragment,
				{ key: language.id },
				row,
				createElement(
					'tr',
					{ className: 'shapetranslate-site-editor__linking-row' },
					createElement( 'td', { colSpan: 6 }, renderLinking( language ) )
				)
			);
		}

		var others = view.languages.filter( function ( language ) {
			return ! language.isCurrent;
		} );

		var body;

		if ( loading ) {
			body = createElement(
				'p',
				{ className: 'shapetranslate-site-editor__empty' },
				createElement( Spinner, null )
			);
		} else if ( ! view.isOpen ) {
			body = createElement(
				'p',
				{ className: 'shapetranslate-site-editor__empty' },
				__( 'Open a template, a template part, or a menu to see which language it belongs to.', 'shapetranslate' )
			);
		} else {
			body = createElement(
				Fragment,
				null,
				renderCurrent(),
				createElement(
					'div',
					{ className: 'shapetranslate-site-editor__section' },
					createElement(
						'h2',
						{ className: 'shapetranslate-site-editor__heading' },
						__( 'Translations', 'shapetranslate' )
					),
					others.length
						? createElement(
								'table',
								{ className: 'shapetranslate-site-editor__table' },
								createElement( 'tbody', null, others.map( renderRow ) )
						  )
						: createElement(
								'p',
								{ className: 'shapetranslate-site-editor__empty' },
								__( 'This site publishes one language.', 'shapetranslate' )
						  )
				)
			);
		}

		return createElement(
			Fragment,
			null,
			PluginSidebarMoreMenuItem
				? createElement(
						PluginSidebarMoreMenuItem,
						{ target: 'shapetranslate-languages', icon: icon( 'translation' ) },
						__( 'Languages', 'shapetranslate' )
				  )
				: null,
			createElement(
				PluginSidebar,
				{
					name: 'shapetranslate-languages',
					title: __( 'Languages', 'shapetranslate' ),
					// Without an icon the sidebar gets no toolbar button of its own.
					icon: icon( 'translation' ),
					className: 'shapetranslate-site-editor',
				},
				error
					? createElement(
							Notice,
							{
								status: 'error',
								isDismissible: true,
								onRemove: function () {
									setError( '' );
								},
							},
							error
					  )
					: null,
				body
			)
		);
	}

	wp.plugins.registerPlugin( 'shapetranslate-site-editor', {
		render: Panel,
		icon: icon( 'translation' ),
	} );
} )( window.wp );
