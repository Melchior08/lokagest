<?php
/**
 * LokaGest - Script de Déconnexion
 * 
 * Détruit proprement la session PHP et redirige l'utilisateur vers la page de login.
 */

require_once __DIR__ . '/../config/app.php';

// Vider toutes les variables de session
$_SESSION = [];

// Détruire le cookie de session sur le navigateur
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// Détruire la session côté serveur
session_destroy();

// Rediriger vers la page de connexion
header('Location: ' . APP_URL . '/auth/login.php?logout=1');
exit;
