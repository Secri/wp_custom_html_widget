<?php
/**
 * admin-menu.php
 *
 * Rôle unique de ce fichier : enregistrer l'entrée de premier niveau du plugin dans le menu latéral
 * de l'administration. N'importe quelle logique de settings (register_setting, sanitization) ou
 * de rendu de page vit ailleurs (settings.php / settings-page-template.php).
 */

if ( ! defined( 'ABSPATH' ) ) exit; // sécurité : pas d'accès direct au fichier

add_action( 'admin_menu', 'chtw_add_settings_page' ); //Hook la construction de la barre latérale de l'ACP

/**
 * Enregistre la page de settings du plugin comme entrée de premier niveau du menu d'administration.
 *
 * IMPORTANT — conséquence du choix d'un menu de premier niveau : WordPress n'affiche
 * automatiquement les messages de la Settings API que pour les pages placées sous le menu Réglages.
 * Hors de ce menu, cet affichage n'a pas lieu, et c'est l'appel explicite à settings_errors() dans
 * chtw_render_settings_page() qui s'en charge. Cet appel devient donc indispensable : le supprimer
 * ferait disparaître silencieusement tous les messages (blocs ignorés, configuration incomplète,
 * approche de la limite, ainsi que la confirmation d'enregistrement de WordPress).
 */
function chtw_add_settings_page() {

	$chtw_settings = add_menu_page(
		__( 'Widgets HTML personnalisés', 'chtw' ), // Titre affiché dans l'onglet du navigateur / <title>
		__( 'Widgets HTML', 'chtw' ),               // Texte affiché dans le menu latéral
		'manage_options',                           // Capability requise (cohérent avec register_setting dans settings.php)
		'chtw_widgets',                             // Slug de la page
		'chtw_render_settings_page',                // Callback de rendu, défini dans settings-page-template.php
		'dashicons-welcome-widgets-menus',          // Icône du menu (jeu Dashicons intégré à WordPress)
		60.5                                        // Position : juste après Apparence (60), avant Extensions (65)
	);

	chtw_store_settings_page_hook_suffix( $chtw_settings ); //Active la fonction d'enregistrement du suffixe de la page settings de chtw
}

/**
 * Stocke le suffixe de la page de settings (retourné par add_menu_page()).
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
