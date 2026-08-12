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

		/**
		 * Retourne le plus grand index de champ (le N de chtw_blocks[N][...]) actuellement
		 * présent dans la liste, ou -1 si la liste est vide.
		 *
		 * À ne pas confondre avec newBlockCounter ci-dessus : cet index-ci est l'emplacement du
		 * bloc dans le tableau $_POST['chtw_blocks'] et cohabite avec ceux rendus par PHP, alors
		 * que newBlockCounter ne sert qu'à générer un id temporaire ('new_N') propre aux blocs
		 * créés côté client.
		 *
		 * @return {number}
		 */
		function getHighestFieldIndex() {
			let highest = -1;

			blocksList.querySelectorAll( '.chtw-block-id-field' ).forEach( ( field ) => {
				const match = field.name.match( /^chtw_blocks\[(\d+)\]/ ); //On extrait le N de chtw_blocks[N][id]
				if ( match ) {
					highest = Math.max( highest, parseInt( match[ 1 ], 10 ) );
				}
			} );

			return highest;
		}

		// Compteur d'index monotone : on repart au-dessus du plus grand index rendu par PHP et on
		// n'en réutilise jamais un. Un simple décompte des blocs présents ne suffirait pas : après
		// suppression d'un bloc intermédiaire, ce décompte retomberait sur un index encore utilisé
		// et les deux blocs s'écraseraient mutuellement dans $_POST.
		let nextFieldIndex = getHighestFieldIndex() + 1;

		/**
		 * Désactive le bouton "Ajouter un bloc" si le nombre de blocs a atteint
		 * CHTW_MAX_BLOCKS (cf chtwRepeaterData.maxBlocks, injecté depuis
		 * settings.php via enqueue.php), le réactive sinon. Appelée après tout
		 * ajout ou suppression de bloc, puisque le décompte change à chaque fois.
		 * Complète la protection déjà posée côté PHP au chargement de la page
		 * (cf disabled() sur ce même bouton dans settings-page-template.php) et
		 * le rejet côté serveur en cas de contournement (chtw_sanitize_blocks()).
		 */
		function refreshAddButtonState() {
			const currentCount = blocksList.querySelectorAll( '.chtw-accordion' ).length;
			addButton.disabled = currentCount >= chtwRepeaterData.maxBlocks;
		}

		refreshAddButtonState();

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
			refreshAddButtonState(); //On réactive le bouton "Ajouter" si on repasse sous la limite
		} );

		/* ------------------------------------------------------------
		 * 3bis. Réordonnancement d'un bloc (monter / descendre)
		 *
		 * L'ordre d'affichage front suit l'ordre d'apparition des clés dans
		 * $_POST['chtw_blocks'], que le navigateur sérialise dans l'ordre du DOM :
		 * réordonner visuellement le bloc avant soumission suffit donc à changer
		 * son ordre d'affichage, sans logique PHP supplémentaire.
		 *
		 * Les index de champ (chtw_blocks[N][...]) n'ont PAS à être renumérotés ici :
		 * PHP conserve l'ordre d'insertion des clés, pas leur valeur numérique, et
		 * chtw_sanitize_blocks() réindexe de toute façon via $clean_blocks[].
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

		addButton.addEventListener( 'click', ( event ) => { //On cible le bouton pour ajouter un bloc
			event.preventDefault(); //On preventDefault si jamais on change d'élément HTML - Superflu à l'heure actuelle

			// Sécurité : le bouton devrait déjà être disabled à ce stade (cf
			// refreshAddButtonState()), mais on vérifie explicitement, au cas où
			// un clic parviendrait malgré tout (ex: clic synthétique, focus clavier
			// avant que l'état disabled n'ait été appliqué).
			if ( addButton.disabled ) {
				return;
			}

			newBlockCounter += 1; //On utilise un incrément
			const tempId = 'new_' + newBlockCounter; //Pour créer un ID temporaire

			const fragment = template.content.cloneNode( true ); //On duplique le fragment du template du bloc (un fragment n'est PAS un élément HTML)
			const newRow   = fragment.querySelector( '.chtw-accordion' ); //On assigne à newRow l'élément HTML parent qui contient le bloc complet

			// Substitution du placeholder d'index posé par chtw_render_block_row() : les name du
			// template valent 'chtw_blocks[__INDEX__][html]' et doivent devenir 'chtw_blocks[7][html]'.
			// Sans cette étape, tous les blocs ajoutés dans la même session partageraient l'index
			// littéral '__INDEX__' et fusionneraient en un seul élément de tableau côté PHP.
			const fieldIndex = nextFieldIndex;
			nextFieldIndex += 1;

			newRow.querySelectorAll( '[name]' ).forEach( ( field ) => {
				field.name = field.name.replace( '__INDEX__', fieldIndex ); //replace() sans /g : le placeholder n'apparaît qu'une fois par name
			} );

			// Retire le message "Aucun bloc pour le moment" s'il est présent devenu obsolète dès qu'un premier bloc est ajouté.
			const emptyNotice = blocksList.querySelector( '.chtw-no-blocks' );
			if ( emptyNotice ) {
				emptyNotice.parentNode.removeChild( emptyNotice );
			}

			// Renseigne l'id temporaire dans le champ caché ET dans l'attribut data-block-id
			const idField = newRow.querySelector( '.chtw-block-id-field' );
			if ( idField ) {
				idField.value = tempId;
			}
			newRow.setAttribute( 'data-block-id', tempId );

			// Le nouveau bloc apparaît déjà déplié, contrairement aux blocs existants (repliés par défaut) : l'utilisateur vient de cliquer sur "Ajouter", il s'apprête donc à le remplir immédiatement.
			const header = newRow.querySelector( '.chtw-accordion-header' );
			const body   = newRow.querySelector( '.chtw-accordion-body' );
			if ( header && body ) { //On vérifier que header et body ne sont pas null
				header.setAttribute( 'aria-expanded', 'true' ); //On ajoute l'attribut aria-expanded
				body.style.display = ''; //On affiche le corps du bloc
				const icon = header.querySelector( '.chtw-accordion-toggle-icon' ); //On met la bonne icone qui indique que l'accordéon est déplié
				if ( icon ) {
					icon.textContent = '▼';
				}
			}

			blocksList.appendChild( fragment ); //La navigateur "déballe" le contenu du fragment et insère donc l'accordéon dans la liste des blocs - A noter que blocksList.appendChild( newRow ); aurait fonctionné aussi
			refreshMoveButtonsState(); //on met à jour l'état des boutons Monter /Descendre
			refreshAddButtonState(); //On désactive le bouton "Ajouter" si on vient d'atteindre la limite

			// On utilise dispatchEvent() pour créer un événement afin de prévenir les fichiers JS qui gèrent les dépendances (Select2, CodeMirror) qu'un nouveau bloc vient d'être inséré dans le DOM
			document.dispatchEvent( new CustomEvent( 'chtw:block-added', {
				detail: { blockId: tempId, blockElement: newRow }
			} ) );

			// Amène le nouveau bloc dans le champ de vision de l'utilisateur.
			newRow.scrollIntoView( { behavior: 'smooth', block: 'center' } ); //Pas besoin d'utiliser l'option de centrage horizontale
		} );

	} );

} )();
