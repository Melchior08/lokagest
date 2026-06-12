<?php
/**
 * LokaGest - API Chambres / Unités
 * 
 * Permet d'interagir avec les chambres en AJAX (liste par propriété, ajout).
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/auth_guard.php';
require_once __DIR__ . '/../lib/supabase.php';

$user = $_SESSION['user'];
$userId = $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $propertyId = $_GET['property_id'] ?? '';
    if (empty($propertyId)) {
        echo json_encode(['success' => false, 'error' => 'Identifiant de propriété requis']);
        exit;
    }
    
    // Vérifier RLS Propriétaire
    $propCheck = SupabaseClient::select('properties', 'id', 'id=eq.' . $propertyId . '&user_id=eq.' . $userId);
    if ($propCheck['status'] !== 200 || empty($propCheck['data'])) {
        echo json_encode(['success' => false, 'error' => 'Accès interdit']);
        exit;
    }
    
    $response = SupabaseClient::select('units', '*', 'property_id=eq.' . $propertyId . '&order=code_unique.asc');
    echo json_encode([
        'success' => $response['status'] === 200,
        'data' => $response['data'] ?? [],
        'error' => $response['error']
    ]);
} elseif ($method === 'POST') {
    // Ajouter une chambre
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    
    $propertyId = trim($input['property_id'] ?? '');
    $type = trim($input['type'] ?? 'Chambre');
    $loyerReference = floatval($input['loyer_reference'] ?? 0);
    
    if (empty($propertyId) || $loyerReference <= 0) {
        echo json_encode(['success' => false, 'error' => 'Champs obligatoires manquants ou invalides']);
        exit;
    }
    
    // Vérifier RLS Propriétaire
    $propCheck = SupabaseClient::select('properties', 'id, quartier', 'id=eq.' . $propertyId . '&user_id=eq.' . $userId);
    if ($propCheck['status'] !== 200 || empty($propCheck['data'])) {
        echo json_encode(['success' => false, 'error' => 'Accès interdit']);
        exit;
    }
    
    $property = $propCheck['data'][0];
    
    // Générer le code de la chambre
    $quartierClean = preg_replace('/[^a-zA-Z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $property['quartier']));
    $quartierClean = strtoupper($quartierClean);
    
    $unitsCountRes = SupabaseClient::select('units', 'id', 'property_id=eq.' . $propertyId);
    $nextNum = 1;
    if ($unitsCountRes['status'] === 200 && is_array($unitsCountRes['data'])) {
        $nextNum = count($unitsCountRes['data']) + 1;
    }
    
    $codeUnique = "LKG-" . $quartierClean . "-CH" . $nextNum;
    
    $newUnit = [
        'property_id' => $propertyId,
        'code_unique' => $codeUnique,
        'type' => $type,
        'loyer_reference' => $loyerReference,
        'statut' => 'libre',
        'photo_url' => 'https://images.unsplash.com/photo-1522771739844-6a9f6d5f14af?auto=format&fit=crop&w=400&q=80',
        'date_creation' => date('Y-m-d H:i:s')
    ];
    
    $response = SupabaseClient::insert('units', $newUnit);
    
    echo json_encode([
        'success' => $response['status'] >= 200 && $response['status'] < 300,
        'data' => $response['data'] ?? [],
        'error' => $response['error']
    ]);
}
exit;
