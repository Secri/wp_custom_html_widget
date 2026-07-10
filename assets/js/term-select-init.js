/**
 * term-select-init.js
 *
 * Rôle unique de ce fichier : initialiser Select2 (avec recherche AJAX paginée) sur les champs .chtw-term-select, à la fois ceux présents au chargement de la page et ceux ajoutés dynamiquement par field-repeater.js
 *
 * Le select des termes est scopé dynamiquement à la taxonomie choisie dans le .chtw-taxonomy-select adjacent (même bloc) : changer de taxonomie vide la sélection de termes en cours et re-scope les recherches suivantes.
 *
 * Dépend de :
 * - jQuery (natif WordPress admin)
 * - Select2 (assets/vendor/select2/, enqueue dans term-select.php)
 * - window.chtwTermSelectData (injecté par wp_localize_script() depuis term-select.php)
 */

( function ( $ ) { // Immediatly Invoked Function Expression qui prend jQuery en paramètre (voir dernière ligne du cript window.jQuery)
	'use strict'; //On active le mode strict de Javascript

	if ( 'undefined' === typeof $ || ! $.fn.select2 || 'undefined' === typeof chtwTermSelectData ) { //Si jQuery n'est pas chargé OU si select2 n'est pas chargé OU si chtwTermSelectData n'est pas envoyé par term-select.php
		return; // Select2 ou ses données de config ne sont pas disponibles, on ne fait rien.
	}

	/**
	 * Initialise Select2 sur un <select> de termes donné, s'il n'est pas déjà
	 * initialisé. La recherche AJAX est scopée à la taxonomie actuellement
	 * choisie dans le .chtw-taxonomy-select du même bloc.
	 *
	 * @param {HTMLSelectElement} selectElement
	 */
	function initSelect2On( selectElement ) {
		const $select = $( selectElement ); //Enveloppe le <select> reçu en paramètre dans un objet jQuery

		if ( $select.hasClass( 'chtw-select2-initialized' ) ) {
			return; // déjà initialisé donc on ne fait rien
		}

		const $row            = $select.closest( '.chtw-accordion' ); //Récupère le premier ancètre
		const $taxonomySelect = $row.find( '.chtw-taxonomy-select' ); //On target le <select> de la taxonomie (PAS le <select> des termes !)

		$select.select2( { //Options de paramétrage du Select2
			width: '100%',
			placeholder: chtwTermSelectData.searchPlaceholder,
			allowClear: true,
			minimumInputLength: 0, // liste initiale visible dès l'ouverture, avant même de taper une recherche
			ajax: {
				url: chtwTermSelectData.ajaxUrl, //défini dans term-select.php et envoyée via wp_localize-script - la propriété ajaxUrl stocke l'endpoint vers lequel on envoie la requête
				dataType: 'json',
				delay: 250, // en milliseconde pour ne pas envoyer de requête à chaque frappe
				data: ( params ) => ( {
					action: 'chtw_search_terms', //Appelle le hook wp_ajax_chtw_search_terms (term-select.php) et execute la fonction chtw_handle_term_search_request()
					nonce: chtwTermSelectData.nonce, //Le nonce contenu dans la variable globale
					taxonomy: $taxonomySelect.val(), //la taxonomie sélectionnée dans le bloc
					q: params.term || '', //le texte tapé
					page: params.page || 1
				} ),
				processResults: ( response ) => {
					if ( ! response?.success ) { //opérateur de chaïnage optionnel, assigne success si response n'est ni null ni undefinded
						return { results: [] };
					}
					return response.data; // au format { results: [...], pagination: { more } } attendu par Select2
				},
				cache: true
			}
		} );

		$select.addClass( 'chtw-select2-initialized' ); //On ajouter cette classe pour identifier les elements pour lesquels le Select2 est activé

		// Tant qu'aucune taxonomie n'est choisie, le select de termes reste
		// désactivé : chercher un terme sans savoir dans quelle taxonomie
		// n'aurait pas de sens (cf handler AJAX, qui exige une taxonomie valide).
		toggleTermSelectAvailability( $taxonomySelect, $select );

		// Changement de taxonomie : on vide la sélection de termes en cours
		// (les term_ids précédents n'ont probablement plus de sens dans une
		// autre taxonomie) et on ajuste la disponibilité du select.
		$taxonomySelect.on( 'change', () => {
			$select.val( null ).trigger( 'change' );
			toggleTermSelectAvailability( $taxonomySelect, $select );
		} );
	}

	/**
	 * Active ou désactive le select de termes selon qu'une taxonomie est
	 * actuellement choisie ou non dans le select adjacent.
	 *
	 * @param {jQuery} $taxonomySelect
	 * @param {jQuery} $termSelect
	 */
	function toggleTermSelectAvailability( $taxonomySelect, $termSelect ) {
		const hasTaxonomy = '' !== $taxonomySelect.val();
		$termSelect.prop( 'disabled', ! hasTaxonomy );
	}

	$( function () {

		// 1. Blocs déjà présents au chargement de la page.
		// Note : function() classique volontairement conservée ici (pas de
		// fonction fléchée) — jQuery .each() lie 'this' à l'élément DOM courant
		// de l'itération, ce que initSelect2On(this) exploite directement. Une
		// fonction fléchée n'aurait pas son propre 'this' et casserait cet appel.
		$( '.chtw-term-select' ).each( function () {
			initSelect2On( this );
		} );

		// 2. Nouveaux blocs ajoutés dynamiquement par field-repeater.js.
		document.addEventListener( 'chtw:block-added', ( event ) => {
			const blockElement = event.detail?.blockElement;
			if ( ! blockElement ) {
				return;
			}
			const termSelect = blockElement.querySelector( '.chtw-term-select' );
			if ( termSelect ) {
				initSelect2On( termSelect );
			}
		} );

	} );

} )( window.jQuery );
