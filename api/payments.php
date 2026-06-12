<?php
/**
 * LokaGest - API Paiements (cURL FedaPay & Simulation)
 * 
 * Traite les demandes de paiement initiées par le portail locataire.
 * Crée la transaction en BDD (statut en_attente), communique avec FedaPay (Bénin),
 * et propose une page interactive de simulation de push USSD en local
 * pour tester le flux de A à Z (MTN MoMo + Moov Money) sans clés réelles.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/supabase.php';

$errorRedirect = APP_URL . '/tenant/pay.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $errorRedirect);
    exit;
}

$token = $_POST['token'] ?? '';
$momoPhone = trim($_POST['momo_phone'] ?? '');
$isHalf = isset($_POST['pay_half']);

if (empty($token) || empty($momoPhone)) {
    header('Location: ' . $errorRedirect . '?error=missing_fields');
    exit;
}

// 1. Charger le lien de paiement
$linkResponse = SupabaseClient::select('payment_links', '*', 'token=eq.' . $token);
if ($linkResponse['status'] !== 200 || empty($linkResponse['data'])) {
    header('Location: ' . $errorRedirect . '?error=invalid_token');
    exit;
}

$linkRecord = $linkResponse['data'][0];

if ($linkRecord['statut'] !== 'actif') {
    header('Location: ' . $errorRedirect . '?error=inactive_link');
    exit;
}

$unitId = $linkRecord['unit_id'];
$tenantId = $linkRecord['tenant_id'];
$moisEnCours = date('Y-m');

// 2. Charger les détails de la chambre, du locataire et du propriétaire
$unitRes = SupabaseClient::select('units', '*', 'id=eq.' . $unitId);
$unit = ($unitRes['status'] === 200 && !empty($unitRes['data'])) ? $unitRes['data'][0] : null;

$tenantRes = SupabaseClient::select('tenants', '*', 'id=eq.' . $tenantId);
$tenant = ($tenantRes['status'] === 200 && !empty($tenantRes['data'])) ? $tenantRes['data'][0] : null;

if (!$unit || !$tenant) {
    header('Location: ' . $errorRedirect . '?token=' . $token . '&error=not_found');
    exit;
}

// Charger les charges mensuelles
$chargesAmount = 0;
$chargesRes = SupabaseClient::select('charges', 'montant', 'unit_id=eq.' . $unitId . '&mois=eq.' . $moisEnCours);
if ($chargesRes['status'] === 200 && is_array($chargesRes['data'])) {
    foreach ($chargesRes['data'] as $c) {
        $chargesAmount += $c['montant'];
    }
}

// 3. Calculer le montant à débiter (Totalité vs Moitié)
$loyerBase = $tenant['loyer_convenu'];
if ($isHalf) {
    $loyerBase = $loyerBase / 2;
}
$montantTotalTransaction = $loyerBase + $chargesAmount;

// 4. Blocage de double paiement (F18)
$checkPay = SupabaseClient::select(
    'payments',
    'id',
    'unit_id=eq.' . $unitId . '&mois=eq.' . $moisEnCours . '&statut=eq.confirme'
);

if ($checkPay['status'] === 200 && !empty($checkPay['data'])) {
    header('Location: ' . APP_URL . '/tenant/pay.php?token=' . $token . '&error=double_payment');
    exit;
}

// 5. Générer un numéro de reçu unique SHA256 à l'avance
$numeroRecu = hash('sha256', $unitId . $tenantId . $moisEnCours . time());

// Nettoyer le numéro de téléphone MoMo
$momoPhoneClean = preg_replace('/[^0-9]/', '', $momoPhone);
if (strlen($momoPhoneClean) === 8) {
    $momoPhoneClean = '229' . $momoPhoneClean;
}

$operatorChoice = trim($_POST['operator'] ?? '');
if ($operatorChoice === '') {
    header('Location: ' . APP_URL . '/tenant/pay.php?token=' . urlencode($token) . '&error=select_operator');
    exit;
}
if ($operatorChoice === 'moov') {
    $modeMomo = 'momo_moov';
} elseif ($operatorChoice === 'mtn') {
    $modeMomo = 'momo_mtn';
} else {
    $modeMomo = 'momo_mtn';
    $prefix = substr($momoPhoneClean, 3, 2);
    $moovPrefixes = ['95', '66', '67', '60', '55'];
    if (in_array($prefix, $moovPrefixes)) {
        $modeMomo = 'momo_moov';
    }
}

// 6. Communication avec l'API FedaPay ou Simulation locale (Fallback F46 / F44 / F45)
$fedapaySecretKey = getenv('FEDAPAY_SECRET_KEY');

if (empty($fedapaySecretKey)) {
    // -------------------------------------------------------------
    // MODE SIMULATION INTERACTIVE DE PUSH USSD (SANS CLÉ API)
    // -------------------------------------------------------------
    
    // Insérer d'abord le paiement en_attente dans Supabase
    $tempFedaPayId = 'sim_' . bin2hex(random_bytes(8));
    
    $newPayment = [
        'unit_id' => $unitId,
        'tenant_id' => $tenantId,
        'numero_recu' => $numeroRecu,
        'montant' => $montantTotalTransaction,
        'mois' => $moisEnCours,
        'mode' => $modeMomo,
        'statut' => 'en_attente',
        'fedapay_id' => $tempFedaPayId,
        'date_paiement' => date('Y-m-d H:i:s')
    ];
    SupabaseClient::insert('payments', $newPayment);
    
    // Rendre un écran d'attente de Push USSD sur le téléphone locataire
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Simulation de Paiement MoMo - LokaGest</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="../css/style.css">
    </head>
    <body class="bg-light d-flex align-items-center justify-content-center min-vh-100">
        <div class="app-container shadow bg-white p-4 text-center rounded-4">
            
            <div class="my-4">
                <div class="spinner-grow text-success mb-3" style="width: 3rem; height: 3rem;" role="status">
                    <span class="visually-hidden">Chargement...</span>
                </div>
                <h4 class="fw-bold text-dark">Autorisation sur votre téléphone</h4>
                <p class="text-muted small">Un message push USSD de facturation vient d'être envoyé sur le numéro <b>+<?php echo $momoPhoneClean; ?></b>.</p>
            </div>
            
            <div class="card border-0 bg-light p-3 rounded-3 my-3 text-start small">
                <div class="d-flex justify-content-between mb-1">
                    <span>Opérateur :</span>
                    <b class="text-uppercase"><?php echo ($modeMomo === 'momo_mtn') ? 'MTN MoMo' : 'Moov Money'; ?></b>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span>Montant :</span>
                    <b class="text-success"><?php echo number_format($montantTotalTransaction, 0, ',', ' '); ?> FCFA</b>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Chambre :</span>
                    <b><?php echo htmlspecialchars($unit['code_unique']); ?></b>
                </div>
            </div>
            
            <div class="alert alert-warning border-0 small py-2 mb-3" role="alert">
                🔑 <b>Test de validation :</b> Tapez votre code PIN secret sur le téléphone simulé ci-dessous pour confirmer le loyer.
            </div>
            
            <div class="p-3 border rounded-3 bg-dark text-white font-monospace text-center mb-3">
                <p class="small text-white-50 mb-1">ÉCRAN MOBILE SIMULÉ (+<?php echo $momoPhoneClean; ?>)</p>
                <div class="p-2 bg-secondary rounded text-white small mb-3">
                    LokaGest : Confirmer loyer <?php echo date('m/Y'); ?> de <?php echo number_format($montantTotalTransaction, 0, ',', ' '); ?> F ?<br>
                    1. Oui (Confirmer)<br>
                    2. Non (Annuler)
                </div>
                <form action="webhook-fedapay.php" method="GET" class="d-flex gap-2 justify-content-center">
                    <input type="hidden" name="simulation_id" value="<?php echo $tempFedaPayId; ?>">
                    <input type="hidden" name="token" value="<?php echo $token; ?>">
                    <button type="submit" name="status" value="approved" class="btn btn-sm btn-success px-4">1 (Confirmer)</button>
                    <button type="submit" name="status" value="declined" class="btn btn-sm btn-danger px-4">2 (Annuler)</button>
                </form>
            </div>
            
            <div class="text-muted small" style="font-size: 0.72rem;">
                Le système basculera en mode FedaPay réel en configurant la clé d'API.
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
} else {
    // -------------------------------------------------------------
    // MODE DE PAIEMENT REEL VIA FEDAPAY (MTN / MOOV MOMO BENIN)
    // -------------------------------------------------------------
    
    // 1. Initialiser la transaction FedaPay
    $env = (getenv('FEDAPAY_ENV') === 'live') ? 'live' : 'sandbox';
    $baseUrl = ($env === 'live') ? 'https://api.fedapay.com' : 'https://api.fedapay.com'; // Point de terminaison unifié
    
    $transactionData = [
        'description' => 'Loyer LokaGest Chambre ' . $unit['code_unique'] . ' - Locataire: ' . $tenant['prenom'],
        'amount' => intval($montantTotalTransaction),
        'currency' => ['iso' => 'XOF'],
        'callback_url' => APP_URL . '/tenant/pay.php?token=' . $token . '&success=momo',
        'customer' => [
            'firstname' => $tenant['prenom'],
            'lastname' => 'LokaGest',
            'email' => !empty($tenant['email']) ? $tenant['email'] : 'locataire@lokagest.bj',
            'phone_number' => [
                'number' => substr($momoPhoneClean, 3), // Numéro à 8 chiffres
                'country' => 'bj'
            ]
        ]
    ];
    
    $ch = curl_init("$baseUrl/v1/transactions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($transactionData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $fedapaySecretKey",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $resData = json_decode($response, true);
    
    if ($httpCode !== 200 || !isset($resData['v1/transaction']['id'])) {
        // En cas de panne de l'API MoMo (F46)
        header('Location: ' . APP_URL . '/tenant/pay.php?token=' . $token . '&error=momo_down');
        exit;
    }
    
    $fedaPayId = $resData['v1/transaction']['id'];
    
    // 2. Insérer le paiement en_attente dans notre base Supabase
    $newPayment = [
        'unit_id' => $unitId,
        'tenant_id' => $tenantId,
        'numero_recu' => $numeroRecu,
        'montant' => $montantTotalTransaction,
        'mois' => $moisEnCours,
        'mode' => $modeMomo,
        'statut' => 'en_attente',
        'fedapay_id' => $fedaPayId,
        'date_paiement' => date('Y-m-d H:i:s')
    ];
    SupabaseClient::insert('payments', $newPayment);
    
    // 3. Déclencher le paiement direct MTN ou Moov via l'API de validation FedaPay
    $operator = ($modeMomo === 'momo_mtn') ? 'mtn' : 'moov';
    
    $payData = [
        'mode' => $operator
    ];
    
    $chPay = curl_init("$baseUrl/v1/transactions/$fedaPayId/pay");
    curl_setopt($chPay, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chPay, CURLOPT_POST, true);
    curl_setopt($chPay, CURLOPT_POSTFIELDS, json_encode($payData));
    curl_setopt($chPay, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $fedapaySecretKey",
        "Content-Type: application/json"
    ]);
    curl_setopt($chPay, CURLOPT_TIMEOUT, 15);
    
    $payResponse = curl_exec($chPay);
    $payHttpCode = curl_getinfo($chPay, CURLINFO_HTTP_CODE);
    curl_close($chPay);
    
    $payResData = json_decode($payResponse, true);
    
    // Si redirection requise ou URL de paiement fournie
    if (isset($payResData['v1/transaction']['url'])) {
        header('Location: ' . $payResData['v1/transaction']['url']);
        exit;
    } else {
        // Si validation directe asynchrone par push USSD commencée
        header('Location: ' . APP_URL . '/tenant/pay.php?token=' . $token . '&success=ussd_sent');
        exit;
    }
}
?>
