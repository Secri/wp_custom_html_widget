<?php
/**
 * Rôle unique de ce fichier : centraliser tous les mises dans la file des scripts JS et CSS du plugin côté admin (field-repeater.js, admin-style.css, Select2, CodeMirror). 
 *
 * Tout est conditionné à la page de settings du plugin (via le hook suffix exposé par admin-menu.php), pour ne pas alourdir le reste du back-office Wordpress.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // sécurité : pas d'accès direct au fichier

add_action( 'admin_enqueue_scripts', 'chtw_enqueue_admin_assets' );

/**
 * Charge les scripts et styles du plugin, uniquement sur sa propre page de settings.
 *
 * @param string $hook_suffix - retourné par Wordpress : le suffixe de la page admin courante.
 */
function chtw_enqueue_admin_assets( $hook_suffix ) {

	if ( $hook_suffix !== chtw_get_settings_page_hook_suffix() ) {
		return; // on n'est pas sur la page de settings du plugin, on ne charge rien
	}

	/* 
	 * ------------------------------------------------------------------
	 * CSS
	 * ------------------------------------------------------------------ 
	 */

	wp_enqueue_style(
		'chtw-admin-style',
		plugin_dir_url( __DIR__ ) . 'assets/css/admin-style.css',
		array(),
		filemtime( plugin_dir_path( __DIR__ ) . 'assets/css/admin-style.css' ) //Timestamp de la dernière modif du fichier en tant que paramètre pour $version
	);

	/* 
	 * --------------------------------------------------------------------
	 * JS des settings (mécanique accordéon / ajout / suppression de blocs)
	 * --------------------------------------------------------------------
	 */

	wp_enqueue_script(
		'chtw-field-repeater',
		plugin_dir_url( __DIR__ ) . 'assets/js/field-repeater.js',
		array(), // vanilla JS, aucune dépendance
		filemtime( plugin_dir_path( __DIR__ ) . 'assets/js/field-repeater.js' ), //Timestamp de la dernière modif du fichier en tant que paramètre pour $version
		true // chargé en pied de page
	);

	// Transmission des labels vers field-repeater.js dans un objectif de traduction
	wp_localize_script(
		'chtw-field-repeater', 
		'chtwRepeaterData', //Nom de la variable globale qui sera envoyée au fichier JS
		array( //Contenu de la variable chtwRepeaterData
			'noTitleLabel'       => __( '(Bloc sans titre)', 'chtw' ),
			'confirmRemoveLabel' => __( 'Supprimer ce bloc ? Cette action est irréversible une fois les modifications enregistrées.', 'chtw' ),
		)
	);

}
