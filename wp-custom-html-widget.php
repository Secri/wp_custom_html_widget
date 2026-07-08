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
 * Fichiers admin/ (back-office uniquement)
 *
 * Ordre de chargement : 
 *
 * 1 - admin-menu.php (définit chtw_get_settings_page_hook_suffix() utilisée par enqueue.php, code-editor.php et term-select.php)
 * 2 - settings.php (nécessaire à settings-page-template.php pour chtw_get_blocks())
 * ---------------------------------------------------------------------- */

require_once plugin_dir_path( __FILE__ ) . 'admin/admin-menu.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/settings.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/settings-page-template.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/enqueue.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/code-editor.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/term-select.php';

/* ------------------------------------------------------------------------
 * Fichiers includes/ (partagés admin et /ou front
 * ---------------------------------------------------------------------- */

require_once plugin_dir_path( __FILE__ ) . 'includes/taxonomy-matcher.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/front-rendering.php';
