<?php
/**
 *
 * Gère la structure de données du plugin :
 * - 'chtw_blocks'   : tableau des blocs HTML + leur ciblage par taxonomie
 * - 'chtw_next_id'  : compteur incrémental servant à générer les futurs 'id' de bloc
 *
 * Rôle unique de ce fichier : Settings API (gestion des id de blocs, register_setting, sanitization, lecture/écriture).
 * 
 */

if ( ! defined( 'ABSPATH' ) ) exit; // sécurité : pas d'accès direct au fichier

/* ------------------------------------------------------------------------
 * 1. Attribution des id définitifs aux nouveaux blocs (avant sanitization)
 *
 * Cette étape doit se produire AVANT que WordPress n'invoque le sanitize_callback de 'chtw_blocks'.
 * L'attribution d'un id définitif ('widget_N') à un bloc fraîchement créé côté JS (id temporaire 'new_...') est isolée ici via la fonction chtw_assign_pending_block_ids()
 * ---------------------------------------------------------------------- */

add_action( 'admin_init', 'chtw_assign_pending_block_ids', 5 ); // priorité 5 : avant chtw_register_settings (priorité 10 par défaut)

/**
 * Remplace l'id temporaire de tout bloc fraîchement créé côté JS (préfixe 'new_') par un id définitif ('widget_N'), généré via chtw_create_new_block().
 * Ne fait rien si la requête courante n'est pas une soumission de notre propre formulaire de settings (vérifié via 'option_page', champ caché généré par settings_fields() dans settings-page-template.php).
 *
 * Une fois cette fonction exécutée, chtw_sanitize_blocks() ne reçoit plus que des blocs dont l'id est soit déjà 'widget_N' (bloc existant), soit invalide (bug, requête corrompue)
 */
function chtw_assign_pending_block_ids() {

	if ( empty( $_POST['option_page'] ) || 'chtw_settings_group' !== $_POST['option_page'] ) { // Si ce n'est pas le formulaire settings_page_template.php qui est soumis
		return; // On ne fait rien
	}

	if ( empty( $_POST['chtw_blocks'] ) || ! is_array( $_POST['chtw_blocks'] ) ) { // Si c'est bien notre formulaire, on vérifie que c'est la bonne option qui est soumise
		return; // Sinon on ne fait rien
	}

	foreach ( $_POST['chtw_blocks'] as $index => $raw_block ) { // On itère sur la data de l'option

		if ( ! is_array( $raw_block ) || empty( $raw_block['id'] ) ) {  //si l'élement de chtw_blocks n'est pas un tableau ou si la valeur associée à la clé 'id' est vide
			continue; // On passe directement à l'élément suivant - On laissera chtw_sanitize_blocks() rejeter le bloc à la soumission
		}

		$raw_id = sanitize_key( $raw_block['id'] ); // On sanitize l'id

		if ( 0 === strpos( $raw_id, 'new_' ) ) { // Si cet id est un id temporaire (qui commence par new_ = attribué en JS)
			$new_block                            = chtw_create_new_block(); // On crée un nouveau bloc avec un ID définitif
			$_POST['chtw_blocks'][ $index ]['id'] = $new_block['id']; // On intercepte le tableau $_POST et on remplace le bloc avec l'id temporaire par son jumeau avec l'id définitif
		}
	}
}

/* ------------------------------------------------------------------------
 * 2. Enregistrement des settings
 * ---------------------------------------------------------------------- */

add_action( 'admin_init', 'chtw_register_settings' );

function chtw_register_settings() {

	register_setting(
		'chtw_settings_group',   // groupe d'options
		'chtw_blocks',           // nom de l'option en base qui stocke les valeurs du ou des blocs
		array(
			'type'              => 'array',
			'sanitize_callback' => 'chtw_sanitize_blocks',
			'default'           => array(),
		)
	);

	register_setting(
		'chtw_settings_group',
		'chtw_next_id', // compteur indépendant à stocker à part dans la base de données ! Sert pour la logique d'unicité de l'incrément
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 1,
		)
	);
}

/* ------------------------------------------------------------------------
 * 3. Sanitization du tableau de blocs
 * ---------------------------------------------------------------------- */

/**
 * Sanitize l'ensemble du tableau soumis par le formulaire.
 * Reçoit le tableau brut ($_POST['chtw_blocks'] tel que WordPress le transmet via register_setting), retourne un tableau propre prêt à être stocké en bdd.
 *
 * Au moment où cette fonction s'exécute, chaque bloc a donc déjà soit un id 'widget_N' valide, soit un id invalide (bug, requête corrompue), auquel cas il sera rejeté ci-dessous.
 *
 * @param mixed $raw_blocks
 * @return array
 */

function chtw_sanitize_blocks( $raw_blocks ) {

	if ( ! is_array( $raw_blocks ) ) {
		// $raw_blocks dans un format inattendu (ex: requête corrompue/falsifiée, $_POST['chtw_blocks'] absent alors qu'il aurait dû être présent) : 
		// On restaure les blocs actuellement en base (aucune perte de données) et on raise une erreur.
		add_settings_error(
			'chtw_blocks',
			'chtw_blocks_malformed_submission',
			__( 'La soumission du formulaire semble corrompue : aucune modification n\'a été enregistrée, vos blocs existants ont été conservés tels quels.', 'chtw' ),
			'error'
		);
		return chtw_get_blocks();
	}

	$clean_blocks      = array();
	$rejected_count    = 0; // nombre de blocs invalides écartés pendant la sanitization
	$incomplete_labels = array(); // repères (titre ou id) des blocs enregistrés mais incomplets (taxonomie/termes manquants)

	foreach ( $raw_blocks as $raw_block ) { //On itère sur chaque bloc du tableau

		if ( ! is_array( $raw_block ) ) { //Si un élément de $raw_blocks n'est pas un tableau, on le rejette et on incrémente le compteur de rejets
			$rejected_count++;
			continue; //On passe directement à l'élément suivant
		}

		$id = isset( $raw_block['id'] ) ? sanitize_key( $raw_block['id'] ) : ''; //on met l'id du bloc dans la variable $id et on sanitize - Valeur de repli = string vide

		// À ce stade, chtw_assign_pending_block_ids() a déjà remplacé tout id temporaire ('new_...') par un id définitif ('widget_N') : un id qui n'est toujours pas de la forme 'widget_N' ici est un cas anormal.
		if ( '' === $id || 0 !== strpos( $id, 'widget_' ) ) { //si l'id du bloc est vide ou si sa syntaxe est différente de widget_N, on rejette et on incrémente le compteur
			$rejected_count++;
			continue; //On passe directement à l'élément suivant
		}

		$taxonomy = isset( $raw_block['taxonomy'] ) ? sanitize_key( $raw_block['taxonomy'] ) : ''; //De la même façon on sanitize la taxo du bloc

		$term_ids = array();
		if ( isset( $raw_block['term_ids'] ) && is_array( $raw_block['term_ids'] ) ) {
			$term_ids = array_values( array_filter( array_map( 'absint', $raw_block['term_ids'] ) ) ); //S'assure que les id sont int positifs, supprime les 0, réassigne les index du tableau
		}

		$include_children = ! empty( $raw_block['include_children'] ); // Inclusion des taxonomies enfants : Checkbox HTML classique - présente dans $_POST seulement si cochée

		$title = isset( $raw_block['title'] ) ? sanitize_text_field( $raw_block['title'] ) : '';

		$html = isset( $raw_block['html'] ) ? chtw_sanitize_html_block( $raw_block['html'] ) : '';

		$clean_blocks[] = array(
			'id'               => $id,
			'html'             => $html,
			'taxonomy'         => $taxonomy,
			'term_ids'         => $term_ids,
			'include_children' => $include_children,
			'title'            => $title,
		);

		// Mécanisme de fail-safe : un bloc sans taxonomie ou sans terme ciblé est enregistré tel quel. La fonction chtw_block_matches_post() dans taxonomy-matcher.php ne l'affichera alors sur aucune page. On le signalera néanmoins explicitement pour que l'admin ne soit pas surpris de ne pas le voir apparaître sur le site.
		if ( '' === $taxonomy || empty( $term_ids ) ) {
			$incomplete_labels[] = '' !== $title ? $title : $id; //Pour l'indication on utilise le titre du bloc s'il existe, sinon on prend son id qui existe toujours à ce moment là
		}
	}

	// Avertit visuellement l'admin si un ou plusieurs blocs ont été rejetés pendant la sanitization
	if ( $rejected_count > 0 ) {
		add_settings_error(
			'chtw_blocks',
			'chtw_blocks_rejected',
			sprintf(
				_n(
					'%d bloc a été ignoré lors de l\'enregistrement car il ne contenait pas d\'identifiant valide. Vérifiez qu\'aucun bloc ne s\'est corrompu avant de continuer.',
					'%d blocs ont été ignorés lors de l\'enregistrement car ils ne contenaient pas d\'identifiant valide. Vérifiez qu\'aucun bloc ne s\'est corrompu avant de continuer.',
					$rejected_count, //tricky : sert à déterminer quelle forme utiliser (pluriel ou singulier)
					'chtw' //text domain
				),
				$rejected_count //valeur qui sera utilisée pour remplacer %d
			),
			'error'
		);
	}

	// Avertit l'admin des blocs enregistrés mais incomplet (pas de taxonomie ou pas de terme sélectionné)
	// Ces blocs SONT enregistrés mais ils ne s'afficheront sur aucune page tant que leur configuration ne sera pas complétée (cf chtw_block_matches_post() dans taxonomy-matcher.php)
	if ( ! empty( $incomplete_labels ) ) {
		add_settings_error(
			'chtw_blocks',
			'chtw_blocks_incomplete',
			sprintf(
				__( 'Configuration incomplète pour le(s) bloc(s) suivant(s) : %s. Ils ont bien été enregistrés, mais ne s\'afficheront sur aucune page tant qu\'une taxonomie et au moins un terme ne seront pas sélectionnés.', 'chtw' ),
				implode( ', ', array_map( 'esc_html', $incomplete_labels ) )
			),
			'warning'
		);
	}

	return $clean_blocks;
}

/**
 * Sanitize le HTML d'un bloc individuel.
 *
 * wp_kses_post() ne suffit pas ici : le plugin doit pouvoir stocker des embeds tiers qui pourraient contenir des balises <script>.
 * Règles standard de wp_kses_allowed_html('post') + balise <script> volontairement restreinte (src, async, defer, type, charset, crossorigin)
 *
 * @param string $html
 * @return string
 */
function chtw_sanitize_html_block( $html ) {

	$allowed_html = wp_kses_allowed_html( 'post' );

	$allowed_html['script'] = array(
		'src'         => true,
		'async'       => true,
		'defer'       => true,
		'type'        => true,
		'charset'     => true,
		'crossorigin' => true,
	);

	// Pour les <iframe> on s'assure que les attributs dangereux ne sont pas acceptés. Les 10 attributs choisis permettent les embed type Linkedin, Youtube...
	$allowed_html['iframe'] = array(
		'src'             => true,
		'width'           => true,
		'height'          => true,
		'frameborder'     => true,
		'allow'           => true,
		'allowfullscreen' => true,
		'loading'         => true,
		'title'           => true,
		'sandbox'         => true,
		'referrerpolicy'  => true,
	);

	return wp_kses( $html, $allowed_html );
}

/* ------------------------------------------------------------------------
 * 4. Lecture des données (fonctions utilitaires réutilisables partout)
 * ---------------------------------------------------------------------- */

/**
 * Retourne le tableau complet des blocs enregistrés
 *
 * @return array
 */
function chtw_get_blocks() {

	$blocks = get_option( 'chtw_blocks', array() );

	return is_array( $blocks ) ? $blocks : array();

}

/**
 * Retourne le prochain compteur disponible pour générer un nouvel id de bloc
 *
 * @return int
 */
function chtw_get_next_id() {

	return absint( get_option( 'chtw_next_id', 1 ) );

}

/* ------------------------------------------------------------------------
 * 5. Création d'un nouveau bloc (génère l'id, incrémente le compteur)
 * ---------------------------------------------------------------------- */

/**
 * Construit un nouveau bloc vide avec un id unique de la forme 'widget_N', et incrémente + persiste le compteur chtw_next_id en base.
 *
 * Le compteur n'est jamais réutilisé, même après suppression de blocs, ça évite toute collision d'id côté JS/HTML si un ancien état était encore en cache navigateur.
 *
 * @return array Le nouveau bloc, prêt à être ajouté au tableau chtw_blocks.
 */
function chtw_create_new_block() {

	$next_id = chtw_get_next_id();

	$new_block = array(
		'id'               => 'widget_' . $next_id,
		'html'             => '',
		'taxonomy'         => '',
		'term_ids'         => array(),
		'include_children' => false,
		'title'            => '',
	);

	update_option( 'chtw_next_id', $next_id + 1 );

	return $new_block;
	
}
