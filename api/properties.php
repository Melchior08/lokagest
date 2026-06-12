<?php
/**
 * LokaGest - API Propriétés
 * 
 * Permet d'interagir avec les propriétés en AJAX (liste, création).
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/auth_guard.php';
require_once __DIR__ . '/../lib/supabase.php';

$user = $_SESSION['user'];
$userId = $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Lister les propriétés
    $response = SupabaseClient::select('properties', '*', 'user_id=eq.' . $userId . '&order=date_creation.desc');
    echo json_encode([
        'success' => $response['status'] === 200,
        'data' => $response['data'] ?? [],
        'error' => $response['error']
    ]);
} elseif ($method === 'POST') {
    // Créer une propriété
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    
    $nom = trim($input['nom'] ?? '');
    $adresse = trim($input['adresse'] ?? '');
    $quartier = trim($input['quartier'] ?? '');
    $ville = trim($input['ville'] ?? '');
    $photoUrl = trim($input['photo_url'] ?? '');
    
    if (empty($nom) || empty($adresse) || empty($quartier) || empty($ville)) {
        echo json_encode(['success' => false, 'error' => 'Champs obligatoires manquants']);
        exit;
    }
    
    if (empty($photoUrl)) {
        $photoUrl = 'https://images.unsplash.com/photo-1564013799919-ab600027ffc6?auto=format&fit=crop&w=400&q=80';
    }
    
    $newProperty = [
        'user_id' => $userId,
        'nom' => $nom,
        'adresse' => $adresse,
        'quartier' => $quartier,
        'ville' => $ville,
        'photo_url' => $photoUrl,
        'date_creation' => date('Y-m-d H:i:s')
    ];
    
    $response = SupabaseClient::insert('properties', $newProperty);
    
    echo json_encode([
        'success' => $response['status'] >= 200 && $response['status'] < 300,
        'data' => $response['data'] ?? [],
        'error' => $response['error']
    ]);
}
exit;
