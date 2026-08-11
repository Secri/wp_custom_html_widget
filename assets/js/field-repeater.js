/**
 * field-repeater.js
 *
 * Rôle unique de ce fichier : mécanique JS du repeater de blocs côté admin.
 * - Toggle accordéon (ouvrir/fermer un bloc)
 * - Ajout d'un nouveau bloc (clonage du <template>, id temporaire unique)
 * - Suppression d'un bloc (avec confirmation)
 * - Synchronisation en direct du titre affiché dans l'en-tête d'accordéon
 *
 * Vanilla JS, sans dépendance jQuery ni autre bibliothèque : ce fichier ne gère que la mécanique du repeater. 
 * L'initialisation de Select2 et de CodeMirror sur les champs nouvellement clonés est déclenchée via un événement custom ('chtw:block-added'), auquel code-editor.php et term-select.php (via les fichiers JS associés) s'abonnent indépendamment.
 * Ce fichier n'a pas à connaître leur existence.
 */

( function () {
	'use strict';

	// Compteur local, purement côté client, pour générer des id temporaires
	// uniques ('new_1', 'new_2'...) sur les blocs fraîchement ajoutés. Ce
	// compteur repart de zéro à chaque chargement de page : il ne sert qu'à
	// distinguer les nouveaux blocs ENTRE EUX le temps de la session en cours,
	// pas à générer l'id définitif ('widget_N'), qui est attribué côté PHP
	// uniquement à la sauvegarde (cf chtw_sanitize_blocks() dans settings.php).
	
	let newBlockCounter = 0; // on utilise let, cette variable est réassignée à chaque ajout de bloc.

	document.addEventListener( 'DOMContentLoaded', () => { //On s'assure que le DOM est bien chargé

		const blocksList = document.getElementById( 'chtw-blocks-list' );
		const addButton  = document.getElementById( 'chtw-add-block' );
		const template   = document.getElementById( 'chtw-block-template' );

		if ( ! blocksList || ! addButton || ! template ) {
			return; // page pas dans l'état attendu, on n'installe aucun handler
		}

		/* ------------------------------------------------------------
		 * 1. Toggle accordéon (délégation d'événement sur la liste,
		 *    pour couvrir aussi les blocs ajoutés dynamiquement)
		 * ---------------------------------------------------------- */

		blocksList.addEventListener( 'click', ( event ) => { //On met l'écouteur sur toute la liste des blocks, on cible l'élément clilqué et on remonte jusqu'à l'accordéon parent
			const header = event.target.closest( '.chtw-accordion-header' ); //On utilise la méthode .closest() pour cibler l'en-tête de l'accordéon qui a été clilqué
			if ( ! header ) { //Mécanisme défensif - On ne fait rien si header est null
				return;
			}
			// Un clic sur les boutons d'action de l'en-tête (supprimer, monter, descendre) ne doit pas aussi déclencher le toggle de l'accordéon !
			if ( event.target.closest( '.chtw-remove-block, .chtw-move-block-up, .chtw-move-block-down' ) ) {
				return;
			}
			toggleAccordion( header );
		} );

		// Accessibilité clavier : Entrée / Espace sur l'en-tête doit aussi togglé.
		blocksList.addEventListener( 'keydown', ( event ) => {
			const header = event.target.closest( '.chtw-accordion-header' );
			if ( ! header ) {
				return;
			}
			if ( 'Enter' === event.key || ' ' === event.key ) {
				event.preventDefault();
				toggleAccordion( header );
			}
		} );

		function toggleAccordion( header ) {
			const row  = header.closest( '.chtw-accordion' );
			const body = row.querySelector( '.chtw-accordion-body' );
			const icon = header.querySelector( '.chtw-accordion-toggle-icon' );

			const isExpanded = 'true' === header.getAttribute( 'aria-expanded' ); //Subtilité liée à getAttribute() qui renvoie un string et non un booléen ! Le résultat de cette comparaison sera donc un booléen => True si aria-expanded="true" et False dans le cas contraire

			//Toogle de l'accordéon ouvert /fermé
			if ( isExpanded ) {
				header.setAttribute( 'aria-expanded', 'false' );
				body.style.display = 'none';
				if ( icon ) {
					icon.textContent = '▶';
				}
			} else {
				header.setAttribute( 'aria-expanded', 'true' );
				body.style.display = '';
				if ( icon ) {
					icon.textContent = '▼';
				}
			}
		}

		/**
		 * Grise/désactive le bouton "Monter" du tout premier bloc et le bouton
		 * "Descendre" du tout dernier bloc, pour éviter un clic sans effet visible
		 * sur une extrémité de la liste. À rappeler après tout changement d'ordre
		 * (ajout, suppression, déplacement) puisque les extrémités changent.
		 */
		function refreshMoveButtonsState() {
			const rows = blocksList.querySelectorAll( '.chtw-accordion' );

			rows.forEach( ( row, index ) => {
				const upButton   = row.querySelector( '.chtw-move-block-up' );
				const downButton = row.querySelector( '.chtw-move-block-down' );

				if ( upButton ) { //Défensif, on s'assure que upButton n'est pas nul avant d'agir
					upButton.disabled = ( 0 === index ); //On vérifie si l'index de l'élément est le premier du tableau, si c'est le cas on disable le bouton up
				}
				if ( downButton ) { //Défensif, on s'assure que downButton n'est pas nul avant d'agir
					downButton.disabled = ( index === rows.length - 1 ); //On vérifie si l'index de l'élément est le dernier du tableau, si c'est le cas on disable le bouton down
				}
			} );
		}

		// État initial au chargement de la page (blocs déjà présents en PHP).
		refreshMoveButtonsState();

		/* ------------------------------------------------------------
		 * 2. Synchronisation en direct du titre affiché (élément <h3>) et de l'input modifiable
		 * Délégation d'événement, pour couvrir aussi les blocs ajoutés dynamiquement
		 * ---------------------------------------------------------- */

		blocksList.addEventListener( 'input', ( event ) => { //On met l'écouteur sur la liste des blocs pour faire de la délégation d'événements
			if ( ! event.target.classList.contains( 'chtw-block-title-field' ) ) { //Si l'événement de saisie n'est pas dans l'<input> du titre du block
				return; //On en fait rien
			}
			const row     = event.target.closest( '.chtw-accordion' ); //On utilise .closest() pour cibler l'accordéon dans lequel le clic a eu lieu
			const display = row.querySelector( '.chtw-block-title-display' ); //On met l'élément <h3> (title display) dans une variable
			if ( ! display ) { //Si l'élément n'existe pas
				return;
			}
			const value = event.target.value.trim();
			display.textContent = '' !== value ? value : chtwRepeaterData.noTitleLabel; //chtwRepeaterData.noTitleLabel vaut '(Block sans titre)', voir admin/enqueue.php
		} );

		/* ------------------------------------------------------------
		 * 3. Suppression d'un bloc (avec confirmation, délégation d'événement)
		 * ---------------------------------------------------------- */

		blocksList.addEventListener( 'click', ( event ) => { //Délégation d'événement, on met l'événement sur la liste de blocs
			const removeButton = event.target.closest( '.chtw-remove-block' ); //On sélectionne la cible du clic et on s'assure que c'est bien le bouton de suppression
			if ( ! removeButton ) { //Si removeButton est null on ne fait rien
				return;
			}
			event.preventDefault(); //superflu avec la structure HTML actuelle (<button type="button">) mais on le laisse au cas où on voudrait par exemple basculer sur un lien hypertext <a>

			const confirmed = window.confirm( chtwRepeaterData.confirmRemoveLabel ); //On utilise window.confirm() et on passe la chaîne définie dans admin/enqueue.php - window.confirm() est bloquant mais c'est le comportement que l'on veut pour cette action irréversible
			if ( ! confirmed ) { //Early return - Si la confirmation est refusée on en fait rien
				return;
			}

			//La suite s'exécute si l'utilisateur a confirmé
			const row = removeButton.closest( '.chtw-accordion' ); //On utilise .closest() pour remonter sur l'accordéon parent
			if ( row ) {
				row.parentNode.removeChild( row ); //On le supprime
			}
			refreshMoveButtonsState(); //On met à jour l'état des boutons monter /descendre
		} );

		/* ------------------------------------------------------------
		 * 3bis. Réordonnancement d'un bloc (monter / descendre)
		 *
		 * L'ordre d'affichage front suit l'ordre de soumission du tableau
		 * chtw_blocks[] (positionnel, cf chtw_render_block_row()) : réordonner
		 * visuellement le bloc dans le DOM avant soumission suffit donc à
		 * changer son ordre d'affichage, sans logique PHP supplémentaire.
		 * ---------------------------------------------------------- */

		blocksList.addEventListener( 'click', ( event ) => { //Délégation d'événement (pareil que précédemment)

			const moveUpButton   = event.target.closest( '.chtw-move-block-up' ); //on utilise la méthode .closest() pour cibler le bouton "Monter"
			const moveDownButton = event.target.closest( '.chtw-move-block-down' ); //on utilise la méthode .closest() pour cibler le bouton "Descendre"

			if ( ! moveUpButton && ! moveDownButton ) { //Si moveUpButton ET moveDownButton sont null sinon on ne fait rien
				return;
			}
			event.preventDefault(); //superflu avec la structure HTML actuelle (<button type="button">) mais on le laisse au cas où on voudrait par exemple basculer sur un lien hypertext <a>

			// Sécurité : un bouton disabled ne devrait déjà pas déclencher de clic dans la plupart des navigateurs, mais on vérifie explicitement pour éviter tout comportement inattendu (ex: clic synthétique, focus clavier).
			if ( ( moveUpButton && moveUpButton.disabled ) || ( moveDownButton && moveDownButton.disabled ) ) { //Si le bouton existe ET qu'il est disable, on ne fait rien
				return;
			}

			const row = ( moveUpButton || moveDownButton ).closest( '.chtw-accordion' ); //On cible le bloc parent du bouton qui a été cliqué
			if ( ! row ) {
				return;
			}

			if ( moveUpButton ) { //Si le bouton cliqué est "Monter"
				const previousRow = row.previousElementSibling; //on cible le bloc précédent
				if ( previousRow ) { //S'il existe
					blocksList.insertBefore( row, previousRow ); //on met le bloc row au dessus du précédent (action de monter)
				}
			} else {
				const nextRow = row.nextElementSibling; //On cible le bloc suivant
				if ( nextRow ) {
					blocksList.insertBefore( nextRow, row ); //on met le bloc row en dessous du bloc suivant (action de descendre)
				}
			}

			refreshMoveButtonsState(); //On met à jour l'état des boutons Monter /Descendre
		} );

		/* ------------------------------------------------------------
		 * 4. Ajout d'un nouveau bloc (clonage du template)
		 * ---------------------------------------------------------- */

		addButton.addEventListener( 'click', ( event ) => {
			event.preventDefault();

			newBlockCounter += 1;
			const tempId = 'new_' + newBlockCounter;

			const fragment = template.content.cloneNode( true );
			const newRow   = fragment.querySelector( '.chtw-accordion' );

			// Retire le message "Aucun bloc pour le moment" s'il est présent,
			// devenu obsolète dès qu'un premier bloc est ajouté.
			const emptyNotice = blocksList.querySelector( '.chtw-no-blocks' );
			if ( emptyNotice ) {
				emptyNotice.parentNode.removeChild( emptyNotice );
			}

			// Renseigne l'id temporaire dans le champ caché ET dans l'attribut
			// data-block-id (utile pour le futur script Select2/CodeMirror qui
			// aura besoin de cibler ce bloc précisément).
			const idField = newRow.querySelector( '.chtw-block-id-field' );
			if ( idField ) {
				idField.value = tempId;
			}
			newRow.setAttribute( 'data-block-id', tempId );

			// Le nouveau bloc apparaît déjà déplié, contrairement aux blocs
			// existants (repliés par défaut) : l'utilisateur vient de cliquer
			// sur "Ajouter", il s'apprête donc à le remplir immédiatement.
			const header = newRow.querySelector( '.chtw-accordion-header' );
			const body   = newRow.querySelector( '.chtw-accordion-body' );
			if ( header && body ) {
				header.setAttribute( 'aria-expanded', 'true' );
				body.style.display = '';
				const icon = header.querySelector( '.chtw-accordion-toggle-icon' );
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
