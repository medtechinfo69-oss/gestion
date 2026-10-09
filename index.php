<?php
require_once __DIR__ . '/includes/init.php';

/**
 * Page d'accueil après connexion, selon le rôle :
 *  - un compte RH arrive sur SON tableau de bord (le dashboard de production
 *    lui est interdit) ;
 *  - les autres rôles arrivent sur le dashboard de gestion.
 */
function home_page_for_role(): string
{
    return is_rh() ? 'rh_dashboard.php' : 'dashboard.php';
}

if (is_logged_in()) {
    redirect(home_page_for_role());
} else {
    redirect('login.php');
}
