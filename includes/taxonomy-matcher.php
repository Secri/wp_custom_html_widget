<?php
/**
 * taxonomy-matcher.php
 *
 * Rôle unique de ce fichier : déterminer si un bloc doit s'afficher sur la page courante, en comparant les term_ids ciblés par le bloc aux termes réellement assignés à la page.
 *
 * La correspondance se fait en une comparaison d'ensembles (array_intersect)
 *
 */

if ( ! defined( 'ABSPATH' ) ) exit; // sécurité : pas d'accès direct au fichier

/**
 * Détermine si un bloc doit s'afficher pour un post donné, en comparant les termes ciblés par le bloc aux termes réellement assignés à ce post. Utilisé par front-rendering.php.
 *
 * @param array $block   - Un bloc tel que retourné par chtw_get_blocks() : doit contenir au moins 'taxonomy', 'term_ids', 'include_children'.
 * @param int   $post_id - ID du post/page à tester (typiquement get_the_ID()).
 * @return bool          - True si le bloc doit s'afficher sur ce post
 */
function chtw_block_matches_post( array $block, $post_id ) {

	$taxonomy = isset( $block['taxonomy'] ) ? $block['taxonomy'] : '';
	$term_ids = isset( $block['term_ids'] ) && is_array( $block['term_ids'] ) ? $block['term_ids'] : array();

	// Un bloc sans taxonomie ou sans terme ciblé ne peut matcher aucune page : on considère que la configuration est incomplète, donc on n'affiche pas le bloc (fail-safe)
	if ( '' === $taxonomy || empty( $term_ids ) ) {
		return false;
	}

	if ( ! taxonomy_exists( $taxonomy ) ) {
		return false; // taxonomie renommée/supprimée depuis l'enregistrement du bloc
	}

	$target_term_ids = chtw_expand_target_term_ids( $block ); //Retourne l'ensemble des term_id associés à un bloc HTML

	// get_the_terms() plutôt que wp_get_post_terms() : elle lit le cache des relations objet-termes,
	// que WordPress a déjà amorcé via update_object_term_cache() en exécutant la requête principale
	// de la page. Les termes du post sont donc en mémoire avant même que ce widget ne s'exécute.
	// wp_get_post_terms(), elle, reconstruit une WP_Term_Query à chaque appel — servie par un cache
	// de second niveau, certes, mais après avoir refait tout le travail de construction de requête.
	//
	// Aucun risque de régression : si le cache n'était pas amorcé, get_the_terms() appellerait
	// elle-même wp_get_object_terms() puis l'amorcerait pour les appels suivants.
	//
	// À noter : elle retourne des objets WP_Term (d'où le wp_list_pluck()), false — et non un
	// tableau vide — quand le post n'a aucun terme dans cette taxonomie, et applique le filtre
	// 'get_the_terms'. Ce dernier point est un changement de comportement assumé : le ciblage voit
	// désormais les mêmes termes que le thème et les autres extensions.
	$post_terms = get_the_terms( $post_id, $taxonomy );

	if ( is_wp_error( $post_terms ) || empty( $post_terms ) ) {
		return false; // pas de terme assigné à ce post dans cette taxonomie, ou taxonomie invalide pour ce post type
	}

	$post_term_ids = wp_list_pluck( $post_terms, 'term_id' );

	return count( array_intersect( $target_term_ids, $post_term_ids ) ) > 0; //Retourne true s'il les deux tableaux (les term_id du post et les term_id du bloc) contiennent au moins 1 fois un terme commun
}

/**
 * Calcule l'ensemble complet des term_id à considérer comme "ciblés" par un bloc, en tenant compte de l'option 'include_children'.
 *
 * Si 'include_children' est actif, chaque term_id sélectionné est complété par tous ses descendants (récursif, via get_term_children())
 *
 * @param array $block
 * @return array Liste de term_id (entiers), dédupliquée.
 */
function chtw_expand_target_term_ids( array $block ) {

	$taxonomy         = isset( $block['taxonomy'] ) ? $block['taxonomy'] : '';
	$term_ids         = isset( $block['term_ids'] ) && is_array( $block['term_ids'] ) ? $block['term_ids'] : array();
	$include_children = ! empty( $block['include_children'] ); // Vaut true s'il faut inclure les enfants

	if ( ! $include_children || '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) { // Si on ne doit pas inclure les enfants on retourne direct les term_ids
		return $term_ids;
	}

	$expanded = $term_ids;

	foreach ( $term_ids as $term_id ) {
		$children = get_term_children( $term_id, $taxonomy );

		if ( is_wp_error( $children ) ) { // get_term_children() retourne un WP_Error si le terme ou la taxonomie est invalide, on ignore silencieusement
			continue;
		}

		$expanded = array_merge( $expanded, $children );
	}

	return array_values( array_unique( array_map( 'absint', $expanded ) ) );
}
