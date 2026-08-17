/**
 * field-repeater.js - Vanilla JS, sans dépendance jQuery ni autre bibliothèque
 *
 * Rôle de ce fichier : mécanique JS du repeater de blocs côté admin.
 * - Toggle accordéon (ouvrir /fermer un bloc)
 * - Ajout d'un nouveau bloc (clonage du <template>, id temporaire unique)
 * - Suppression d'un bloc (avec confirmation)
 * - Synchronisation en direct du titre affiché dans l'en-tête d'accordéon avec l'<input> éditable
 *
 * L'initialisation de Select2 et de CodeMirror sur les champs nouvellement clonés est déclenchée via un événement custom ('chtw:block-added'), auquel code-editor.php et term-select.php (via les fichiers JS associés) s'abonnent indépendamment.
 */

( function () {
	'use strict';

	// Compteur local, purement côté client, pour générer des id temporaires uniques ('new_1', 'new_2'...) sur les blocs fraîchement ajoutés. 
	// Il repart de zéro à chaque chargement de page : il ne sert qu'à distinguer les nouveaux blocs ENTRE EUX le temps de la session en cours.
	// L'id définitif ('widget_N') est attribué côté PHP uniquement à la sauvegarde (cf chtw_sanitize_blocks() dans settings.php).
	
	let newBlockCounter = 0; // Variable est réassignée à chaque ajout de bloc.

	/**
	 * Libellés (titre par défaut et msg de confirmation) injectés par wp_localize_script() (cf admin/enqueue.php) sous forme d'un tableau chtwReapeaterData en variable globale.
	 *
	 * Note : wp_localize_script() imprime un <script> INLINE, celui-ci peut ne pas se charger pour diverses raisons :  
	 * Content-Security-Policy stricte bloquant l'inline, plugin d'optimisation qui déplace les scripts, problème avec wp_enqueue_script()...
	 *
	 * Sans repli, la première lecture de chtwRepeaterData lèverait une ReferenceError au beau milieu de l'initialisation : 
	 * tous les écouteurs enregistrés à la suite ne le seraient jamais, et l'écran se retrouverait à moitié fonctionnel : ni ajout, ni suppression, ni réordonnancement.
	 *
	 */
	const repeaterData = window.chtwRepeaterData || {}; // Défensif - repeaterData sera undefined si le script ne trouve pas chtwRepeaterData au lieu de lever une ReferenceError + on substitue un objet vide qui neutralise les erreurs de type sur les propriétés de repeaterData

	// Gestion des labels avec valeurs de repli en dur
	const labels = {
		noTitle:       repeaterData.noTitleLabel || '(Bloc sans titre)', 
		confirmRemove: repeaterData.confirmRemoveLabel || 'Supprimer ce bloc ? Cette action sera irréversible une fois les modifications enregistrées.',
	};

	document.addEventListener( 'DOMContentLoaded', () => { //On s'assure que le DOM est bien chargé

		const blocksList = document.getElementById( 'chtw-blocks-list' ); // On récupère la liste de tous les blocs existants au chargement - IMPORTANT parce que c'est sur cette liste qu'on va ajouter tous les écouteurs !
		const addButton  = document.getElementById( 'chtw-add-block' );
		const template   = document.getElementById( 'chtw-block-template' ); // On va chercher <template id="chtw-bloc-template"> qu'on utilise pour créer les nouveaux blocs

		if ( ! blocksList || ! addButton || ! template ) {
			return; // page pas dans l'état attendu, on n'installe aucun handler
		}

		/* ------------------------------------------------------------
		 * 1. Toggle accordéon (délégation d'événement sur la liste,
		 *    pour couvrir aussi les blocs ajoutés dynamiquement)
		 * ---------------------------------------------------------- */

		// La zone de bascule est un <button> frère des boutons d'action, et non plus l'en-tête
		// entier qui les contenait. Deux conséquences : plus besoin d'exclure ces boutons du
		// toggle (ils ne sont plus dans la zone cliquable), et plus besoin d'un handler 'keydown'
		// dédié — le navigateur déclenche nativement un 'click' sur Entrée et Espace pour un
		// <button>. Un seul écouteur remplace donc les deux précédents.
		blocksList.addEventListener( 'click', ( event ) => { //On met l'écouteur sur toute la liste des blocs, on cible l'élément cliqué et on remonte jusqu'au bouton de bascule
			const toggle = event.target.closest( '.chtw-accordion-toggle' ); //On utilise la méthode .closest() car le clic peut atterrir sur l'icône ou sur le titre, tous deux enfants du bouton
			if ( ! toggle ) { //Mécanisme défensif - On ne fait rien si le clic n'a pas eu lieu dans un bouton de bascule
				return;
			}
			toggleAccordion( toggle );
		} );

		/**
		 * Aligne l'icône du bouton de bascule sur l'état ouvert / fermé du bloc.
		 *
		 * L'icône est un dashicon : le glyphe n'est PAS le contenu texte de l'élément, il est injecté
		 * par la classe dashicons-* via un pseudo-élément ::before. On échange donc les CLASSES, et
		 * surtout pas le textContent — écrire dedans laisserait le pseudo-élément en place et
		 * ajouterait du texte à côté du glyphe.
		 *
		 * @param {HTMLElement} toggle     Le bouton de bascule du bloc.
		 * @param {boolean}     isExpanded État visé.
		 */
		function refreshToggleIcon( toggle, isExpanded ) {
			const icon = toggle.querySelector( '.chtw-accordion-toggle-icon' );

			if ( ! icon ) { //Mécanisme défensif - on ne présume pas de la présence de l'icône
				return;
			}

			icon.classList.toggle( 'dashicons-minus', isExpanded ); //Pour lecond argument de toggle() on passe un booléen : force l'ajout si true, le retrait si false
			icon.classList.toggle( 'dashicons-plus', ! isExpanded );
		}

		function toggleAccordion( toggle ) {
			const row  = toggle.closest( '.chtw-accordion' );
			const body = row.querySelector( '.chtw-accordion-body' );

			const isExpanded = 'true' === toggle.getAttribute( 'aria-expanded' ); //Subtilité liée à getAttribute() qui renvoie un string et non un booléen ! Le résultat de cette comparaison sera donc un booléen => True si aria-expanded="true" et False dans le cas contraire

			//Toogle de l'accordéon ouvert /fermé
			if ( isExpanded ) {
				toggle.setAttribute( 'aria-expanded', 'false' );
				body.style.display = 'none';
				refreshToggleIcon( toggle, false );
			} else {
				toggle.setAttribute( 'aria-expanded', 'true' );
				body.style.display = 'block'; //'block' et NON '' : une chaîne vide RETIRE la déclaration inline, ce qui laisse s'appliquer le display:none de la feuille de style (cf .chtw-accordion-body dans admin-style.css) — le bloc resterait invisible
				refreshToggleIcon( toggle, true );

				// Le bloc vient de devenir visible : on prévient les scripts qui greffent des
				// composants ayant besoin de mesurer leurs dimensions (CodeMirror se rend avec une
				// hauteur nulle s'il est initialisé dans un conteneur en display:none). Comme pour
				// 'chtw:block-added', ce fichier ignore qui écoute — il se contente de signaler.
				document.dispatchEvent( new CustomEvent( 'chtw:block-expanded', {
					detail: { blockElement: row }
				} ) );
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
		 * Active ou désactive la case "Inclure tous les enfants" d'un bloc selon que la taxonomie
		 * sélectionnée est hiérarchique.
		 *
		 * Sur une taxonomie plate (étiquettes et assimilées), l'option est inerte : get_term_children()
		 * s'appuie sur _get_term_hierarchy(), qui retourne un tableau vide dans ce cas. La case ne
		 * ferait donc que promettre un comportement qui n'arrivera jamais.
		 *
		 * Elle est décochée EN PLUS d'être désactivée. Sans cela, une case cochée sur une taxonomie
		 * plate — donc sans effet — resterait cochée après bascule vers une taxonomie hiérarchique,
		 * et étendrait silencieusement le ciblage à toute la descendance sans que personne ne l'ait
		 * décidé (le changement de taxonomie réinitialise les termes, mais pas cette case).
		 * L'attribut disabled empêche par ailleurs toute soumission : côté PHP, une clé absente et
		 * une case décochée sont traitées à l'identique par ! empty().
		 *
		 * @param {HTMLElement} row Le bloc concerné.
		 */
		function refreshIncludeChildrenState( row ) {
			const taxonomySelect = row.querySelector( '.chtw-taxonomy-select' );
			const label          = row.querySelector( '.chtw-include-children-label' );
			const checkbox       = row.querySelector( '.chtw-include-children-checkbox' );

			if ( ! taxonomySelect || ! label || ! checkbox ) { //Mécanisme défensif - on ne présume pas de la présence des trois éléments
				return;
			}

			const selectedOption = taxonomySelect.options[ taxonomySelect.selectedIndex ];
			const isHierarchical = '1' === selectedOption?.dataset.hierarchical; //Chaînage optionnel : selectedIndex vaut -1 si aucune option n'est sélectionnée

			if ( isHierarchical ) {
				checkbox.disabled = false;
				label.classList.remove( 'chtw-option-unavailable' );
				return; // on ne touche pas à checked : l'utilisateur retrouve son réglage tel quel
			}

			checkbox.checked  = false;
			checkbox.disabled = true;
			label.classList.add( 'chtw-option-unavailable' );
		}

		// État initial des blocs rendus par PHP.
		blocksList.querySelectorAll( '.chtw-accordion' ).forEach( refreshIncludeChildrenState );

		// Le select de taxonomie est un <select> natif — seul celui des termes passe par Select2 —
		// un écouteur classique suffit donc, sans dépendance à jQuery.
		blocksList.addEventListener( 'change', ( event ) => {
			const taxonomySelect = event.target.closest( '.chtw-taxonomy-select' );
			if ( ! taxonomySelect ) {
				return;
			}
			refreshIncludeChildrenState( taxonomySelect.closest( '.chtw-accordion' ) );
		} );

		/**
		 * Affiche ou masque le message "Aucun bloc pour le moment" selon que la liste est vide.
		 *
		 * On bascule l'attribut hidden plutôt que de retirer le paragraphe du DOM : détruire le
		 * nœud rendrait le message irrécupérable après suppression du dernier bloc, sauf à
		 * dupliquer sa chaîne traduite côté JS. Appelée aux mêmes moments que refreshMoveButtonsState()
		 * et refreshAddButtonState(), c'est-à-dire après tout ajout ou suppression de bloc.
		 */
		function refreshEmptyState() {
			const notice = blocksList.querySelector( '.chtw-no-blocks' );
			if ( ! notice ) { //Mécanisme défensif - le paragraphe est rendu par PHP, mais on ne présume pas de sa présence
				return;
			}
			notice.hidden = blocksList.querySelectorAll( '.chtw-accordion' ).length > 0;
		}

		refreshEmptyState();

		/**
		 * Retourne la limite de blocs (CHTW_MAX_BLOCKS), lue sur l'attribut data-max-blocks du
		 * bouton "Ajouter un bloc".
		 *
		 * Volontairement lue dans le DOM plutôt que dans l'objet localisé : c'est le même document
		 * que ce fichier manipule déjà, donc un canal qui ne peut pas disparaître indépendamment de
		 * lui. La source de vérité reste unique — la constante PHP, rendue dans l'attribut par
		 * settings-page-template.php.
		 *
		 * Repli à 0 si l'attribut est absent ou illisible : le bouton reste alors désactivé en
		 * permanence. C'est délibérément le repli le plus restrictif, parce que l'erreur inverse
		 * coûte cher — au-delà de la limite, chtw_sanitize_blocks() rejette la soumission ENTIÈRE
		 * et l'utilisateur perd toute sa saisie. Mieux vaut ne pas pouvoir ajouter de bloc que
		 * d'en saisir soixante pour les perdre à l'enregistrement.
		 *
		 * @return {number}
		 */
		function getMaxBlocks() {
			const raw = parseInt( addButton.dataset.maxBlocks, 10 );
			return Number.isNaN( raw ) ? 0 : raw;
		}

		/**
		 * Désactive le bouton "Ajouter un bloc" si le nombre de blocs a atteint
		 * CHTW_MAX_BLOCKS, le réactive sinon. Appelée après tout ajout ou
		 * suppression de bloc, puisque le décompte change à chaque fois.
		 * Complète la protection déjà posée côté PHP au chargement de la page
		 * (cf disabled() sur ce même bouton dans settings-page-template.php) et
		 * le rejet côté serveur en cas de contournement (chtw_sanitize_blocks()).
		 */
		function refreshAddButtonState() {
			const currentCount = blocksList.querySelectorAll( '.chtw-accordion' ).length;
			addButton.disabled = currentCount >= getMaxBlocks();
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
			const display = row.querySelector( '.chtw-block-title-display' ); //On met l'élément <span> qui affiche le titre dans l'en-tête dans une variable
			if ( ! display ) { //Si l'élément n'existe pas
				return;
			}
			const value = event.target.value.trim();
			display.textContent = '' !== value ? value : labels.noTitle; //Libellé traduit injecté par admin/enqueue.php, avec repli si l'objet localisé est absent
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

			const confirmed = window.confirm( labels.confirmRemove ); //On utilise window.confirm() et on passe la chaîne définie dans admin/enqueue.php - window.confirm() est bloquant mais c'est le comportement que l'on veut pour cette action irréversible
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
			refreshEmptyState(); //On réaffiche le message d'invite si on vient de supprimer le dernier bloc
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

			// Les deux branches déplacent le MÊME nœud, celui du bloc cliqué. C'est ce qui garantit
			// que le flash ne concerne que lui : insertBefore() sur un nœud déjà présent équivaut à
			// un retrait suivi d'une réinsertion, et réinsérer un élément dans le document relance
			// ses animations CSS. Faire descendre un bloc en remontant son voisin — ce qui produit
			// pourtant le bon ordre final — rejouait donc le flash de ce voisin s'il portait encore
			// la classe d'un déplacement précédent.
			if ( moveUpButton ) { //Si le bouton cliqué est "Monter"
				const previousRow = row.previousElementSibling; //on cible le bloc précédent
				if ( previousRow ) { //S'il existe
					blocksList.insertBefore( row, previousRow ); //on insère le bloc juste avant le précédent (action de monter)
				}
			} else {
				const nextRow = row.nextElementSibling; //On cible le bloc suivant
				if ( nextRow ) {
					blocksList.insertBefore( row, nextRow.nextElementSibling ); //on insère le bloc juste après le suivant (action de descendre). Si le suivant est le dernier, nextElementSibling vaut null et insertBefore() ajoute alors en fin de liste — comportement voulu.
				}
			}

			refreshMoveButtonsState(); //On met à jour l'état des boutons Monter /Descendre

			// Retour visuel sur l'action réussie : l'échange est instantané et, quand les blocs sont
			// repliés, rien ne permet de constater qu'il a eu lieu — a fortiori si deux blocs portent
			// le même titre. Le flash répond à la seule question que se pose l'utilisateur : lequel
			// vient de bouger ?
			//
			// Le retrait de la classe suivi de la lecture de offsetWidth n'est pas superflu : sans
			// cette lecture, le navigateur regrouperait le retrait et l'ajout dans la même passe et
			// ne verrait aucun changement net, donc ne rejouerait pas l'animation. Lire une propriété
			// géométrique le force à recalculer la mise en page immédiatement, ce qui rend le retrait
			// effectif avant l'ajout. Indispensable pour les clics successifs — on déplace rarement
			// un bloc d'un seul cran.
			row.classList.remove( 'chtw-block-moved' );
			void row.offsetWidth; // eslint-disable-line no-void -- reflow forcé volontaire, cf commentaire ci-dessus
			row.classList.add( 'chtw-block-moved' );

			// insertBefore() détache puis rattache le nœud : un composant déjà rendu à l'intérieur
			// (CodeMirror) peut avoir besoin de se remesurer. On signale, libre aux abonnés d'agir.
			document.dispatchEvent( new CustomEvent( 'chtw:block-moved', {
				detail: { blockElement: row }
			} ) );
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

			// Renseigne l'id temporaire dans le champ caché ET dans l'attribut data-block-id
			const idField = newRow.querySelector( '.chtw-block-id-field' );
			if ( idField ) {
				idField.value = tempId;
			}
			newRow.setAttribute( 'data-block-id', tempId );

			// Le nouveau bloc apparaît déjà déplié, contrairement aux blocs existants (repliés par défaut) : l'utilisateur vient de cliquer sur "Ajouter", il s'apprête donc à le remplir immédiatement.
			const toggle = newRow.querySelector( '.chtw-accordion-toggle' );
			const body   = newRow.querySelector( '.chtw-accordion-body' );
			if ( toggle && body ) { //On vérifie que toggle et body ne sont pas null
				// L'id du corps et l'aria-controls du bouton portent encore le placeholder '__INDEX__'
				// hérité du template : on les réaligne sur l'index réel, sinon tous les blocs ajoutés
				// dans la même session partageraient le même id HTML.
				body.id = 'chtw-accordion-body-' + fieldIndex;
				toggle.setAttribute( 'aria-controls', body.id );

				toggle.setAttribute( 'aria-expanded', 'true' ); //On signale que l'accordéon est déplié
				body.style.display = 'block'; //'block' et NON '' : une chaîne vide RETIRE la déclaration inline, ce qui laisse s'appliquer le display:none de la feuille de style (cf .chtw-accordion-body dans admin-style.css) — le bloc resterait invisible
				refreshToggleIcon( toggle, true ); //On met la bonne icône en indiquant que l'accordéon est déplié avec true
			}

			blocksList.appendChild( fragment ); //La navigateur "déballe" le contenu du fragment et insère donc l'accordéon dans la liste des blocs - A noter que blocksList.appendChild( newRow ); aurait fonctionné aussi
			refreshMoveButtonsState(); //on met à jour l'état des boutons Monter /Descendre
			refreshAddButtonState(); //On désactive le bouton "Ajouter" si on vient d'atteindre la limite
			refreshEmptyState(); //On masque le message d'invite, devenu obsolète dès le premier bloc
			refreshIncludeChildrenState( newRow ); //Aucune taxonomie n'est encore choisie : la case part désactivée

			// On utilise dispatchEvent() pour créer un événement afin de prévenir les fichiers JS qui gèrent les dépendances (Select2, CodeMirror) qu'un nouveau bloc vient d'être inséré dans le DOM
			document.dispatchEvent( new CustomEvent( 'chtw:block-added', {
				detail: { blockId: tempId, blockElement: newRow }
			} ) );

			// Amène le nouveau bloc dans le champ de vision de l'utilisateur.
			newRow.scrollIntoView( { behavior: 'smooth', block: 'center' } ); //Pas besoin d'utiliser l'option de centrage horizontale
		} );

	} );

} )();
