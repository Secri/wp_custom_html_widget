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

/**
 * Longueur maximale (en nombre de caractères) autorisée pour le titre d'un bloc. Utilisée à deux endroits qui doivent rester cohérents entre eux :
 * - ici, comme troncature réelle à la sauvegarde (chtw_sanitize_blocks())
 * - dans settings-page-template.php, comme attribut HTML maxlength sur le champ de saisie
 */
define( 'CHTW_BLOCK_TITLE_MAX_LENGTH', 100 );

/**
 * Nombre maximal de blocs autorisés. Protection de robustesse (pas de
 * sécurité stricte) contre une accumulation excessive de blocs, qui
 * dégraderait le rendu de la page de settings sans jamais menacer la base
 * de données (50 blocs représentent quelques dizaines à quelques centaines
 * de Ko, négligeable pour wp_options).
 *
 * Le JS (field-repeater.js) désactive déjà le bouton "Ajouter un bloc" à
 * cette limite : le seul scénario qui peut atteindre ce nombre côté serveur
 * est donc une requête forgée (contournement volontaire du JS) — traité en
 * conséquence dans chtw_sanitize_blocks() : rejet total de la soumission,
 * pas de troncature (cette limite n'a pas vocation à être abaissée après
 * coup, un dépassement ici est considéré comme anormal).
 *
 * Utilisée aussi comme seuil de calcul pour l'avertissement préventif
 * affiché à l'admin à l'approche de la limite (cf CHTW_MAX_BLOCKS_WARNING_THRESHOLD
 * et chtw_render_settings_page() dans settings-page-template.php).
 */
define( 'CHTW_MAX_BLOCKS', 50 );

/**
 * Seuil (en nombre de blocs) à partir duquel un avertissement préventif est
 * affiché à l'admin, pour anticiper l'approche de CHTW_MAX_BLOCKS avant
 * qu'elle ne devienne bloquante. Fixé à 90% de CHTW_MAX_BLOCKS.
 */
define( 'CHTW_MAX_BLOCKS_WARNING_THRESHOLD', (int) ( CHTW_MAX_BLOCKS * 0.9 ) );

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
 *
 * POURQUOI DES CONTRÔLES DE DROITS ICI, ALORS QUE options.php EN FAIT DÉJÀ ?
 *
 * Parce que cette fonction ne s'exécute PAS depuis options.php : elle est accrochée à 'admin_init',
 * qui se déclenche sur absolument toutes les pages de /wp-admin/, pour tout utilisateur connecté —
 * y compris un simple abonné, et y compris sur profile.php ou index.php. Le seul filtre en place
 * était la présence de 'option_page' dans $_POST, qui n'est qu'une convention de nommage : rien
 * n'empêche de POSTer ce champ vers n'importe quelle page d'admin.
 *
 * Or cette fonction n'est pas en lecture seule : chtw_create_new_block() écrit en base
 * (update_option( 'chtw_next_id', ... )). Sans les trois gardes ci-dessous, n'importe quel compte
 * connecté pouvait donc déclencher autant d'écritures qu'il le souhaitait, et faire grimper le
 * compteur d'id indéfiniment. Pas de fuite ni de corruption de données — l'enregistrement réel
 * reste protégé par options.php — mais une écriture déclenchable hors du parcours prévu.
 *
 * On retourne silencieusement au lieu d'appeler check_admin_referer(), qui interromprait la
 * requête avec un wp_die() : une requête illégitime n'a aucune raison de casser l'affichage d'une
 * page d'admin sans rapport, et une requête légitime dont le nonce a expiré sera de toute façon
 * rejetée proprement quelques instants plus tard par options.php, avec le message adapté.
 */
function chtw_assign_pending_block_ids() {

	if ( empty( $_POST['option_page'] ) || 'chtw_settings_group' !== $_POST['option_page'] ) { // Si ce n'est pas le formulaire settings_page_template.php qui est soumis
		return; // On ne fait rien
	}

	if ( ! current_user_can( 'manage_options' ) ) { // Même capability que add_options_page() et que le contrôle en tête de chtw_render_settings_page()
		return; // On ne fait rien
	}

	// Nonce posé par settings_fields( 'chtw_settings_group' ) : wp_nonce_field() y génère l'action
	// "{$option_group}-options", soit 'chtw_settings_group-options', dans un champ '_wpnonce'.
	// C'est exactement celui que options.php vérifiera ensuite de son côté.
	if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'chtw_settings_group-options' ) ) {
		return; // On ne fait rien
	}

	if ( empty( $_POST['chtw_blocks'] ) || ! is_array( $_POST['chtw_blocks'] ) ) { // Si chtw_blocks est vide ou que ce n'est pas un tableau
		return; // On ne fait rien
	}

	foreach ( $_POST['chtw_blocks'] as $index => $raw_block ) { // On itère sur la data de l'option

		if ( ! is_array( $raw_block ) || empty( $raw_block['id'] ) ) {  //si l'élement de chtw_blocks n'est pas un tableau ou si la valeur associée à la clé 'id' est vide
			continue; // On passe directement à l'élément suivant - On laissera chtw_sanitize_blocks() rejeter le bloc à la soumission
		}

		$raw_id = sanitize_key( $raw_block['id'] ); // On sanitize l'id

		if ( 0 === strpos( $raw_id, 'new_' ) ) { // Si cet id est un id temporaire (qui commence par new_ = attribué en JS)
			$new_block                            = chtw_create_new_block(); // On crée un nouveau bloc avec un ID définitif
			/* ATTENTION — modification directe de $_POST['chtw_blocks']
			* ce n'est PAS un contournement de la sanitization, ni une faille : $_POST n'est ici que le vecteur de transport entre cette fonction et le
			* sanitize_callback natif de WordPress (chtw_sanitize_blocks(), invoqué plus tard par options.php).
			* Aucune donnée n'est lue en confiance ni persistée depuis ce fichier : seule la clé 'id' est réécrite, avec une valeur générée par chtw_create_new_block().
			* chtw_sanitize_blocks() re-valide l'intégralité du tableau, y compris cet id.
			* cette fonction ne remplace en rien la sanitization, elle la précède seulement pour éviter que la génération d'id (avec son effet de bord d'incrémentation de chtw_next_id) ne vive dans chtw_sanitize_blocks()
			*/
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

	/* 'chtw_next_id' n'est VOLONTAIREMENT PAS enregistré dans le groupe 'chtw_settings_group'.
	 *
	 * options.php boucle sur toutes les options d'un groupe et appelle update_option() pour chacune,
	 * y compris celles absentes de $_POST — auquel cas la valeur transmise est null :
	 *
	 *     $value = null;
	 *     if ( isset( $_POST[ $option ] ) ) { ... }
	 *     update_option( $option, $value );
	 *
	 * Or ce compteur n'a aucun champ correspondant dans le formulaire (il est piloté exclusivement
	 * côté serveur par chtw_create_new_block()). L'enregistrer ici revenait donc à le remettre à 0
	 * à chaque sauvegarde (absint( null ) === 0), et à faire produire l'id 'widget_0' à tous les
	 * blocs suivants — donc des id en collision.
	 *
	 * Le compteur est persisté directement via update_option() dans chtw_create_new_block() :
	 * il n'a pas besoin de la Settings API, qui ne sert qu'aux options pilotées par un formulaire.
	 */
}

/* ------------------------------------------------------------------------
 * 3. Sanitization du tableau de blocs
 * ---------------------------------------------------------------------- */

/**
 * Callback de sanitization enregistré via register_setting().
 *
 * Coquille de mémorisation autour de chtw_sanitize_blocks_uncached(), qui contient le traitement réel.
 *
 * POURQUOI CETTE COQUILLE ?
 *
 * update_option() et add_option() appellent toutes deux sanitize_option(), donc ce callback. Or la
 * première délègue à la seconde lorsque l'option n'a pas encore de ligne en base :
 *
 *     $value = sanitize_option( $option, $value );   // 1re exécution
 *     ...
 *     if ( apply_filters( "default_option_{$option}", false, $option, false ) === $old_value ) {
 *         return add_option( $option, $value, '', $autoload );   // qui re-sanitize
 *     }
 *
 * Le 'default' déclaré dans register_setting() n'y change rien : WordPress compare justement
 * $old_value à cette valeur par défaut pour détecter une option encore absente. Le traitement
 * s'exécuterait donc deux fois à la toute première sauvegarde d'une installation — sans altérer
 * les données (toutes les transformations sont idempotentes), mais en rejouant les
 * add_settings_error() de chtw_sanitize_blocks_uncached() : l'utilisateur verrait chaque
 * avertissement en double, et croirait deux blocs concernés là où il n'y en a qu'un.
 *
 * La variable statique conserve sa valeur d'un appel à l'autre pour la durée de la requête HTTP
 * uniquement — rien ne subsiste d'une soumission à la suivante, ce qui est exactement la portée
 * recherchée. La sentinelle null distingue "pas encore calculé" d'un résultat légitime : le test
 * doit rester une comparaison stricte à null et NON un empty(), sous peine de confondre l'absence
 * de calcul avec un tableau vide — cas parfaitement valide (suppression de tous les blocs), et
 * justement l'un de ceux qui ont le plus de chances de produire un avertissement.
 *
 * LIMITE ASSUMÉE : cette mémorisation suppose un seul jeu de valeurs par requête. Si un autre code
 * appelait update_option( 'chtw_blocks', ... ) avec un contenu différent au cours de la même
 * requête, il récupérerait le résultat de la première sanitization et non la sienne. options.php ne
 * traitant qu'une soumission à la fois, le cas ne se présente pas aujourd'hui — mais toute
 * sauvegarde programmatique ajoutée plus tard devra le prendre en compte.
 *
 * @param mixed $raw_blocks
 * @return array
 */
function chtw_sanitize_blocks( $raw_blocks ) {

	static $sanitized = null; // initialisée au premier appel uniquement : PHP ignore cette ligne aux appels suivants

	if ( null === $sanitized ) {
		$sanitized = chtw_sanitize_blocks_uncached( $raw_blocks );
	}

	return $sanitized;

}

/**
 * Sanitize l'ensemble du tableau soumis par le formulaire.
 * Reçoit le tableau brut ($_POST['chtw_blocks'] tel que WordPress le transmet via register_setting), retourne un tableau propre prêt à être stocké en bdd.
 *
 * Au moment où cette fonction s'exécute, chaque bloc a donc déjà soit un id 'widget_N' valide, soit un id invalide (bug, requête corrompue), auquel cas il sera rejeté ci-dessous.
 *
 * Ne pas appeler directement : passer par chtw_sanitize_blocks(), qui garantit une exécution unique
 * par requête. Les multiples points de sortie de cette fonction sont la raison pour laquelle la
 * mémorisation vit dans une coquille séparée plutôt qu'ici — un seul return oublié suffirait à la
 * rendre inopérante.
 *
 * @param mixed $raw_blocks
 * @return array
 */

function chtw_sanitize_blocks_uncached( $raw_blocks ) {

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

	// Rejet total (pas de troncature) si le nombre de blocs soumis dépasse
	// CHTW_MAX_BLOCKS : le JS désactive déjà le bouton "Ajouter un bloc" à
	// cette limite en usage normal, donc un dépassement ici ne peut provenir
	// que d'une requête forgée — traité comme une soumission anormale, au
	// même titre qu'une soumission structurellement invalide ci-dessus.
	if ( count( $raw_blocks ) > CHTW_MAX_BLOCKS ) {
		add_settings_error(
			'chtw_blocks',
			'chtw_blocks_limit_exceeded',
			sprintf(
				/* translators: %d: nombre maximal de blocs autorisés */
				__( 'La soumission dépasse la limite de %d blocs autorisés : aucune modification n\'a été enregistrée, vos blocs existants ont été conservés tels quels.', 'chtw' ),
				CHTW_MAX_BLOCKS
			),
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

		// Troncature APRÈS sanitize_text_field() : on nettoie d'abord les caractères indésirables, puis on
		// coupe à la longueur maximale autorisée (cf CHTW_BLOCK_TITLE_MAX_LENGTH, cohérente avec le maxlength
		// HTML posé sur le champ dans settings-page-template.php). Cette troncature serveur reste nécessaire
		// même avec ce maxlength, qui n'est qu'une aide à la saisie et se contourne trivialement.
		//
		// mb_substr() et NON substr() : substr() compte des octets, le maxlength HTML compte des caractères.
		// En UTF-8 un « é » pèse 2 octets, un « € » 3, un emoji 4 — un titre conforme à la limite affichée
		// serait donc coupé bien avant sa fin, et surtout la coupure pourrait tomber au milieu d'une séquence
		// multi-octets. La chaîne cesserait alors d'être de l'UTF-8 valide, wpdb en retirerait l'octet orphelin
		// à l'insertion, et le préfixe de longueur posé par serialize() ne correspondrait plus au contenu :
		// unserialize() échouerait à la relecture et chtw_get_blocks() retournerait un tableau vide, faisant
		// disparaître TOUS les blocs à cause d'un seul accent mal coupé.
		//
		// Aucune dépendance à ajouter : WordPress fournit un repli mb_substr() dans wp-includes/compat.php
		// lorsque l'extension mbstring est absente.
		$title = isset( $raw_block['title'] ) ? mb_substr( sanitize_text_field( $raw_block['title'] ), 0, CHTW_BLOCK_TITLE_MAX_LENGTH ) : '';

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

	// Les entrées ci-dessous REMPLACENT celles du jeu 'post' et ne passent donc pas par
	// _wp_add_global_attributes() : les attributs globaux (id, class, style, data-*) doivent y être
	// répétés explicitement, contrairement aux balises héritées telles quelles (blockquote, div, p, a...).
	$allowed_html['script'] = array(
		'src'            => true,
		'async'          => true,
		'defer'          => true,
		'type'           => true,
		'charset'        => true,
		'crossorigin'    => true,
		'integrity'      => true, // Subresource Integrity
		'referrerpolicy' => true,
		'id'             => true,
		'class'          => true,
		'data-*'         => true, // certains loaders tiers se configurent par attributs data-
	);

	// Les 15 attributs retenus couvrent les embeds iframe courants (LinkedIn, YouTube, Google Maps,
	// Spotify, SoundCloud, Mastodon) sans ouvrir d'attribut permettant l'exécution de code.
	$allowed_html['iframe'] = array(
		'src'               => true,
		'width'             => true,
		'height'            => true,
		'frameborder'       => true,
		'allow'             => true,
		'allowfullscreen'   => true,
		'allowtransparency' => true, // Facebook, anciens lecteurs audio
		'scrolling'         => true, // SoundCloud, Spotify
		'loading'           => true,
		'title'             => true,
		'name'              => true,
		'sandbox'           => true,
		'referrerpolicy'    => true,
		'id'                => true,
		'class'             => true,
		'style'             => true,
		'data-*'            => true,
	);

	$allowed_html['style'] = array(
		'type'  => true,
		'media' => true,
	);

	return wp_kses( chtw_strip_inline_scripts( $html ), $allowed_html );
}

/**
 * Retire les balises <script> dépourvues d'attribut src, contenu compris.
 *
 * @param string $html
 * @return string
 */
function chtw_strip_inline_scripts( $html ) {

	$stripped = preg_replace( '#<script\b(?![^>]*\bsrc\s*=)[^>]*>.*?</script>#is', '', $html );

	return is_string( $stripped ) ? $stripped : $html; // preg_replace() retourne null en cas d'échec (dépassement de pcre.backtrack_limit sur un contenu très volumineux)
}

/* ------------------------------------------------------------------------
 * 4. Génération des identifiants de blocs (chemin d'écriture, admin uniquement)
 *
 * La lecture des blocs, elle, vit dans includes/data.php : elle est partagée avec le front
 * (cf includes/front-rendering.php) et doit donc rester chargée hors du contexte admin.
 * ---------------------------------------------------------------------- */

/**
 * Retourne le prochain compteur disponible pour générer un nouvel id de bloc.
 *
 * La valeur retournée est le maximum entre :
 * - 1 (plancher : 'widget_0' n'est jamais un id valide) ;
 * - le compteur stocké en base ;
 * - le plus grand suffixe déjà utilisé par un bloc existant, + 1.
 *
 * Ce troisième terme est un garde-fou d'auto-réparation : si le compteur a été corrompu
 * (remis à 0 par une version antérieure du plugin, restauration partielle de base de données,
 * import manuel de l'option 'chtw_blocks' sans 'chtw_next_id'...), on ne peut plus jamais
 * régénérer un id déjà attribué. Le coût est nul en pratique : chtw_get_blocks() lit une
 * option déjà mise en cache par WordPress, et la boucle ne dépasse jamais CHTW_MAX_BLOCKS.
 *
 * @return int
 */
function chtw_get_next_id() {

	$stored_counter = absint( get_option( 'chtw_next_id', 1 ) );

	$highest_used = 0;

	foreach ( chtw_get_blocks() as $block ) {

		if ( ! is_array( $block ) || ! isset( $block['id'] ) || 0 !== strpos( $block['id'], 'widget_' ) ) {
			continue; // bloc malformé ou id hors format : ne peut pas entrer en collision
		}

		$highest_used = max( $highest_used, absint( substr( $block['id'], strlen( 'widget_' ) ) ) );
	}

	return max( 1, $stored_counter, $highest_used + 1 );

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
