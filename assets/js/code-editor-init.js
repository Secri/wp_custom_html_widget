/**
 * code-editor-init.js
 *
 * Rôle unique de ce fichier : initialiser CodeMirror (wp.codeEditor) sur les champs .chtw-html-editor — à la fois ceux présents au chargement de la page, et sur les blocs ajoutés dynamiquement par field-repeater.js
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
	 * Initialise CodeMirror sur un textarea donné, s'il n'est pas déjà initialisé.
	 *
	 * Chaque textarea reçoit donc un id HTML unique généré ici, basé sur le data-block-id du bloc parent (stable pour un bloc existant, temporaire de type 'new_N' pour un bloc fraîchement ajouté).
	 *
	 * @param {HTMLTextAreaElement} textarea
	 */
	function initEditorOn( textarea ) {
		if ( ! textarea || textarea.classList.contains( 'chtw-codemirror-initialized' ) ) {
			return; // déjà initialisé, ou élément invalide
		}

		if ( ! textarea.id ) {
			const blockRow = textarea.closest( '.chtw-accordion' );
			const blockId  = blockRow ? blockRow.getAttribute( 'data-block-id' ) : ( 'unknown_' + Date.now() );
			textarea.id    = 'chtw-html-editor-' + blockId;
		}

		wp.codeEditor.initialize( textarea.id, chtwCodeEditorSettings );
		textarea.classList.add( 'chtw-codemirror-initialized' );
	}

	document.addEventListener( 'DOMContentLoaded', function () {

		// 1. Blocs déjà présents au chargement de la page.
		const existingEditors = document.querySelectorAll( '.chtw-html-editor' );
		existingEditors.forEach( initEditorOn );

		// 2. Nouveaux blocs ajoutés dynamiquement par field-repeater.js.
		document.addEventListener( 'chtw:block-added', function ( event ) {
			const blockElement = event.detail?.blockElement; //Opérateur de chaînage optionnel : assigne blockElement à la variable seilement si event.detail n'est ni null ni undefined
			if ( ! blockElement ) {
				return;
			}
			const newTextarea = blockElement.querySelector( '.chtw-html-editor' );
			initEditorOn( newTextarea );
		} );

	} );

} )();
