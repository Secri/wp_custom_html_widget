<?php
/**
 * Plugin Name: WP Custom HTML Widget
 * Plugin URI: https://github.com/Secri/wp_custom_html_widget/
 * Description: Création de blocs HTML personnalisés injectables par taxonomies dans les zones de widgets
 * Version: 0.1.0
 * Author: Christophe IENZER
 * Author URI: https://www.linkedin.com/in/christophe-ienzer
 * Text Domain: chtw
 */

if ( ! defined( 'ABSPATH' ) ) exit; // sécurité : pas d'accès direct au fichier

/* ------------------------------------------------------------------------
 * Fichiers includes/ (partagés admin et /ou front)
 *
 * Chargés inconditionnellement, et AVANT admin/ : data.php définit chtw_get_blocks(), utilisée
 * aussi bien par front-rendering.php que par les écrans d'administration.
 *
 * front-rendering.php doit rester hors de toute condition : register_widget() est nécessaire en
 * front pour l'affichage, mais aussi dans l'administration pour l'écran des widgets.
 * ---------------------------------------------------------------------- */

require_once plugin_dir_path( __FILE__ ) . 'includes/data.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/taxonomy-matcher.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/front-rendering.php';

/* ------------------------------------------------------------------------
 * Fichiers admin/ (back-office uniquement)
 *
 * Ordre de chargement :
 *
 * 1 - admin-menu.php (définit chtw_get_settings_page_hook_suffix() utilisée par enqueue.php, code-editor.php et term-select.php)
 * 2 - settings.php (définit les constantes CHTW_* utilisées par settings-page-template.php et term-select.php)
 *
 * is_admin() couvre les écrans d'administration ET admin-ajax.php : l'endpoint de recherche de
 * termes défini dans term-select.php continue donc de fonctionner.
 *
 * ATTENTION : is_admin() vaut false pour les requêtes REST et pour WP-CLI. Aucun de ces contextes
 * n'a besoin de ces fichiers aujourd'hui, mais si register_setting() recevait un jour
 * 'show_in_rest' => true, chtw_register_settings() devrait être sortie de cette condition —
 * sinon l'option ne serait pas déclarée lors des requêtes REST.
 * ---------------------------------------------------------------------- */

if ( is_admin() ) {

	require_once plugin_dir_path( __FILE__ ) . 'admin/admin-menu.php';
	require_once plugin_dir_path( __FILE__ ) . 'admin/settings.php';
	require_once plugin_dir_path( __FILE__ ) . 'admin/settings-page-template.php';
	require_once plugin_dir_path( __FILE__ ) . 'admin/enqueue.php';
	require_once plugin_dir_path( __FILE__ ) . 'admin/code-editor.php';
	require_once plugin_dir_path( __FILE__ ) . 'admin/term-select.php';

}
