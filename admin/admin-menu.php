<?php
/**
 * admin-menu.php
 *
 * Rôle unique de ce fichier : enregistrer l'accès à la page de réglages dans le menu admin/réglages
 * N'importe quelle logique de settings (register_setting, sanitization) ou
 * de rendu de page vit ailleurs (settings.php / settings-page-template.php).
 */

if ( ! defined( 'ABSPATH' ) ) exit; // sécurité : pas d'accès direct au fichier

add_action( 'admin_menu', 'chtw_add_settings_page' ); //Hook la construction de la barre latérale de l'ACP

/**
 * Enregistre la page de settings du plugin sous Réglages > Widgets HTML personnalisés.
 */
function chtw_add_settings_page() {

	$chtw_settings = add_options_page(
		__( 'Widgets HTML personnalisés', 'chtw' ), // Titre affiché dans l'onglet du navigateur / <title>
		__( 'Widgets HTML', 'chtw' ),               // Texte affiché dans le sous-menu "Réglages"
		'manage_options',                           // Capability requise (cohérent avec register_setting dans settings.php)
		'chtw_settings_page',                       // Slug de la page - Peut-être renommer pour éviter d'avoir le slug settings_page_chtw_settings_page ?
		'chtw_render_settings_page'                 // Callback de rendu, défini dans settings-page-template.php
	);

	chtw_store_settings_page_hook_suffix( $chtw_settings ); //Active la fonction d'enregistrement du suffixe de la page settings de chtw
}

/**
 * Stocke le suffixe de la page de settings (retourné par add_options_page()).
 * Usage interne à ce fichier — enqueue.php doit lire cette valeur via chtw_get_settings_page_hook_suffix(), jamais via $GLOBALS directement.
 *
 * @param string $hook_suffix
 */
function chtw_store_settings_page_hook_suffix( $hook_suffix ) {

	$GLOBALS['chtw_settings_page_hook_suffix'] = $hook_suffix;

}

/**
 * Retourne le suffixe de la page de settings.
 * enqueue.php s'en sert pour conditionner wp_enqueue_script/style à cette seule page admin, via le hook 'admin_enqueue_scripts' qui reçoit ce suffixe en paramètre.
 *
 * @return string|false Le suffixe, ou false si la page n'a pas encore été enregistrée.
 */
function chtw_get_settings_page_hook_suffix() {

	return isset( $GLOBALS['chtw_settings_page_hook_suffix'] ) ? $GLOBALS['chtw_settings_page_hook_suffix'] : false;
	
}
