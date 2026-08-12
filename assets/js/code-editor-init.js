/**
 * code-editor-init.js
 *
 * Rôle unique de ce fichier : initialiser CodeMirror (wp.codeEditor) sur les champs .chtw-html-editor.
 *
 * L'initialisation est PARESSEUSE : elle a lieu au premier affichage réel du bloc, jamais au
 * chargement de la page. Les blocs sont repliés par défaut (display:none), or CodeMirror mesure son
 * conteneur à l'initialisation et se rend avec une hauteur nulle s'il est masqué à ce moment-là.
 *
 * Ce fichier s'abonne à trois événements custom émis par field-repeater.js :
 * - 'chtw:block-added'    → bloc inséré déjà déplié, donc visible : initialisation immédiate
 * - 'chtw:block-expanded' → initialisation au premier dépliage, remesure aux suivants
 * - 'chtw:block-moved'    → remesure uniquement (le bloc déplacé peut être replié)
 *
 * Dépend de :
 * - window.wp.codeEditor (fourni par le script 'code-editor' natif WordPress)
 * - window.chtwCodeEditorSettings (injecté par wp_localize_script() dans code-editor.php)
 */

( function () {
	'use strict';

	if ( 'undefined' === typeof wp || ! wp.codeEditor || 'undefined' === typeof chtwCodeEditorSettings ) {
		return; // CodeMirror non disponible (ex: utilisateur ayant désactivé la coloration syntaxique)
	}

	/**
	 * Instances CodeMirror créées, indexées par le textarea qu'elles pilotent.
	 *
	 * WeakMap plutôt que propriété posée sur l'élément DOM : la référence ne survit pas à la
	 * suppression du bloc (pas de fuite mémoire si l'admin ajoute et supprime des blocs en série),
	 * et on ne pollue pas le DOM avec un attribut interne au plugin.
	 */
	const editors = new WeakMap();

	/**
	 * Initialise CodeMirror sur un textarea donné, s'il n'est pas déjà initialisé.
	 *
	 * Chaque textarea reçoit un id HTML unique généré ici, basé sur le data-block-id du bloc parent (stable pour un bloc existant, temporaire de type 'new_N' pour un bloc fraîchement ajouté).
	 *
	 * IMPORTANT : ne doit être appelée que sur un textarea VISIBLE. CodeMirror mesure la hauteur
	 * de son conteneur au moment de l'initialisation ; dans un parent en display:none, il se rend
	 * avec une hauteur nulle et reste inutilisable jusqu'à un refresh() explicite.
	 *
	 * @param {HTMLTextAreaElement} textarea
	 */
	function initEditorOn( textarea ) {
		if ( ! textarea || editors.has( textarea ) ) {
			return; // déjà initialisé, ou élément invalide
		}

		if ( ! textarea.id ) {
			const blockRow = textarea.closest( '.chtw-accordion' );
			const blockId  = blockRow ? blockRow.getAttribute( 'data-block-id' ) : ( 'unknown_' + Date.now() );
			textarea.id    = 'chtw-html-editor-' + blockId;
		}

		const editor = wp.codeEditor.initialize( textarea.id, chtwCodeEditorSettings );

		editors.set( textarea, editor );
		textarea.classList.add( 'chtw-codemirror-initialized' ); //Repère visuel pour l'inspection du DOM, la source de vérité reste la WeakMap
	}

	/**
	 * Retourne le textarea d'édition HTML contenu dans un bloc, ou null.
	 *
	 * @param {HTMLElement} blockElement
	 * @return {HTMLTextAreaElement|null}
	 */
	function getTextareaIn( blockElement ) {
		return blockElement ? blockElement.querySelector( '.chtw-html-editor' ) : null;
	}

	/**
	 * Appelée quand un bloc devient visible : initialise l'éditeur au premier affichage, puis se
	 * contente de le remesurer aux affichages suivants.
	 *
	 * C'est ce report au premier affichage qui règle le problème de fond : les blocs sont repliés
	 * par défaut (cf .chtw-accordion-body { display: none } dans admin-style.css), donc plus rien
	 * n'est initialisé dans un conteneur masqué. Effet de bord bienvenu : on ne crée plus autant
	 * d'instances CodeMirror que de blocs au chargement de la page.
	 *
	 * @param {HTMLElement} blockElement
	 */
	function handleBlockShown( blockElement ) {
		const textarea = getTextareaIn( blockElement );

		if ( ! textarea ) {
			return;
		}

		if ( editors.has( textarea ) ) {
			refreshEditorIn( blockElement );
			return;
		}

		initEditorOn( textarea );
	}

	/**
	 * Force un éditeur déjà initialisé à se remesurer. Sans effet si le bloc n'en contient pas
	 * encore un — on ne veut surtout pas en créer un ici, le bloc pouvant être replié.
	 *
	 * @param {HTMLElement} blockElement
	 */
	function refreshEditorIn( blockElement ) {
		const textarea = getTextareaIn( blockElement );
		const editor   = textarea ? editors.get( textarea ) : null;

		if ( editor && editor.codemirror ) {
			editor.codemirror.refresh();
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {

		// 1. Bloc fraîchement ajouté par field-repeater.js : il est inséré déjà déplié, donc
		//    visible — on peut initialiser immédiatement.
		document.addEventListener( 'chtw:block-added', function ( event ) {
			initEditorOn( getTextareaIn( event.detail?.blockElement ) ); //Opérateur de chaînage optionnel : évite l'erreur JS si event.detail est absent
		} );

		// 2. Bloc existant déplié par l'utilisateur : initialisation au premier affichage,
		//    remesure aux suivants.
		document.addEventListener( 'chtw:block-expanded', function ( event ) {
			const blockElement = event.detail?.blockElement;
			if ( blockElement ) {
				handleBlockShown( blockElement );
			}
		} );

		// 3. Bloc déplacé (monter/descendre) : le nœud a été détaché puis rattaché. On remesure
		//    l'éditeur s'il existe déjà, sans jamais en créer un (le bloc peut être replié).
		document.addEventListener( 'chtw:block-moved', function ( event ) {
			const blockElement = event.detail?.blockElement;
			if ( blockElement ) {
				refreshEditorIn( blockElement );
			}
		} );

	} );

} )();
