<?php
/**
 * LokaGest - API Authentification
 * 
 * Retourne le statut de session de l'utilisateur connecté sous forme JSON.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/app.php';

if (isset($_SESSION['user']) && !empty($_SESSION['user'])) {
    echo json_encode([
        'authenticated' => true,
        'user' => [
            'id' => $_SESSION['user']['id'],
            'prenom' => $_SESSION['user']['prenom'],
            'email' => $_SESSION['user']['email'],
            'telephone' => $_SESSION['user']['telephone'],
            'plan' => $_SESSION['user']['plan'],
            'fondateur' => $_SESSION['user']['fondateur']
        ],
        'mode_gestionnaire' => isset($_SESSION['mode_gestionnaire'])
    ]);
} else {
    echo json_encode([
        'authenticated' => false,
        'error' => 'Non authentifié'
    ]);
}
exit;
