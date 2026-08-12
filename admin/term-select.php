<?php
/**
 * term-select.php
 *
 * Rôle unique de ce fichier : tout ce qui concerne le champ de sélection de
 * termes (.chtw-term-select) — enqueue de Select2 (embarqué localement,
 * dépendance jQuery native de WordPress) et handler AJAX de recherche de
 * termes par taxonomie.
 *
 * Select2 n'a pas d'API stable officielle dans WordPress core (contrairement
 * à CodeMirror) : il est donc embarqué en local dans le plugin plutôt que
 * chargé depuis un CDN ou supposé "déjà présent" (cf discussion architecture
 * — risque de conflit avec WooCommerce/ACF qui embarquent chacun leur propre
 * copie/fork de Select2).
 *
 * La recherche se fait en AJAX avec pagination (scroll infini Select2) :
 * aucun terme n'est préchargé côté PHP au rendu de la page (cf
 * chtw_render_block_row() dans settings-page-template.php, qui ne pré-remplit
 * que les termes déjà sélectionnés) — scalable même avec un grand nombre de
 * taxonomies/termes.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // sécurité : pas d'accès direct au fichier

add_action( 'admin_enqueue_scripts', 'chtw_enqueue_term_select' );
add_action( 'wp_ajax_chtw_search_terms', 'chtw_handle_term_search_request' ); //Sera déclenché par admin-ajax.php lorsqu'un fichier JS enverra le paramètre action chtw_search_terms

/**
 * Enqueue Select2 (JS + CSS) et le script d'init dédié, uniquement sur la page de settings du plugin.
 *
 * @param string $hook_suffix Fourni par WordPress : le hook suffix de la page admin courante.
 */
function chtw_enqueue_term_select( $hook_suffix ) {

	if ( $hook_suffix !== chtw_get_settings_page_hook_suffix() ) {
		return; // on n'est pas sur la page de settings du plugin, on charge rien
	}

	wp_enqueue_style(
		'chtw-select2',
		CHTW_PLUGIN_URL . 'assets/vendor/select2/css/select2.min.css', //Librairie CSS Select2
		array(),
		chtw_asset_version( 'assets/vendor/select2/css/select2.min.css' ) //cache busting
	);

	wp_enqueue_script(
		'chtw-select2',
		CHTW_PLUGIN_URL . 'assets/vendor/select2/js/select2.min.js', //Librairie JS Select2
		array( 'jquery' ), // Select2 dépend de jQuery
		chtw_asset_version( 'assets/vendor/select2/js/select2.min.js' ), //cache busting
		true
	);

	wp_enqueue_script(
		'chtw-term-select-init',
		CHTW_PLUGIN_URL . 'assets/js/term-select-init.js',
		array( 'jquery', 'chtw-select2', 'chtw-field-repeater' ), // dépend de Select2 et de l'événement 'chtw:block-added'
		chtw_asset_version( 'assets/js/term-select-init.js' ),
		true
	);

	wp_localize_script(
		'chtw-term-select-init',
		'chtwTermSelectData',
		array(
			'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
			'nonce'             => wp_create_nonce( 'chtw_search_terms' ),
			'searchPlaceholder' => __( 'Sélectionner un terme', 'chtw' ),
			'errorLoadingLabel' => __( 'Impossible de charger les termes. Vérifiez votre connexion et réessayez.', 'chtw' ),
		)
	);
}

/**
 * Handler AJAX : recherche des termes dans une taxonomie donnée avec pagination. Répond au format attendu nativement par la config `ajax` de Select2 (results[] + pagination.more).
 *
 * Paramètres attendus en $_POST :
 * - taxonomy (string) : slug de la taxonomie à interroger.
 * - q        (string, optionnel) : terme de recherche. Absent/vide = les premiers termes triés par nom.
 * - page     (int, optionnel, défaut 1) : page de résultats demandée par Select2 lors du scroll infini.
 */
function chtw_handle_term_search_request() {

	check_ajax_referer( 'chtw_search_terms', 'nonce' ); //Vérification du nonce = sécurité

	if ( ! current_user_can( 'manage_options' ) ) { //Sécurité n°2 : on vérifie que l'utilisateur a bien les droits d'administration nécessaires
		wp_send_json_error( array( 'message' => __( 'Action non autorisée.', 'chtw' ) ), 403 );
	}

	//Récupération et nettoyage des paramètres reçus : on sanitize et on unslash
	$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
	$search   = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
	$page     = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;

	if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) { //On refuse de continuer si la taxonomie n'existe pas
		wp_send_json_error( array( 'message' => __( 'Taxonomie invalide.', 'chtw' ) ), 400 ); 
	}

	$args = array( //Construction des arguments de recherche
		'taxonomy'   => $taxonomy,
		'hide_empty' => false, // un terme peut être choisi comme cible même s'il n'a encore aucun contenu associé
		// On demande volontairement UN terme de plus que la taille de page : sa présence (ou son
		// absence) dans le résultat suffit à renseigner pagination.more, dont le scroll infini de
		// Select2 n'a besoin que comme booléen. Ce terme excédentaire est retiré avant l'envoi de
		// la réponse — il ne sert qu'à sonder l'existence d'une page suivante, en une seule requête
		// au lieu de deux (l'endpoint est sollicité à chaque frappe et à chaque cran de scroll).
		'number'     => CHTW_TERM_SEARCH_PER_PAGE + 1,
		'offset'     => ( $page - 1 ) * CHTW_TERM_SEARCH_PER_PAGE, //Page 1 => offset 0 => les 20 premiers résultat | Page 2 => offset 20 => les 20 suivants
		'orderby'    => 'name', //Tri en fonction du libellé
		'order'      => 'ASC', //Ordre alphabétique
	);

	if ( '' !== $search ) { //Seulement si une recherche est faite
		$args['name__like'] = $search; // recherche partielle insensible à la casse, gérée nativement par get_terms()
	}

	$terms = get_terms( $args );

	if ( is_wp_error( $terms ) ) {
		wp_send_json_error( array( 'message' => $terms->get_error_message() ), 500 );
	}

	// Ordre impératif : on teste d'abord, on tronque ensuite. Inverser les deux ferait
	// systématiquement retomber $has_more à false et couperait le scroll infini.
	$has_more = count( $terms ) > CHTW_TERM_SEARCH_PER_PAGE;
	$terms    = array_slice( $terms, 0, CHTW_TERM_SEARCH_PER_PAGE ); //On écarte le terme excédentaire, qui n'a jamais eu vocation à être affiché

	$results = array();

	foreach ( $terms as $term ) {
		$results[] = array(
			'id'   => $term->term_id,
			'text' => $term->name,
		);
	}

	wp_send_json_success( array(
		'results'    => $results,
		'pagination' => array(
			'more' => $has_more,
		),
	) );
}
