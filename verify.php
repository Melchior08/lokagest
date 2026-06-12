<?php
/**
 * LokaGest - Vérification Publique des Reçus
 * 
 * Permet à un locataire, un propriétaire ou une entité tierce (banque, employeur)
 * de vérifier la validité et l'authenticité d'un reçu de loyer LokaGest en saisissant
 * son empreinte unique SHA-256. (F51 / F73)
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/lib/supabase.php';

$recuHash = trim($_GET['recu'] ?? '');
$payment = null;
$tenant = null;
$unit = null;
$property = null;
$errorMsg = null;
$searched = false;

if (!empty($recuHash)) {
    $searched = true;
    
    // Rechercher le paiement par son numéro de reçu unique (hash SHA256)
    $payResponse = SupabaseClient::select('payments', '*', 'numero_recu=eq.' . $recuHash . '&statut=eq.confirme');
    
    if ($payResponse['status'] === 200 && !empty($payResponse['data'])) {
        $payment = $payResponse['data'][0];
        
        // Charger le locataire associé
        $tenantRes = SupabaseClient::select('tenants', '*', 'id=eq.' . $payment['tenant_id']);
        if ($tenantRes['status'] === 200 && !empty($tenantRes['data'])) {
            $tenant = $tenantRes['data'][0];
        }
        
        // Charger la chambre et la propriété
        $unitRes = SupabaseClient::select('units', '*', 'id=eq.' . $payment['unit_id']);
        if ($unitRes['status'] === 200 && !empty($unitRes['data'])) {
            $unit = $unitRes['data'][0];
            
            $propRes = SupabaseClient::select('properties', '*', 'id=eq.' . $unit['property_id']);
            if ($propRes['status'] === 200 && !empty($propRes['data'])) {
                $property = $propRes['data'][0];
            }
        }
    } else {
        $errorMsg = "⚠️ Aucun reçu authentique ne correspond à cette signature dans le système LokaGest. Ce document pourrait être falsifié ou le paiement n'est pas encore confirmé.";
    }
}

$pageTitle = 'Vérification reçu';
$basePath = '.';
$bodyClass = 'lokagest-app';
require_once __DIR__ . '/includes/head.php';
?>

    <div class="app-container min-vh-100 d-flex flex-column">
        <header class="verify-header text-start">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-search fs-4"></i>
                <h1 class="header-title text-white mb-0" style="font-size:1.2rem;">Vérifier un reçu</h1>
            </div>
            <p class="mb-0 small opacity-75">lokagest.bj/verifier — Page publique, aucun compte requis</p>
        </header>

        <main class="app-main text-start">
            <form action="verify.php" method="GET" class="mb-4 d-flex gap-2">
                <div class="flex-grow-1">
                    <label for="recu" class="form-label small fw-semibold text-secondary">Entrez le numéro de reçu :</label>
                    <input type="text" class="form-control rounded-3" id="recu" name="recu" placeholder="LKG-2026-… ou empreinte complète" value="<?php echo htmlspecialchars($recuHash); ?>" required autocomplete="off">
                </div>
                <div class="d-flex align-items-end">
                    <button type="submit" class="btn btn-nimbus">Vérifier</button>
                </div>
            </form>

            <?php if ($searched): ?>
                <?php if ($payment && $tenant && $unit && $property): ?>
                    <!-- Affichage de la certification de validité -->
                    <div class="cert-card animate-fade-in" style="border:2px solid var(--green);border-left-width:4px;">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <span class="fs-2 text-success"><i class="bi bi-check-circle-fill"></i></span>
                            <div>
                                <h5 class="fw-bold mb-0 text-success">Reçu authentique et valide</h5>
                                <span class="small text-muted">Données vérifiées dans la base LokaGest</span>
                            </div>
                        </div>
                        
                        <div class="d-flex flex-column gap-2 small">
                            <div class="d-flex justify-content-between border-bottom py-1">
                                <span class="text-muted">Locataire :</span>
                                <b class="text-dark"><?php echo htmlspecialchars($tenant['prenom']); ?></b>
                            </div>
                            <div class="d-flex justify-content-between border-bottom py-1">
                                <span class="text-muted">Chambre / Unité :</span>
                                <b class="text-success"><?php echo htmlspecialchars($unit['code_unique']); ?></b>
                            </div>
                            <div class="d-flex justify-content-between border-bottom py-1">
                                <span class="text-muted">Propriété :</span>
                                <b class="text-dark"><?php echo htmlspecialchars($property['nom']); ?></b>
                            </div>
                            <div class="d-flex justify-content-between border-bottom py-1">
                                <span class="text-muted">Mois de Loyer :</span>
                                <b class="text-dark"><?php echo date('m/Y', strtotime($payment['mois'] . '-01')); ?></b>
                            </div>
                            <div class="d-flex justify-content-between border-bottom py-1">
                                <span class="text-muted">Montant validé :</span>
                                <b class="text-success"><?php echo number_format($payment['montant'], 0, ',', ' '); ?> FCFA</b>
                            </div>
                            <div class="d-flex justify-content-between border-bottom py-1">
                                <span class="text-muted">Mode de paiement :</span>
                                <span class="text-uppercase fw-semibold"><?php echo htmlspecialchars($payment['mode']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between py-1">
                                <span class="text-muted">Date & Heure :</span>
                                <b class="text-dark"><?php echo date('d/m/Y H:i:s', strtotime($payment['date_paiement'])); ?></b>
                            </div>
                        </div>

                        <div class="mt-3 p-3 rounded-3 small" style="background:#EFF6FF;border:1px solid #BFDBFE;color:#1E40AF;">
                            <b>Page de vérification publique</b> — Toute personne peut vérifier l'authenticité d'un reçu. Les données affichées sont celles enregistrées au moment du paiement.
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Message d'erreur / Fraude (F73) -->
                    <div class="alert alert-danger border-0 shadow-sm p-4 text-center rounded-4 animate-fade-in" role="alert">
                        <div class="fs-1 text-danger mb-2"><i class="bi bi-x-octagon-fill"></i></div>
                        <h6 class="fw-bold mb-2">REÇU NON CERTIFIÉ</h6>
                        <p class="small mb-0"><?php echo htmlspecialchars($errorMsg); ?></p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

        </main>

        <!-- Footer -->
        <footer class="text-center mt-4 text-muted small" style="font-size: 0.72rem;">
            LokaGest Bénin Certification • PWA 2026.
        </footer>
    </div>

    <?php require_once __DIR__ . '/includes/scripts.php'; ?>
</body>
</html>
