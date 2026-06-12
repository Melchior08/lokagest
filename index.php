<?php
/**
 * LokaGest - Point d'entrée principal
 * 
 * Ce fichier sert de routeur de base. S'il y a une session active,
 * l'utilisateur est redirigé vers son tableau de bord, sinon il est
 * renvoyé vers l'écran de connexion.
 */

require_once __DIR__ . '/config/app.php';

// Si l'utilisateur est connecté, on le redirige vers le dashboard
if (isset($_SESSION['user']) && !empty($_SESSION['user'])) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
} else {
    // Sinon, direction la page de connexion
    header('Location: ' . APP_URL . '/auth/login.php');
    exit;
}
