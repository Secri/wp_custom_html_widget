<?php
/**
 * code-editor.php
 *
 * Rôle unique de ce fichier : brancher l'éditeur de code CodeMirror natif de WordPress (wp_enqueue_code_editor()) sur les champs .chtw-html-editor de la page de settings. 
 * Coloration syntaxique HTML uniquement, pas de lint
 * 
 * Ce fichier gère uniquement CodeMirror. Il communique avec field-repeater.js via l'événement custom 'chtw:block-added' émis à chaque nouveau bloc.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // sécurité : pas d'accès direct au fichier

add_action( 'admin_enqueue_scripts', 'chtw_enqueue_code_editor' );

/**
 * Fonction d'enqueue CodeMirror (natif WordPress) pour l'édition de code HTML, uniquement sur la page de settings du plugin.
 *
 * @param string $hook_suffix Fourni par WordPress : le hook suffix de la page admin courante.
 */
function chtw_enqueue_code_editor( $hook_suffix ) {

	if ( $hook_suffix !== chtw_get_settings_page_hook_suffix() ) {
		return; // on n'est pas sur la page de settings du plugin, on ne charge rien
	}

	$code_editor_settings = wp_enqueue_code_editor( //Configuration du CodeMirror générée par Wordpress
													array(
															'type'       => 'text/html',
															'codemirror' => array(
															'lint' => false, // coloration syntaxique seule, pas de vérification de syntaxe en temps réel
													),
							) );

	// wp_enqueue_code_editor() retourne false si l'utilisateur a désactivé la coloration syntaxique dans son profil, ou si les settings générés sont vides. 
	// Dans ce cas, on n'enqueue pas notre script d'init, les textareas resteront de simples <textarea> sans coloration, ce qui reste utilisable.
	if ( false === $code_editor_settings ) {

		return;

	}

	wp_enqueue_script(

		'chtw-code-editor-init',

		plugin_dir_url( __DIR__ ) . 'assets/js/code-editor-init.js', // Notre fichier d'initiation de l'éditeur de code

		array( 'code-editor', 'chtw-field-repeater' ), // les dépendances - On a besoin de chtw-field-repeater parce que c'est ce script qui génère l'événement chtw:block-added

		filemtime( plugin_dir_path( __DIR__ ) . 'assets/js/code-editor-init.js' ), //cache busting

		true //On charge en pied de page

	);

	wp_localize_script(

		'chtw-code-editor-init',

		'chtwCodeEditorSettings',

		$code_editor_settings //On transmet la conf du CodeMirror au JS

	);

}
