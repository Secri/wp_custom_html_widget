/**
 * term-select-init.js
 *
 * Rôle unique de ce fichier : initialiser Select2 (avec recherche AJAX
 * paginée) sur les champs .chtw-term-select, à la fois ceux présents au
 * chargement de la page et ceux ajoutés dynamiquement par field-repeater.js
 * (via l'événement custom 'chtw:block-added').
 *
 * Le select des termes est scopé dynamiquement à la taxonomie choisie dans
 * le .chtw-taxonomy-select adjacent (même bloc) : changer de taxonomie vide
 * la sélection de termes en cours et re-scope les recherches suivantes.
 *
 * Dépend de :
 * - jQuery (natif WordPress admin)
 * - Select2 (assets/vendor/select2/, enqueue dans term-select.php)
 * - window.chtwTermSelectData (injecté par wp_localize_script() dans term-select.php)
 */

( function ( $ ) {
	'use strict';

	if ( 'undefined' === typeof $ || ! $.fn.select2 || 'undefined' === typeof chtwTermSelectData ) {
		return; // Select2 ou ses données de config ne sont pas disponibles
	}

	/**
	 * Initialise Select2 sur un <select> de termes donné, s'il n'est pas déjà
	 * initialisé. La recherche AJAX est scopée à la taxonomie actuellement
	 * choisie dans le .chtw-taxonomy-select du même bloc.
	 *
	 * @param {HTMLSelectElement} selectElement
	 */
	function initSelect2On( selectElement ) {
		const $select = $( selectElement );

		if ( $select.hasClass( 'chtw-select2-initialized' ) ) {
			return; // déjà initialisé
		}

		const $row            = $select.closest( '.chtw-accordion' );
		const $taxonomySelect = $row.find( '.chtw-taxonomy-select' );

		$select.select2( {
			width: '100%',
			placeholder: chtwTermSelectData.searchPlaceholder,
			allowClear: true,
			minimumInputLength: 0, // liste initiale visible dès l'ouverture, avant même de taper une recherche
			ajax: {
				url: chtwTermSelectData.ajaxUrl,
				dataType: 'json',
				delay: 250, // évite une requête à chaque frappe, attend une courte pause
				data: ( params ) => ( {
					action: 'chtw_search_terms',
					nonce: chtwTermSelectData.nonce,
					taxonomy: $taxonomySelect.val(),
					q: params.term || '',
					page: params.page || 1
				} ),
				processResults: ( response ) => {
					if ( ! response?.success ) {
						return { results: [] };
					}
					return response.data; // déjà au format { results: [...], pagination: { more } } attendu par Select2
				},
				cache: true
			}
		} );

		$select.addClass( 'chtw-select2-initialized' );

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
