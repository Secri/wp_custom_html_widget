/**
 * field-repeater.js
 *
 * Rôle unique de ce fichier : mécanique JS du repeater de blocs côté admin.
 * - Toggle accordéon (ouvrir/fermer un bloc)
 * - Ajout d'un nouveau bloc (clonage du <template>, id temporaire unique)
 * - Suppression d'un bloc (avec confirmation)
 * - Synchronisation en direct du titre affiché dans l'en-tête d'accordéon
 *
 * Vanilla JS, sans dépendance jQuery ni autre bibliothèque : ce fichier ne
 * gère que la mécanique du repeater. L'initialisation de Select2 et de
 * CodeMirror sur les champs nouvellement clonés est déclenchée via un
 * événement custom ('chtw:block-added'), à laquelle code-editor.php (et son
 * JS associé) et le futur script Select2 s'abonnent indépendamment — ce
 * fichier n'a pas à connaître leur existence.
 */

( function () {
	'use strict';

	// Compteur local, purement côté client, pour générer des id temporaires
	// uniques ('new_1', 'new_2'...) sur les blocs fraîchement ajoutés. Ce
	// compteur repart de zéro à chaque chargement de page : il ne sert qu'à
	// distinguer les nouveaux blocs ENTRE EUX le temps de la session en cours,
	// pas à générer l'id définitif ('widget_N'), qui est attribué côté PHP
	// uniquement à la sauvegarde (cf chtw_sanitize_blocks() dans settings.php).
	var newBlockCounter = 0;

	document.addEventListener( 'DOMContentLoaded', function () {

		var blocksList  = document.getElementById( 'chtw-blocks-list' );
		var addButton   = document.getElementById( 'chtw-add-block' );
		var template    = document.getElementById( 'chtw-block-template' );

		if ( ! blocksList || ! addButton || ! template ) {
			return; // page pas dans l'état attendu, on n'installe aucun handler
		}

		/* ------------------------------------------------------------
		 * 1. Toggle accordéon (délégation d'événement sur la liste,
		 *    pour couvrir aussi les blocs ajoutés dynamiquement)
		 * ---------------------------------------------------------- */

		blocksList.addEventListener( 'click', function ( event ) {
			var header = event.target.closest( '.chtw-accordion-header' );
			if ( ! header ) {
				return;
			}
			// Un clic sur les boutons d'action de l'en-tête (supprimer, monter,
			// descendre) ne doit pas aussi déclencher le toggle de l'accordéon.
			if ( event.target.closest( '.chtw-remove-block, .chtw-move-block-up, .chtw-move-block-down' ) ) {
				return;
			}
			toggleAccordion( header );
		} );

		// Accessibilité clavier : Entrée / Espace sur l'en-tête doit aussi togglé.
		blocksList.addEventListener( 'keydown', function ( event ) {
			var header = event.target.closest( '.chtw-accordion-header' );
			if ( ! header ) {
				return;
			}
			if ( 'Enter' === event.key || ' ' === event.key ) {
				event.preventDefault();
				toggleAccordion( header );
			}
		} );

		function toggleAccordion( header ) {
			var row  = header.closest( '.chtw-accordion' );
			var body = row.querySelector( '.chtw-accordion-body' );
			var icon = header.querySelector( '.chtw-accordion-toggle-icon' );

			var isExpanded = 'true' === header.getAttribute( 'aria-expanded' );

			header.setAttribute( 'aria-expanded', isExpanded ? 'false' : 'true' );
			body.style.display = isExpanded ? 'none' : '';
			if ( icon ) {
				icon.textContent = isExpanded ? '▶' : '▼';
			}
		}

		/**
		 * Grise/désactive le bouton "Monter" du tout premier bloc et le bouton
		 * "Descendre" du tout dernier bloc, pour éviter un clic sans effet visible
		 * sur une extrémité de la liste. À rappeler après tout changement d'ordre
		 * (ajout, suppression, déplacement) puisque les extrémités changent.
		 */
		function refreshMoveButtonsState() {
			var rows = blocksList.querySelectorAll( '.chtw-accordion' );

			rows.forEach( function ( row, index ) {
				var upButton   = row.querySelector( '.chtw-move-block-up' );
				var downButton = row.querySelector( '.chtw-move-block-down' );

				if ( upButton ) {
					upButton.disabled = ( 0 === index );
				}
				if ( downButton ) {
					downButton.disabled = ( index === rows.length - 1 );
				}
			} );
		}

		// État initial au chargement de la page (blocs déjà présents en PHP).
		refreshMoveButtonsState();

		/* ------------------------------------------------------------
		 * 2. Synchronisation en direct du titre affiché (délégation
		 *    d'événement, pour couvrir aussi les blocs ajoutés dynamiquement)
		 * ---------------------------------------------------------- */

		blocksList.addEventListener( 'input', function ( event ) {
			if ( ! event.target.classList.contains( 'chtw-block-title-field' ) ) {
				return;
			}
			var row     = event.target.closest( '.chtw-accordion' );
			var display = row.querySelector( '.chtw-block-title-display' );
			if ( ! display ) {
				return;
			}
			var value = event.target.value.trim();
			display.textContent = '' !== value ? value : chtwRepeaterData.noTitleLabel;
		} );

		/* ------------------------------------------------------------
		 * 3. Suppression d'un bloc (avec confirmation, délégation d'événement)
		 * ---------------------------------------------------------- */

		blocksList.addEventListener( 'click', function ( event ) {
			var removeButton = event.target.closest( '.chtw-remove-block' );
			if ( ! removeButton ) {
				return;
			}
			event.preventDefault();

			var confirmed = window.confirm( chtwRepeaterData.confirmRemoveLabel );
			if ( ! confirmed ) {
				return;
			}

			var row = removeButton.closest( '.chtw-accordion' );
			if ( row ) {
				row.parentNode.removeChild( row );
			}
			refreshMoveButtonsState();
		} );

		/* ------------------------------------------------------------
		 * 3bis. Réordonnancement d'un bloc (monter / descendre)
		 *
		 * L'ordre d'affichage front suit l'ordre de soumission du tableau
		 * chtw_blocks[] (positionnel, cf chtw_render_block_row()) : réordonner
		 * visuellement le bloc dans le DOM avant soumission suffit donc à
		 * changer son ordre d'affichage, sans logique PHP supplémentaire.
		 * ---------------------------------------------------------- */

		blocksList.addEventListener( 'click', function ( event ) {

			var moveUpButton = event.target.closest( '.chtw-move-block-up' );
			var moveDownButton = event.target.closest( '.chtw-move-block-down' );

			if ( ! moveUpButton && ! moveDownButton ) {
				return;
			}
			event.preventDefault();

			// Sécurité : un bouton disabled ne devrait déjà pas déclencher de clic
			// dans la plupart des navigateurs, mais on vérifie explicitement pour
			// éviter tout comportement inattendu (ex: clic synthétique, focus clavier).
			if ( ( moveUpButton && moveUpButton.disabled ) || ( moveDownButton && moveDownButton.disabled ) ) {
				return;
			}

			var row = ( moveUpButton || moveDownButton ).closest( '.chtw-accordion' );
			if ( ! row ) {
				return;
			}

			if ( moveUpButton ) {
				var previousRow = row.previousElementSibling;
				if ( previousRow ) {
					blocksList.insertBefore( row, previousRow );
				}
			} else {
				var nextRow = row.nextElementSibling;
				if ( nextRow ) {
					blocksList.insertBefore( nextRow, row );
				}
			}

			refreshMoveButtonsState();
		} );

		/* ------------------------------------------------------------
		 * 4. Ajout d'un nouveau bloc (clonage du template)
		 * ---------------------------------------------------------- */

		addButton.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			newBlockCounter += 1;
			var tempId = 'new_' + newBlockCounter;

			var fragment = template.content.cloneNode( true );
			var newRow   = fragment.querySelector( '.chtw-accordion' );

			// Retire le message "Aucun bloc pour le moment" s'il est présent,
			// devenu obsolète dès qu'un premier bloc est ajouté.
			var emptyNotice = blocksList.querySelector( '.chtw-no-blocks' );
			if ( emptyNotice ) {
				emptyNotice.parentNode.removeChild( emptyNotice );
			}

			// Renseigne l'id temporaire dans le champ caché ET dans l'attribut
			// data-block-id (utile pour le futur script Select2/CodeMirror qui
			// aura besoin de cibler ce bloc précisément).
			var idField = newRow.querySelector( '.chtw-block-id-field' );
			if ( idField ) {
				idField.value = tempId;
			}
			newRow.setAttribute( 'data-block-id', tempId );

			// Le nouveau bloc apparaît déjà déplié, contrairement aux blocs
			// existants (repliés par défaut) : l'utilisateur vient de cliquer
			// sur "Ajouter", il s'apprête donc à le remplir immédiatement.
			var header = newRow.querySelector( '.chtw-accordion-header' );
			var body   = newRow.querySelector( '.chtw-accordion-body' );
			if ( header && body ) {
				header.setAttribute( 'aria-expanded', 'true' );
				body.style.display = '';
				var icon = header.querySelector( '.chtw-accordion-toggle-icon' );
				if ( icon ) {
					icon.textContent = '▼';
				}
			}

			blocksList.appendChild( fragment );
			refreshMoveButtonsState();

			// Prévient le reste du plugin (Select2, CodeMirror) qu'un nouveau
			// bloc vient d'être inséré dans le DOM, pour qu'ils puissent
			// s'initialiser sur ses champs sans que ce fichier ait besoin de
			// connaître leur implémentation.
			document.dispatchEvent( new CustomEvent( 'chtw:block-added', {
				detail: { blockId: tempId, blockElement: newRow }
			} ) );

			// Amène le nouveau bloc dans le champ de vision de l'utilisateur.
			newRow.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		} );

	} );

} )();
