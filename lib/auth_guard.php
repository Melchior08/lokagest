<?php
/**
 * LokaGest - Guard de Sécurité d'Authentification
 * 
 * Ce fichier sécurise les pages d'administration du propriétaire.
 * Il vérifie la session utilisateur et s'assure que le profil est complet (téléphone).
 */

require_once __DIR__ . '/../config/app.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    // Redirection vers la page de login avec l'URL de retour
    $redirectUrl = APP_URL . '/auth/login.php';
    
    // Garder en mémoire la page demandée pour y revenir après authentification
    $currentPage = $_SERVER['REQUEST_URI'];
    if (!empty($currentPage) && strpos($currentPage, 'login.php') === false && strpos($currentPage, 'callback.php') === false) {
        $_SESSION['redirect_after_login'] = $currentPage;
    }
    
    header('Location: ' . $redirectUrl);
    exit;
}

$user = $_SESSION['user'];

// Vérifier si le compte est suspendu
if (isset($user['statut']) && $user['statut'] === 'suspendu') {
    // Détruire la session et renvoyer vers le login avec un message
    session_destroy();
    header('Location: ' . APP_URL . '/auth/login.php?error=compte_suspendu');
    exit;
}

// Vérifier si le téléphone est manquant (F2 - demande téléphone à la première connexion)
// Si le téléphone n'est pas renseigné, on force l'utilisateur à aller sur les paramètres (settings) pour le renseigner.
// On excepte la page settings.php elle-même et logout.php pour éviter une boucle de redirection infinie.
$currentScript = basename($_SERVER['SCRIPT_NAME']);
if (empty($user['telephone']) && $currentScript !== 'settings.php' && $currentScript !== 'logout.php' && $currentScript !== 'callback.php') {
    header('Location: ' . APP_URL . '/pages/settings.php?setup_phone=1');
    exit;
}
