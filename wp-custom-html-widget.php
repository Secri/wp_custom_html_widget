<?php
/**
 * Plugin Name: WP Custom HTML Widget
 * Plugin URI: https://github.com/Secri/wp_custom_html_widget/
 * Description: Création de blocs HTML personnalisés injectables par taxonomies dans les zones de widgets
 * Version: 0.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Christophe IENZER
 * Author URI: https://www.linkedin.com/in/christophe-ienzer
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: chtw
 * Update URI: false
 */

if ( ! defined( 'ABSPATH' ) ) exit; // sécurité : pas d'accès direct au fichier

/* ------------------------------------------------------------------------
 * Chemins du plugin
 *
 * Calculés une seule fois, ici, à partir de __FILE__ — le seul repère fiable, puisque ce fichier
 * ne bougera jamais de la racine du plugin. Les fichiers de admin/ utilisaient auparavant
 * plugin_dir_url( __DIR__ ) et plugin_dir_path( __DIR__ ), qui remontent d'un niveau depuis leur
 * propre emplacement : correct tant qu'ils restent dans un sous-dossier de premier niveau, mais
 * silencieusement faux dès qu'un fichier change de profondeur (admin/partials/, includes/admin/...).
 * L'erreur ne serait pas détectée au chargement — juste des assets en 404.
 *
 * Les deux fonctions WordPress retournent un slash final : les chemins relatifs se concatènent
 * donc directement, sans séparateur.
 * ---------------------------------------------------------------------- */

define( 'CHTW_PLUGIN_PATH', plugin_dir_path( __FILE__ ) ); // chemin serveur, pour require_once et filemtime()
define( 'CHTW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );   // URL publique, pour wp_enqueue_script() et wp_enqueue_style()

/**
 * Version du plugin.
 *
 * ATTENTION : doit rester synchronisée manuellement avec l'en-tête « Version: » ci-dessus, qui est
 * la seule source lue par WordPress. La lire dynamiquement imposerait get_plugin_data(), qui charge
 * des fichiers d'administration et n'est pas disponible en front — remède pire que le mal ici.
 *
 * Sert uniquement de repli à chtw_asset_version().
 */
define( 'CHTW_VERSION', '0.1.0' );

/**
 * Retourne le paramètre de version à passer à wp_enqueue_style() / wp_enqueue_script() pour un asset.
 *
 * Retourne la date de dernière modification du fichier (cache busting automatique : l'URL générée
 * change dès que le fichier est modifié, ce qui force le navigateur à le retélécharger au lieu de
 * resservir sa copie en cache), ou la version du plugin en repli si le fichier est introuvable.
 *
 * Ce repli évite qu'un déploiement incomplet ne remplisse le journal d'erreurs de warnings
 * « stat failed » — filemtime() n'échoue pas silencieusement, elle émet un warning et retourne
 * false, valeur que WordPress traduit ensuite par sa propre version dans l'URL de l'asset.
 *
 * Définie dans ce fichier et non dans admin/enqueue.php : elle est utilisée aussi par
 * admin/term-select.php et admin/code-editor.php, et la loger dans l'un d'eux recréerait la
 * dépendance implicite à l'ordre des require_once que la centralisation vient de supprimer.
 *
 * @param string $relative_path Chemin depuis la racine du plugin, ex: 'assets/js/field-repeater.js'
 * @return string|int Timestamp Unix, ou CHTW_VERSION si le fichier est absent.
 */
function chtw_asset_version( $relative_path ) {

	$absolute_path = CHTW_PLUGIN_PATH . $relative_path;

	return file_exists( $absolute_path ) ? filemtime( $absolute_path ) : CHTW_VERSION;

}

/* ------------------------------------------------------------------------
 * Constantes de configuration
 *
 * Toutes rassemblées ici, quel que soit le fichier qui les consomme. Auparavant réparties entre
 * admin/settings.php et admin/term-select.php, elles créaient une dépendance implicite à l'ordre
 * des require_once ci-dessous : un fichier lisait une constante définie par un autre, et un simple
 * déplacement de ligne aurait suffi à provoquer une erreur fatale. Ce fichier étant chargé en
 * premier par WordPress, l'ordre cesse d'être une question.
 *
 * Note : ces constantes sont désormais définies dans tous les contextes, y compris en front où
 * seul le rendu des blocs a lieu. Quatre entiers dans l'espace global, le coût est nul.
 * ---------------------------------------------------------------------- */

/**
 * Longueur maximale (en nombre de caractères) autorisée pour le titre d'un bloc. Utilisée à deux endroits qui doivent rester cohérents entre eux :
 * - dans settings.php, comme troncature réelle à la sauvegarde (chtw_sanitize_blocks_uncached())
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
 * conséquence dans chtw_sanitize_blocks_uncached() : rejet total de la
 * soumission, pas de troncature (cette limite n'a pas vocation à être
 * abaissée après coup, un dépassement ici est considéré comme anormal).
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

/**
 * Nombre de termes RETOURNÉS par page de résultats AJAX (cf chtw_handle_term_search_request()
 * dans admin/term-select.php). Correspond à la pagination native de Select2 (scroll infini
 * au-delà de ce nombre). Attention : ce n'est pas la valeur passée telle quelle à get_terms(),
 * qui en demande une de plus pour détecter l'existence d'une page suivante.
 */
define( 'CHTW_TERM_SEARCH_PER_PAGE', 20 );

/* ------------------------------------------------------------------------
 * Fichiers includes/ (partagés admin et /ou front)
 *
 * Chargés inconditionnellement, et AVANT admin/ : data.php définit chtw_get_blocks(), utilisée
 * aussi bien par front-rendering.php que par les écrans d'administration.
 *
 * front-rendering.php doit rester hors de toute condition : register_widget() est nécessaire en
 * front pour l'affichage, mais aussi dans l'administration pour l'écran des widgets.
 * ---------------------------------------------------------------------- */

require_once CHTW_PLUGIN_PATH . 'includes/data.php';
require_once CHTW_PLUGIN_PATH . 'includes/taxonomy-matcher.php';
require_once CHTW_PLUGIN_PATH . 'includes/front-rendering.php';

/* ------------------------------------------------------------------------
 * Fichiers admin/ (back-office uniquement)
 *
 * Ordre de chargement :
 *
 * 1 - admin-menu.php (définit chtw_get_settings_page_hook_suffix() utilisée par enqueue.php, code-editor.php et term-select.php)
 *
 * Les constantes CHTW_* ne pèsent plus sur cet ordre : elles sont définies plus haut dans ce
 * fichier, donc disponibles pour tous les fichiers inclus ci-dessous, quelle que soit leur position.
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

	require_once CHTW_PLUGIN_PATH . 'admin/admin-menu.php';
	require_once CHTW_PLUGIN_PATH . 'admin/settings.php';
	require_once CHTW_PLUGIN_PATH . 'admin/settings-page-template.php';
	require_once CHTW_PLUGIN_PATH . 'admin/enqueue.php';
	require_once CHTW_PLUGIN_PATH . 'admin/code-editor.php';
	require_once CHTW_PLUGIN_PATH . 'admin/term-select.php';

}
