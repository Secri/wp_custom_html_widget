<?php
/**
 * settings-page-template.php
 *
 * Rôle unique de ce fichier : rendu HTML de la page d'administration du plugin.
 * - Affiche les erreurs/succès de sauvegarde via settings_errors()
 * - Affiche le <form> Settings API complet
 * - Boucle sur les blocs existants (chtw_get_blocks()) pour les afficher
 * - Fournit un template caché que field-repeater.js clone à chaque clic sur "Ajouter un bloc" (champ répétable)
 *
 */

if ( ! defined( 'ABSPATH' ) ) exit; // sécurité : pas d'accès direct au fichier

/* ------------------------------------------------------------------------
 * 1. Rendu d'une ligne de bloc (réutilisé pour les blocs existants ET comme template caché pour le JS)
 * ---------------------------------------------------------------------- */

/**
 * Fonction qui retourne les identifiants des zones de widgets contenant au moins une instance du widget.
 *
 * Sert à signaler, dans le sélecteur de zone, celles où aucun widget n'est placé : un bloc peut y être ciblé sans jamais s'afficher, faute de widget pour le rendre.
 *
 * Les instances de widgets sont identifiées par des clés de la forme '{id_base}-{numéro}', soit ici 'chtw_widget-3' (cf CHTW_Widget::__construct() dans includes/front-rendering.php).
 *
 * Note : wp_get_sidebars_widgets() est marquée en @access private dans le cœur de WordPress. Elle est stable depuis la version 2.2 et universellement utilisée, mais ce n'est pas une API contractuelle.
 *
 * Mémorisée en statique : appelée une fois par bloc affiché, elle interrogerait sinon la même option
 * à chaque ligne du formulaire.
 *
 * @return array Identifiants de zones en clés, true en valeurs.
 **/
function chtw_get_sidebars_containing_widget() {

	static $sidebars_with_widget = null;

	if ( null !== $sidebars_with_widget ) {
		return $sidebars_with_widget;
	}

	$sidebars_with_widget = array();

	if ( ! function_exists( 'wp_get_sidebars_widgets' ) ) { //Mécanisme défensif, comme l'équipe Wordpress précise que cette fonction est private, on retourne un tableau vide directement pour éviter une éventuelle erreur fatale
    	return $sidebars_with_widget;
	}

	foreach ( wp_get_sidebars_widgets() as $sidebar_id => $widget_ids ) {

		if ( 'wp_inactive_widgets' === $sidebar_id || ! is_array( $widget_ids ) ) {
			continue; // zone des widgets inactifs : rien n'y est rendu côté front
		}

		foreach ( $widget_ids as $widget_id ) {
			if ( 0 === strpos( $widget_id, 'chtw_widget-' ) ) {
				$sidebars_with_widget[ $sidebar_id ] = true;
				break; // une instance suffit, inutile de parcourir le reste de la zone
			}
		}
	}

	return $sidebars_with_widget;

}

/**
 * Fonction qui génère le HTML d'une ligne de bloc du repeater.
 *
 * @param array $block {
 *     @type string $id       Identifiant du bloc ('widget_N' ou 'new_...' ou '' pour le template).
 *     @type string $html     Code HTML du bloc (déjà sanitizé si lu depuis la base).
 *     @type string $taxonomy Slug de la taxonomie de ciblage.
 *     @type array  $term_ids Liste de term_id ciblés.
 *     @type string $title    Titre optionnel du bloc.
 *     @type string $sidebar  Identifiant de la zone de widgets dans laquelle le bloc s'affiche.
 * }
 * @param int|string $index Index de la ligne dans le tableau chtw_blocks soumis. Entier pour un bloc existant, chaîne '__INDEX__' pour le template caché => field-repeater.js y substitue un index réel au moment du clonage.
 * @return string HTML de la ligne.
 *
 **/
function chtw_render_block_row( array $block, $index ) {

	$id               = isset( $block['id'] ) ? $block['id'] : '';
	$html             = isset( $block['html'] ) ? $block['html'] : '';
	$taxonomy         = isset( $block['taxonomy'] ) ? $block['taxonomy'] : '';
	$term_ids         = isset( $block['term_ids'] ) && is_array( $block['term_ids'] ) ? $block['term_ids'] : array();
	$include_children = ! empty( $block['include_children'] ); //Tricky : Doit pouvoir gérer les blocs en bdd et les blocs vides - case cochée => empty(...) = false => ! empty(...) = true => case cochée | case décochée => empty(...) = true => ! empty(...) = false => case décochée | valeur absente => empty(...) = true => ! empty(...) = false => case décochée
	$title            = isset( $block['title'] ) ? $block['title'] : '';
	$sidebar          = isset( $block['sidebar'] ) ? $block['sidebar'] : '';

	// Préfixe de name= utilisé pour tous les champs de cette ligne.
	//
	// L'index est OBLIGATOIREMENT explicite : chtw_blocks[0][html], pas chtw_blocks[][html].
	// PHP ouvre un nouvel élément de tableau à CHAQUE crochet vide rencontré, sans mémoire du
	// champ précédent : 'chtw_blocks[][id]=x&chtw_blocks[][title]=y' ne produit pas un bloc à
	// deux clés, mais deux blocs à une clé — donc un bloc rejeté faute d'id, et un bloc sans
	// contenu. L'index explicite est le seul moyen de regrouper les champs d'une même ligne.
	//
	// Ces index n'ont pas besoin d'être contigus ni ordonnés : chtw_sanitize_blocks() réindexe
	// via $clean_blocks[], et PHP conserve l'ordre d'apparition des clés dans $_POST — l'ordre
	// d'affichage front suit donc toujours l'ordre du DOM au moment de la soumission, y compris
	// après usage des boutons monter/descendre.
	$name_base = 'chtw_blocks[' . $index . ']';

	// Liste des taxonomies proposées comme cibles de ciblage.
	//
	// show_ui en plus de public : les deux critères coïncident pour presque toutes les taxonomies
	// (show_ui prend par défaut la valeur de public), sauf pour post_format, que WordPress déclare
	// public => true / show_ui => false. Sans ce second critère, « Formats » apparaîtrait dans le
	// select alors que l'utilisateur ne peut pas affecter ces termes depuis l'administration, et
	// que ceux-ci n'existent même pas en base tant qu'aucun article ne s'en est vu attribuer un —
	// le ciblage resterait donc sans termes sélectionnables, puis serait signalé comme incomplet.
	//
	// Le critère retenu est bien « ce que l'utilisateur peut effectivement affecter à ses contenus »
	// plutôt qu'une exclusion nominative de post_format : il couvre de la même façon les taxonomies
	// tierces enregistrées sur ce modèle, sans avoir à les connaître.
	$taxonomies = get_taxonomies( array( 'public' => true, 'show_ui' => true ), 'objects' );

	// Zones de widgets déclarées par le thème actif, proposées comme second axe de ciblage.
	//
	// $GLOBALS['wp_registered_sidebars'] est le registre alimenté par register_sidebar() ; le cœur de
	// WordPress n'expose pas d'accesseur pour le lire. Il est peuplé pendant 'widgets_init', donc bien
	// avant le rendu de cette page.
	$sidebars = isset( $GLOBALS['wp_registered_sidebars'] ) ? $GLOBALS['wp_registered_sidebars'] : array();

	$sidebars_with_widget = chtw_get_sidebars_containing_widget();

	ob_start(); //Buffer nécessaire car chtw_render_block_row() est aussi utilisé par chtw_render_settings_page()
	?>
	<!-- HTML d'un accordéon côté ACP -->
	<div class="chtw-block-row chtw-accordion" data-block-id="<?php echo esc_attr( $id ); ?>">

		<input type="hidden" class="chtw-block-id-field" name="<?php echo esc_attr( $name_base ); ?>[id]" value="<?php echo esc_attr( $id ); ?>" />

		<div class="chtw-accordion-header">
			<button type="button" class="chtw-accordion-toggle" aria-expanded="false" aria-controls="chtw-accordion-body-<?php echo esc_attr( $index ); ?>">
				<span class="chtw-accordion-toggle-icon dashicons dashicons-plus" aria-hidden="true"></span>
				<span class="chtw-block-title-display">
					<?php echo '' !== $title ? esc_html( $title ) : esc_html__( '(Bloc sans titre)', 'chtw' ); ?>
				</span>
			</button>
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

		<div class="chtw-accordion-body" id="chtw-accordion-body-<?php echo esc_attr( $index ); ?>">

			<div class="chtw-block-row-header">
				<label>
					<?php esc_html_e( 'Titre du bloc (repère interne, non affiché sur le site)', 'chtw' ); ?>
					<input
						type="text"
						maxlength="<?php echo esc_attr( CHTW_BLOCK_TITLE_MAX_LENGTH ); //Limite maximale d'un titre, définie dans le fichier principal du plugin ?>"
						class="regular-text chtw-block-title-field"
						name="<?php echo esc_attr( $name_base ); ?>[title]"
						value="<?php echo esc_attr( $title ); ?>"
						placeholder="<?php esc_attr_e( 'Ex : Bandeau LinkedIn - articles cybersécurité', 'chtw' ); ?>"
					/>
				</label>
			</div>

			<div class="chtw-block-row-targeting">

				<label>
					<?php esc_html_e( 'Zone de widgets', 'chtw' ); ?>
					<select class="chtw-sidebar-select" name="<?php echo esc_attr( $name_base ); ?>[sidebar]">
						<option value=""><?php esc_html_e( '— Choisir une zone —', 'chtw' ); ?></option>
						<?php foreach ( $sidebars as $sidebar_id => $sidebar_object ) : ?>
							<option value="<?php echo esc_attr( $sidebar_id ); ?>" <?php selected( $sidebar, $sidebar_id ); ?>>
								<?php
								// Mention « Widget absent » sur les zones où aucune instance du widget n'est
								// placée : un bloc peut y être ciblé sans jamais s'afficher, et rien d'autre
								// dans l'interface ne le signalerait.
								echo esc_html(
									isset( $sidebars_with_widget[ $sidebar_id ] )
										? $sidebar_object['name']
										: sprintf(
											/* translators: %s: nom de la zone de widgets */
											__( '%s — Widget absent', 'chtw' ),
											$sidebar_object['name']
										)
								);
								?>
							</option>
						<?php endforeach; ?>
						<?php if ( '' !== $sidebar && ! isset( $sidebars[ $sidebar ] ) ) : ?>
							<?php
							/* Zone enregistrée mais absente du thème actif — typiquement après un changement de
							   thème, les identifiants de zones appartenant au thème. On réinjecte l'option pour
							   deux raisons : rendre l'anomalie visible, et surtout éviter que le navigateur ne
							   retombe sur la première option du select, ce qui effacerait le ciblage au prochain
							   enregistrement sans que personne ne l'ait demandé. */
							?>
							<option value="<?php echo esc_attr( $sidebar ); ?>" selected>
								<?php
								printf(
									/* translators: %s: identifiant de la zone de widgets introuvable */
									esc_html__( '%s — zone introuvable dans ce thème', 'chtw' ),
									esc_html( $sidebar )
								);
								?>
							</option>
						<?php endif; ?>
					</select>
				</label>

				<label>
					<?php esc_html_e( 'Taxonomie de ciblage', 'chtw' ); ?>
					<select class="chtw-taxonomy-select" name="<?php echo esc_attr( $name_base ); ?>[taxonomy]">
						<option value=""><?php esc_html_e( '— Choisir une taxonomie —', 'chtw' ); ?></option>
						<?php foreach ( $taxonomies as $tax_slug => $tax_object ) : ?>
							<option value="<?php echo esc_attr( $tax_slug ); ?>" data-hierarchical="<?php echo $tax_object->hierarchical ? '1' : '0'; //Lu par field-repeater.js pour activer ou non la case "Inclure tous les enfants", sans effet sur une taxonomie plate ?>" <?php selected( $taxonomy, $tax_slug ); ?>>
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

				<p class="description chtw-html-editor-notice">
					<?php esc_html_e( 'Les scripts externes et les balises style sont acceptés : vous pouvez coller tel quel le code d\'intégration fourni par LinkedIn, YouTube, X, Instagram, TikTok, Facebook...', 'chtw' ); ?>
					<br />
					<strong><?php esc_html_e( 'Le code JavaScript sera en revanche supprimé à l\'enregistrement, seuls les scripts chargés depuis une adresse externe (attribut src) sont conservés.', 'chtw' ); ?></strong>
					<br />
					<?php esc_html_e( 'Si vous ajoutez du CSS, nous vous recommandons d\'utiliser ce sélecteur :', 'chtw' ); ?>
					<code><?php echo esc_html( '.chtw-widget[data-chtw-block-id="' . ( 0 === strpos( $id, 'widget_' ) ? $id : 'votre-identifiant-de-bloc' ) . '"] { ... }' ); //Sélecteur réel dès que le bloc est enregistré, générique tant qu'il ne l'est pas (template et blocs non sauvegardés) ?></code>
				</p>
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
 * Callback d'affichage de la page de settings, référencé par add_menu_page() dans admin-menu.php.
 */
function chtw_render_settings_page() {

	if ( ! current_user_can( 'manage_options' ) ) { //Sécurité lié aux droits du compte utilisateur
		return;
	}

	$blocks = chtw_get_blocks(); //Fonction de lecture de la bdd qui renvoie un tableau des blocs existant (voir settings.php)

	// Avertissement préventif à l'approche de la limite CHTW_MAX_BLOCKS
	// (cf CHTW_MAX_BLOCKS_WARNING_THRESHOLD dans settings.php), affiché au
	// chargement de la page — pas seulement à la sauvegarde — pour laisser
	// à l'admin le temps d'anticiper avant que la limite ne devienne
	// bloquante (cf le rejet total dans chtw_sanitize_blocks()).
	if ( count( $blocks ) >= CHTW_MAX_BLOCKS_WARNING_THRESHOLD ) {
		add_settings_error(
			'chtw_blocks',
			'chtw_blocks_approaching_limit',
			sprintf(
				/* translators: 1: nombre de blocs actuels, 2: nombre maximal de blocs autorisés */
				__( 'Vous approchez de la limite de blocs autorisés (%1$d / %2$d). Pensez à supprimer les blocs inutilisés.', 'chtw' ),
				count( $blocks ),
				CHTW_MAX_BLOCKS
			),
			'warning'
		);
	}

	?>
	
	<div class="wrap chtw-settings-wrap">

		<h1><?php esc_html_e( 'Widgets HTML personnalisés', 'chtw' ); ?></h1>

		<?php
			/* Affiche les messages enregistrés via add_settings_error().
			
			   Cet appel est INDISPENSABLE et ne doit pas être retiré : WordPress n'affiche
			   automatiquement les messages de la Settings API que pour les pages placées sous le
			   menu Réglages. Cette page étant une entrée de premier niveau (cf add_menu_page()
			   dans admin-menu.php), aucun affichage automatique n'a lieu — le supprimer ferait
			   disparaître silencieusement tous les messages, y compris la confirmation
			   d'enregistrement de WordPress.
			   
			   Corollaire inverse : si la page revenait un jour sous Réglages, cet appel
			   provoquerait un affichage EN DOUBLE de chaque message et devrait être retiré. */
			settings_errors();
		?>
		
		<form method="post" action="options.php">

			<?php settings_fields( 'chtw_settings_group' ); ?>

			<div id="chtw-blocks-list">
				<?php
				// Le message est TOUJOURS rendu, simplement masqué quand des blocs existent : c'est
				// field-repeater.js qui bascule sa visibilité (cf refreshEmptyState()). Le retirer
				// du HTML rendrait impossible son réaffichage après suppression du dernier bloc,
				// sauf à dupliquer la chaîne traduite côté JS.
				echo '<p class="chtw-no-blocks"' . ( empty( $blocks ) ? '' : ' hidden' ) . '>' . esc_html__( 'Aucun bloc pour le moment. Cliquez sur "Ajouter un bloc" pour commencer.', 'chtw' ) . '</p>';

				// Index recalculé ici plutôt que réutilisé depuis les clés de $blocks : on
				// garantit une numérotation contiguë à partir de 0, quelle que soit la forme
				// du tableau lu en base (import manuel, réindexation partielle...).
				$row_index = 0;

				foreach ( $blocks as $block ) {
					echo chtw_render_block_row( $block, $row_index );
					$row_index++;
				}
				?>
			</div>

			<p>
				<button type="button" id="chtw-add-block" class="button button-secondary" data-max-blocks="<?php echo esc_attr( CHTW_MAX_BLOCKS ); //Limite lue par field-repeater.js pour piloter l'état de ce bouton après chaque ajout ou suppression. Transmise par le DOM plutôt que par wp_localize_script() : le script inline produit par ce dernier peut être bloqué (CSP stricte) ou déplacé (plugin d'optimisation) indépendamment du fichier JS, alors que cet attribut fait partie du document que field-repeater.js manipule de toute façon. ?>" <?php disabled( count( $blocks ) >= CHTW_MAX_BLOCKS ); ?>>
					<?php esc_html_e( '+ Ajouter un bloc', 'chtw' ); ?>
				</button>
			</p>

			<?php submit_button( __( 'Enregistrer les modifications', 'chtw' ) ); ?>

		</form>

		<!--
			Template caché cloné par field-repeater.js à chaque clic sur "Ajouter un bloc".
			Bloc vide, rendu avec l'index littéral '__INDEX__' dans tous ses name= : le JS
			substitue un index réel (unique pour la page en cours) au moment du clonage, sans
			quoi tous les blocs ajoutés partageraient le même index et s'écraseraient entre eux
			dans $_POST. Le JS renseigne en plus un id temporaire unique ('new_1', 'new_2'...)
			dans le champ caché 'chtw-block-id-field' du clone, qui permet de distinguer les
			nouveaux blocs entre eux jusqu'à la sauvegarde (cf chtw_assign_pending_block_ids()).
			Ce template n'est jamais soumis tel quel : le <template> HTML natif n'est de toute
			façon jamais sérialisé par le navigateur tant qu'il n'a pas été cloné en JS — le
			littéral '__INDEX__' ne peut donc jamais atteindre chtw_sanitize_blocks().
		-->
		<template id="chtw-block-template">
			<?php
			echo chtw_render_block_row(
				array(
					'id'               => '',
					'html'             => '',
					'taxonomy'         => '',
					'term_ids'         => array(),
					'include_children' => false,
					'title'            => '',
				),
				'__INDEX__'
			);
			?>
		</template>

	</div>
	<?php
}
