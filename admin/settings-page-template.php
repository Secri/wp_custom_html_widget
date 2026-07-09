<?php
/**
 * settings-page-template.php
 *
 * Rôle unique de ce fichier : rendu HTML de la page d'administration du plugin.
 * - Affiche les erreurs/succès de sauvegarde via settings_errors()
 * - Affiche le <form> Settings API complet
 * - Boucle sur les blocs existants (chtw_get_blocks()) pour les afficher
 * - Fournit un template caché (une ligne de bloc vide) que field-repeater.js clone à chaque clic sur "Ajouter un bloc" (champ répétable)
 *
 */

if ( ! defined( 'ABSPATH' ) ) exit; // sécurité : pas d'accès direct au fichier

/* ------------------------------------------------------------------------
 * 1. Rendu d'une ligne de bloc (réutilisé pour les blocs existants ET comme template caché pour le JS)
 * ---------------------------------------------------------------------- */

/**
 * Fonction qui génère le HTML d'une ligne de bloc du repeater.
 *
 * Appelée :
 * - en boucle, une fois par bloc existant (chtw_get_blocks())
 * - une fois de plus avec un bloc vide, pour fournir le <template> caché que field-repeater.js clone à chaque ajout de bloc.
 *
 * @param array $block {
 *     @type string $id       Identifiant du bloc ('widget_N' ou 'new_...' ou '' pour le template).
 *     @type string $html     Code HTML du bloc (déjà sanitizé si lu depuis la base).
 *     @type string $taxonomy Slug de la taxonomie de ciblage.
 *     @type array  $term_ids Liste de term_id ciblés.
 *     @type string $title    Titre optionnel du bloc.
 * }
 * @return string HTML de la ligne.
 */
function chtw_render_block_row( array $block ) {

	$id               = isset( $block['id'] ) ? $block['id'] : '';
	$html             = isset( $block['html'] ) ? $block['html'] : '';
	$taxonomy         = isset( $block['taxonomy'] ) ? $block['taxonomy'] : '';
	$term_ids         = isset( $block['term_ids'] ) && is_array( $block['term_ids'] ) ? $block['term_ids'] : array();
	$include_children = ! empty( $block['include_children'] ); //Tricky : Doit pouvoir gérer les blocs en bdd et les blocs vides - case cochée => empty(...) = false => ! empty(...) = true => case cochée | case décochée => empty(...) = true => ! empty(...) = false => case décochée | valeur absente => empty(...) = true => ! empty(...) = false => case décochée
	$title            = isset( $block['title'] ) ? $block['title'] : '';

	// Préfixe de name= utilisé pour tous les champs de cette ligne.
	// Format tableau simple indicé par position (chtw_blocks[][html]), pas indicé par id !
	$name_base = 'chtw_blocks[]';

	// Liste des taxonomies publiques du site pour le select de ciblage.
	$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

	ob_start(); //Buffer nécessaire car chtw_render_block_row() est aussi utilisé par chtw_render_settings_page()
	?>
	<!-- HTML d'un accordéon côté ACP -->
	<div class="chtw-block-row chtw-accordion" data-block-id="<?php echo esc_attr( $id ); ?>">

		<input type="hidden" class="chtw-block-id-field" name="<?php echo esc_attr( $name_base ); ?>[id]" value="<?php echo esc_attr( $id ); ?>" />

		<div class="chtw-accordion-header" role="button" tabindex="0" aria-expanded="false">
			<span class="chtw-accordion-toggle-icon" aria-hidden="true">▶</span> <!-- caractère uncide brut équivalent à &#9654; -->
			<h3 class="chtw-block-title-display">
				<?php echo '' !== $title ? esc_html( $title ) : esc_html__( '(Bloc sans titre)', 'chtw' ); ?>
			</h3>
			<button type="button" class="button chtw-move-block-up" aria-label="<?php esc_attr_e( 'Monter ce bloc', 'chtw' ); ?>" title="<?php esc_attr_e( 'Monter ce bloc', 'chtw' ); ?>">
				▲
			</button>
			<button type="button" class="button chtw-move-block-down" aria-label="<?php esc_attr_e( 'Descendre ce bloc', 'chtw' ); ?>" title="<?php esc_attr_e( 'Descendre ce bloc', 'chtw' ); ?>">
				▼
			</button>
			<button type="button" class="button chtw-remove-block" aria-label="<?php esc_attr_e( 'Supprimer ce bloc', 'chtw' ); ?>">
				<?php esc_html_e( 'Supprimer ce bloc', 'chtw' ); ?>
			</button>
		</div>

		<div class="chtw-accordion-body">

			<div class="chtw-block-row-header">
				<label>
					<?php esc_html_e( 'Titre du bloc (repère interne, non affiché sur le site)', 'chtw' ); ?>
					<input
						type="text"
						class="regular-text chtw-block-title-field"
						name="<?php echo esc_attr( $name_base ); ?>[title]"
						value="<?php echo esc_attr( $title ); ?>"
						placeholder="<?php esc_attr_e( 'Ex : Bandeau LinkedIn - articles cybersécurité', 'chtw' ); ?>"
					/>
				</label>
			</div>

			<div class="chtw-block-row-targeting">

				<label>
					<?php esc_html_e( 'Taxonomie de ciblage', 'chtw' ); ?>
					<select class="chtw-taxonomy-select" name="<?php echo esc_attr( $name_base ); ?>[taxonomy]">
						<option value=""><?php esc_html_e( '— Choisir une taxonomie —', 'chtw' ); ?></option>
						<?php foreach ( $taxonomies as $tax_slug => $tax_object ) : ?>
							<option value="<?php echo esc_attr( $tax_slug ); ?>" <?php selected( $taxonomy, $tax_slug ); ?>>
								<?php echo esc_html( $tax_object->labels->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<?php esc_html_e( 'Termes ciblés', 'chtw' ); ?>
					<select
						class="chtw-term-select"
						name="<?php echo esc_attr( $name_base ); ?>[term_ids][]"
						multiple="multiple"
						data-current-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
						style="width:100%;"
					> <!-- Ici l'attribut style="width:100%" est recommandé pour gérer pendant l'initialisation de Select2 -->
						<?php
						// Pré-remplissage : uniquement les termes déjà utilisés par des blocs en base de données
						// Le peuplement par recherche (autres termes) sera assuré plus tard par Select2 + un endpoint AJAX dédié
						// On ne précharge jamais ici l'ensemble des termes existants !
						if ( ! empty( $term_ids ) && '' !== $taxonomy ) {
							
							foreach ( $term_ids as $term_id ) {
								
								$term = get_term( $term_id, $taxonomy );
								
								if ( $term && ! is_wp_error( $term ) ) {
									echo '<option value="' . esc_attr( $term->term_id ) . '" selected="selected">' . esc_html( $term->name ) . '</option>';
								}
								
							}
							
						}
						?>
					</select>
				</label>

				<label class="chtw-include-children-label">
					<input
						type="checkbox"
						class="chtw-include-children-checkbox"
						name="<?php echo esc_attr( $name_base ); ?>[include_children]"
						value="1"
						<?php checked( $include_children ); ?>
					/>
					<?php esc_html_e( 'Inclure tous les enfants des termes sélectionnés', 'chtw' ); ?>
				</label>

			</div>

			<div class="chtw-block-row-content">
				<label>
					<?php esc_html_e( 'Code HTML du widget', 'chtw' ); ?>
					<textarea
						class="chtw-html-editor"
						name="<?php echo esc_attr( $name_base ); ?>[html]"
						rows="10"
					><?php echo esc_textarea( $html ); ?></textarea>
				</label>
			</div>

		</div>

	</div>
	<!-- Fin de l'HTML d'un accordéon côté ACP -->
	<?php
	return ob_get_clean(); //On stoppe le buffer et on retourne le HTML
}

/* ------------------------------------------------------------------------
 * 2. Rendu de la page complète
 * ---------------------------------------------------------------------- */

/**
 * Callback d'affichage de la page de settings, référencé par add_options_page() dans admin-menu.php.
 */
function chtw_render_settings_page() {

	if ( ! current_user_can( 'manage_options' ) ) { //Sécurité lié aux droits du compte utilisateur
		return;
	}

	$blocks = chtw_get_blocks(); //Fonction de lecture de la bdd qui renvoie un tableau des blocs existant (voir settings.php)
	
	?>
	
	<div class="wrap chtw-settings-wrap">

		<h1><?php esc_html_e( 'Widgets HTML personnalisés', 'chtw' ); ?></h1>

		<?php
			// Affiche les messages enregistrés via add_settings_error()
			settings_errors();
		?>
		
		<form method="post" action="options.php">

			<?php settings_fields( 'chtw_settings_group' ); ?>

			<div id="chtw-blocks-list">
				<?php
				if ( empty( $blocks ) ) {
					echo '<p class="chtw-no-blocks">' . esc_html__( 'Aucun bloc pour le moment. Cliquez sur "Ajouter un bloc" pour commencer.', 'chtw' ) . '</p>';
				} else {
					foreach ( $blocks as $block ) {
						echo chtw_render_block_row( $block );
					}
				}
				?>
			</div>

			<p>
				<button type="button" id="chtw-add-block" class="button button-secondary">
					<?php esc_html_e( '+ Ajouter un bloc', 'chtw' ); ?>
				</button>
			</p>

			<?php submit_button( __( 'Enregistrer les modifications', 'chtw' ) ); ?>

		</form>

		<!--
			Template caché cloné par field-repeater.js à chaque clic sur "Ajouter un bloc".
			Bloc vide, id volontairement laissé vide : les champs utilisent le tableau
			positionnel chtw_blocks[][xxx] (cf chtw_render_block_row()), donc aucun
			placeholder d'index n'est nécessaire dans les name. Le JS n'a besoin de
			renseigner qu'un id temporaire unique (ex: 'new_1', 'new_2'...) dans le
			champ caché 'chtw-block-id-field' du clone (permet de distinguer les nouveaux
			blocs entre eux jusqu'à la sauvegarde (cf chtw_sanitize_blocks()).
			Ce template n'est jamais soumis tel quel : le <template> HTML natif n'est
			de toute façon jamais inclus dans le rendu ni sérialisé par le navigateur
			tant qu'il n'a pas été explicitement cloné en JS.
		-->
		<template id="chtw-block-template">
			<?php
			echo chtw_render_block_row( array( // phpcs:ignore -- déjà échappé champ par champ
				'id'       => '',
				'html'     => '',
				'taxonomy' => '',
				'term_ids' => array(),
				'include_children' => false,
				'title'    => '',
			) );
			?>
		</template>

	</div>
	<?php
}
