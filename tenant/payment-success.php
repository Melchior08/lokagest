<?php
/**
 * LokaGest - Page de confirmation de paiement et reçu digital
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/supabase.php';

$recuHash = trim($_GET['recu'] ?? '');
$token = trim($_GET['token'] ?? '');

$payment = null;
$tenant = null;
$unit = null;
$property = null;
$pdfUrl = null;

if ($recuHash) {
    $payRes = SupabaseClient::select('payments', '*', 'numero_recu=eq.' . $recuHash . '&statut=eq.confirme');
    if ($payRes['status'] === 200 && !empty($payRes['data'])) {
        $payment = $payRes['data'][0];
        $tenantRes = SupabaseClient::select('tenants', '*', 'id=eq.' . $payment['tenant_id']);
        if ($tenantRes['status'] === 200 && !empty($tenantRes['data'])) {
            $tenant = $tenantRes['data'][0];
        }
        $unitRes = SupabaseClient::select('units', '*', 'id=eq.' . $payment['unit_id']);
        if ($unitRes['status'] === 200 && !empty($unitRes['data'])) {
            $unit = $unitRes['data'][0];
            $propRes = SupabaseClient::select('properties', '*', 'id=eq.' . $unit['property_id']);
            if ($propRes['status'] === 200 && !empty($propRes['data'])) {
                $property = $propRes['data'][0];
            }
        }
        $pdfPath = __DIR__ . '/../uploads/receipts/Recu_' . $payment['numero_recu'] . '.pdf';
        if (file_exists($pdfPath)) {
            $pdfUrl = APP_URL . '/uploads/receipts/Recu_' . $payment['numero_recu'] . '.pdf';
        }
    }
}

if (!$payment) {
    header('Location: ' . APP_URL . '/tenant/pay.php' . ($token ? '?token=' . urlencode($token) : ''));
    exit;
}

$modeLabel = match ($payment['mode']) {
    'momo_mtn' => 'MTN MoMo',
    'momo_moov' => 'Moov Money',
    'especes' => 'Espèces',
    default => strtoupper($payment['mode']),
};

$pageTitle = 'Paiement confirmé';
$basePath = '..';
$bodyClass = 'lokagest-app';
require_once __DIR__ . '/../includes/head.php';
?>

<div class="app-container min-vh-100">
    <div class="receipt-success-header">
        <div class="fs-1 mb-2">✅</div>
        <h1 class="fw-bold mb-1" style="font-size:1.4rem;">Paiement confirmé !</h1>
        <p class="mb-0 small opacity-90">Votre reçu a été envoyé par WhatsApp</p>
    </div>

    <div class="receipt-card text-start">
        <p class="text-muted small text-center mb-3">N° REÇU : <?php echo strtoupper(substr($payment['numero_recu'], 0, 20)); ?></p>

        <p class="text-center text-muted small mb-1">Montant payé</p>
        <div class="receipt-amount mb-1"><?php echo number_format($payment['montant'], 0, ',', ' '); ?> FCFA</div>

        <hr class="my-3">

        <div class="d-flex justify-content-between py-2 border-bottom small">
            <span class="text-muted">Locataire</span>
            <b><?php echo htmlspecialchars($tenant['prenom'] ?? ''); ?></b>
        </div>
        <div class="d-flex justify-content-between py-2 border-bottom small">
            <span class="text-muted">Propriété</span>
            <b><?php echo htmlspecialchars($property['nom'] ?? ''); ?></b>
        </div>
        <div class="d-flex justify-content-between py-2 border-bottom small">
            <span class="text-muted">Chambre</span>
            <b><?php echo htmlspecialchars($unit['code_unique'] ?? ''); ?></b>
        </div>
        <div class="d-flex justify-content-between py-2 border-bottom small">
            <span class="text-muted">Mois</span>
            <b><?php
            $moisFr = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
            echo ucfirst($moisFr[(int)date('n', strtotime($payment['mois'] . '-01'))]) . ' ' . date('Y', strtotime($payment['mois'] . '-01'));
            ?></b>
        </div>
        <div class="d-flex justify-content-between py-2 border-bottom small">
            <span class="text-muted">Mode</span>
            <span class="badge bg-success-subtle text-success rounded-pill"><?php echo $modeLabel; ?></span>
        </div>
        <div class="d-flex justify-content-between py-2 small">
            <span class="text-muted">Date & heure</span>
            <b><?php echo date('d/m/Y à H:i:s', strtotime($payment['date_paiement'])); ?></b>
        </div>

        <p class="text-muted text-center mt-3 mb-0" style="font-size:0.7rem;">Ce reçu est une preuve légale de paiement — LokaGest</p>
    </div>

    <?php if ($tenant): ?>
    <div class="alert alert-success border-0 mx-3 small py-2">
        <i class="bi bi-whatsapp"></i> Ce reçu PDF a été envoyé sur votre WhatsApp (+229 <?php echo htmlspecialchars($tenant['telephone']); ?>)
    </div>
    <?php endif; ?>

    <div class="d-flex gap-2 px-3 pb-4">
        <?php if ($pdfUrl): ?>
        <a href="<?php echo htmlspecialchars($pdfUrl); ?>" target="_blank" class="btn btn-nimbus flex-fill">
            <i class="bi bi-download"></i> Télécharger PDF
        </a>
        <?php endif; ?>
        <a href="<?php echo APP_URL; ?>/verify.php?recu=<?php echo urlencode($payment['numero_recu']); ?>" class="btn btn-nimbus-outline flex-fill">
            <i class="bi bi-search"></i> Vérifier
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/scripts.php'; ?>
</body>
</html>
