<?php
/**
 * uninstall.php
 *
 * Exécuté automatiquement par WordPress lors de la SUPPRESSION du plugin depuis l'écran des
 * extensions — jamais lors d'une simple désactivation. C'est la distinction qui compte :
 * désactiver doit rester réversible sans perte, supprimer signifie « je n'en veux plus ».
 *
 * ATTENTION : les fichiers du plugin ne sont PAS chargés dans ce contexte. Ni les constantes
 * CHTW_*, ni les fonctions du plugin ne sont disponibles ici — d'où les noms d'options écrits
 * littéralement ci-dessous. Toute modification de ces noms ailleurs dans le code devra être
 * répercutée ici, sans quoi la désinstallation laisserait des lignes orphelines en base.
 */

// Garde-fou : cette constante n'est définie que par WordPress lors d'une désinstallation réelle.
// Sans elle, un accès direct au fichier suffirait à effacer les données.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Données métier du plugin (cf includes/data.php et admin/settings.php).
delete_option( 'chtw_blocks' );   // tableau des blocs HTML et de leur ciblage
delete_option( 'chtw_next_id' );  // compteur incrémental des id de blocs

// Instances du widget placées dans les zones de widgets. WP_Widget stocke ses réglages dans une
// option nommée 'widget_' + id_base, soit ici 'widget_chtw_widget' (cf CHTW_Widget::__construct()
// dans includes/front-rendering.php). Ces instances ne portent aucun réglage propre, mais la ligne
// subsisterait en base sans ce nettoyage.
delete_option( 'widget_chtw_widget' );

