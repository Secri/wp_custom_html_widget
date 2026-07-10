<?php
/**
 * front-rendering.php
 *
 * Rôle unique de ce fichier : afficher côté front les blocs HTML dont le
 * ciblage par taxonomie correspond à la page courante.
 *
 * L'affichage se fait via le système natif de widgets de WordPress
 * (register_widget / WP_Widget), plutôt que via un hook d'action dédié :
 * l'utilisateur place lui-même, une seule fois, une instance du widget
 * "Custom HTML Widgets" dans la zone de son choix (Apparence > Widgets).
 *
 * Le ciblage par taxonomie reste entièrement automatique : ce n'est PAS un
 * widget par bloc que l'utilisateur devrait replacer à chaque création — une
 * seule instance de ce widget boucle en interne sur tous les blocs de
 * chtw_blocks et n'affiche que ceux qui correspondent à la page courante,
 * exactement comme le faisait l'ancien hook 'chtw_display_blocks'.
 *
 * La logique de correspondance (quel bloc matche quelle page) vit dans
 * taxonomy-matcher.php — ce fichier ne fait que boucler dessus et générer
 * le HTML final.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // sécurité : pas d'accès direct au fichier

add_action( 'widgets_init', 'chtw_register_widget' );

/**
 * Enregistre la classe CHTW_Widget auprès de WordPress, la rendant
 * disponible dans Apparence > Widgets.
 */
function chtw_register_widget() {
	register_widget( 'CHTW_Widget' );
}

/**
 * Widget natif WordPress affichant tous les blocs HTML personnalisés dont le
 * ciblage par taxonomie correspond à la page courante.
 *
 * Ce widget n'a volontairement aucune option configurable depuis l'écran
 * Apparence > Widgets (form()/update() minimaux) : toute la configuration
 * (contenu HTML, ciblage par taxonomie) se fait exclusivement depuis la page
 * de settings du plugin (Réglages > Widgets HTML personnalisés). Le rôle de
 * ce widget est uniquement de choisir OÙ (quelle zone du thème) le résultat
 * de ce ciblage automatique doit s'afficher.
 */
class CHTW_Widget extends WP_Widget {

	/**
	 * Déclare le widget auprès de WordPress : identifiant unique, nom affiché
	 * dans Apparence > Widgets, description courte.
	 */
	public function __construct() {
		$widget_options = array(
			'classname'   => 'chtw_widget',
			'description' => __( 'Affiche les blocs HTML personnalisés ciblés par taxonomie, configurés depuis Réglages > Widgets HTML personnalisés.', 'chtw' ),
		);

		parent::__construct(
			'chtw_widget',              // id_base : identifiant unique du widget
			__( 'Custom HTML Widgets', 'chtw' ), // nom affiché dans Apparence > Widgets
			$widget_options
		);
	}

	/**
	 * Génère le contenu affiché côté front pour cette instance du widget.
	 *
	 * @param array $args     Args de la zone de widget (before_widget, after_widget, etc.),
	 *                        fournis par le thème via register_sidebar().
	 * @param array $instance Réglages de cette instance de widget (inutilisés ici,
	 *                        cf docblock de la classe : pas d'options par instance).
	 */
	public function widget( $args, $instance ) {

		if ( ! is_singular() ) {
			return; // le ciblage par taxonomie de post n'a de sens que sur un contenu singulier (post/page)
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$blocks = chtw_get_blocks();

		$matching_blocks_html = '';

		foreach ( $blocks as $block ) {

			if ( ! chtw_block_matches_post( $block, $post_id ) ) {
				continue;
			}

			// $block['html'] est déjà sanitizé à la sauvegarde (cf
			// chtw_sanitize_html_block() dans settings.php, wp_kses étendu avec
			// script/iframe restreints) : pas de ré-échappement ici, ce serait
			// contre-productif (l'échappement casserait volontairement les
			// balises <script>/<iframe> légitimement autorisées par ce wp_kses).
			$matching_blocks_html .= '<div class="chtw-widget" data-chtw-block-id="' . esc_attr( $block['id'] ) . '">';
			$matching_blocks_html .= $block['html']; // phpcs:ignore -- déjà sanitizé via wp_kses étendu à l'enregistrement
			$matching_blocks_html .= '</div>';
		}

		// Si aucun bloc ne matche la page courante, on n'affiche même pas les
		// before_widget/after_widget du thème (pas de cadre de widget vide).
		if ( '' === $matching_blocks_html ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore -- fourni par le thème via register_sidebar(), pas une donnée utilisateur
		echo $matching_blocks_html; // phpcs:ignore -- déjà sanitizé bloc par bloc ci-dessus
		echo $args['after_widget']; // phpcs:ignore -- fourni par le thème via register_sidebar(), pas une donnée utilisateur
	}

	/**
	 * Formulaire d'options affiché dans Apparence > Widgets pour cette
	 * instance. Volontairement minimal : toute la configuration se fait
	 * depuis la page de settings du plugin, pas ici.
	 *
	 * @param array $instance Réglages actuels de cette instance (inutilisés).
	 */
	public function form( $instance ) {
		?>
		<p>
			<?php
			esc_html_e(
				'Ce widget affiche automatiquement les blocs HTML configurés depuis Réglages > Widgets HTML personnalisés, selon leur ciblage par taxonomie. Aucun réglage supplémentaire n\'est nécessaire ici.',
				'chtw'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Traite la sauvegarde des options de cette instance. Ce widget n'ayant
	 * aucune option propre (cf form() ci-dessus), on retourne un tableau vide
	 * plutôt que $new_instance tel quel, pour ne rien stocker d'inutile.
	 *
	 * @param array $new_instance Valeurs soumises par le formulaire.
	 * @param array $old_instance Valeurs actuellement enregistrées.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array();
	}
}
