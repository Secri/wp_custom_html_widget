<?php
/**
 * data.php
 *
 * Rôle unique de ce fichier : accès en LECTURE aux données du plugin, pour les contextes front
 * comme administration.
 *
 * Ce fichier existe parce que includes/front-rendering.php a besoin de chtw_get_blocks() : tant
 * que cette fonction vivait dans admin/settings.php, le dossier admin/ devait être chargé sur
 * chaque page publique — soit plus de 1 100 lignes parsées pour n'en utiliser qu'une dizaine.
 *
 * Les fonctions d'ÉCRITURE (chtw_get_next_id(), chtw_create_new_block()) restent volontairement
 * dans admin/settings.php : elles n'appartiennent qu'au chemin de sauvegarde, exclusivement
 * administratif, et n'ont donc rien à faire dans le contexte front.
 *
 * Chargé inconditionnellement par wp-custom-html-widget.php, AVANT le dossier admin/.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // sécurité : pas d'accès direct au fichier

/**
 * Retourne le tableau complet des blocs enregistrés
 *
 * @return array
 */
function chtw_get_blocks() {

	$blocks = get_option( 'chtw_blocks', array() );

	return is_array( $blocks ) ? $blocks : array();

}

