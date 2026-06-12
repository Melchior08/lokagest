<?php
/**
 * LokaGest - Tableau de bord du Propriétaire
 */

require_once __DIR__ . '/../lib/auth_guard.php';
require_once __DIR__ . '/../lib/supabase.php';

$user = $_SESSION['user'];
$userId = $user['id'];

$totalChambres = 0;
$chambresOccupees = 0;
$chambresLibres = 0;
$soldeWallet = 0;
$totalEncaisseMois = 0;
$locatairesEnRetard = [];
$roomStatuses = [];
$nbProprietes = 0;

$propertiesResponse = SupabaseClient::select('properties', 'id,nom', 'user_id=eq.' . $userId);
$propertyIds = [];
$propertyNames = [];
if ($propertiesResponse['status'] === 200 && !empty($propertiesResponse['data'])) {
    $nbProprietes = count($propertiesResponse['data']);
    foreach ($propertiesResponse['data'] as $prop) {
        $propertyIds[] = $prop['id'];
        $propertyNames[$prop['id']] = $prop['nom'];
    }
}

$units = [];
if (!empty($propertyIds)) {
    $propertyIdsFilter = 'property_id=in.(' . implode(',', $propertyIds) . ')';
    $unitsResponse = SupabaseClient::select('units', '*', $propertyIdsFilter . '&order=code_unique.asc');

    if ($unitsResponse['status'] === 200 && !empty($unitsResponse['data'])) {
        $units = $unitsResponse['data'];
        $totalChambres = count($units);

        foreach ($units as $unit) {
            if ($unit['statut'] === 'occupee') {
                $chambresOccupees++;
            } elseif ($unit['statut'] === 'libre') {
                $chambresLibres++;
            }
        }
    }
}

$walletResponse = SupabaseClient::select('wallets', 'solde', 'user_id=eq.' . $userId);
if ($walletResponse['status'] === 200 && !empty($walletResponse['data'])) {
    $soldeWallet = $walletResponse['data'][0]['solde'];
}

$moisEnCours = date('Y-m');
$moisEnLettres = [
    '01' => 'Janvier', '02' => 'Février', '03' => 'Mars', '04' => 'Avril',
    '05' => 'Mai', '06' => 'Juin', '07' => 'Juillet', '08' => 'Août',
    '09' => 'Septembre', '10' => 'Octobre', '11' => 'Novembre', '12' => 'Décembre'
];
$nomMoisActuel = $moisEnLettres[date('m')] . ' ' . date('Y');
$jourActuel = (int) date('d');

if (!empty($units)) {
    $unitIds = array_column($units, 'id');
    $unitIdsList = implode(',', $unitIds);

    $tenantsByUnit = [];
    $tenantsResponse = SupabaseClient::select('tenants', '*', 'unit_id=in.(' . $unitIdsList . ')&statut=eq.actif');
    if ($tenantsResponse['status'] === 200 && !empty($tenantsResponse['data'])) {
        foreach ($tenantsResponse['data'] as $t) {
            $tenantsByUnit[$t['unit_id']] = $t;
        }
    }

    $paymentsByUnit = [];
    $paymentsResponse = SupabaseClient::select(
        'payments',
        '*',
        'unit_id=in.(' . $unitIdsList . ')&statut=eq.confirme&mois=eq.' . $moisEnCours
    );
    if ($paymentsResponse['status'] === 200 && !empty($paymentsResponse['data'])) {
        foreach ($paymentsResponse['data'] as $pay) {
            $paymentsByUnit[$pay['unit_id']] = $pay;
            $totalEncaisseMois += $pay['montant'];
        }
    }

    foreach ($units as $unit) {
        $propName = $propertyNames[$unit['property_id']] ?? '';
        $tenant = $tenantsByUnit[$unit['id']] ?? null;
        $status = 'free';
        $statusLabel = 'Libre';
        $subtitle = 'Chambre libre';
        $dotClass = 'free';
        $pillClass = 'free';

        if ($unit['statut'] === 'occupee' && $tenant) {
            $isPaid = isset($paymentsByUnit[$unit['id']]);
            if ($isPaid) {
                $pay = $paymentsByUnit[$unit['id']];
                $status = 'paid';
                $statusLabel = 'Payé ✓';
                $subtitle = htmlspecialchars($tenant['prenom']) . ' · Payé le ' . date('j', strtotime($pay['date_paiement'])) . ' ' . strtolower($moisEnLettres[date('m', strtotime($pay['date_paiement']))]);
                $dotClass = 'paid';
                $pillClass = 'paid';
            } elseif ($jourActuel > 5) {
                $joursRetard = $jourActuel - 5;
                $status = 'late';
                $statusLabel = 'Retard';
                $subtitle = htmlspecialchars($tenant['prenom']) . ' · ' . $joursRetard . ' jours de retard';
                $dotClass = 'late';
                $pillClass = 'late';
                $locatairesEnRetard[] = [
                    'tenant' => $tenant,
                    'chambre' => $unit,
                    'jours_retard' => $joursRetard
                ];
            } else {
                $status = 'wait';
                $statusLabel = 'En attente';
                $subtitle = htmlspecialchars($tenant['prenom']) . ' · Échéance le 5';
                $dotClass = 'paid';
                $pillClass = 'wait';
            }
        }

        $roomStatuses[] = [
            'unit' => $unit,
            'propName' => $propName,
            'subtitle' => $subtitle,
            'statusLabel' => $statusLabel,
            'dotClass' => $dotClass,
            'pillClass' => $pillClass,
        ];
    }
}

$pageTitle = 'Tableau de bord';
$activeNav = 'dashboard';
$basePath = '..';
require_once __DIR__ . '/../includes/head.php';
?>

    <div class="app-container has-nav">
        <header class="app-header">
            <div class="header-inner d-flex justify-content-between align-items-start">
                <div>
                    <div class="header-greeting">Bonjour <?php echo htmlspecialchars($user['prenom']); ?> 👋</div>
                    <div class="header-sub"><?php echo $nomMoisActuel; ?> · <?php echo $nbProprietes; ?> propriété<?php echo $nbProprietes > 1 ? 's' : ''; ?></div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($user['fondateur']): ?>
                        <span class="badge-founder">⚡ Fondateur</span>
                    <?php endif; ?>
                    <span class="header-avatar opacity-75"><i class="bi bi-bell"></i></span>
                </div>
            </div>
            <?php if (isset($_SESSION['welcome_fondateur']) && $_SESSION['welcome_fondateur'] === true): ?>
                <div class="alert alert-warning mt-3 mb-0 py-2 small animate-fade-in">
                    🎉 Vous êtes parmi les <strong>50 premiers</strong> — Commission 0% à vie !
                </div>
                <?php unset($_SESSION['welcome_fondateur']); ?>
            <?php endif; ?>
        </header>

        <main class="app-main">
            <div class="wallet-float animate-fade-in">
                <div class="wallet-label text-muted small">Solde disponible dans votre wallet</div>
                <div class="wallet-amount"><?php echo number_format($soldeWallet, 0, ',', ' '); ?> <span class="fs-6 text-muted">FCFA</span></div>
                <?php if ($totalEncaisseMois > 0): ?>
                <div class="wallet-trend">↑ +<?php echo number_format($totalEncaisseMois, 0, ',', ' '); ?> FCFA encaissés ce mois</div>
                <?php endif; ?>
                <div class="wallet-float-actions">
                    <a href="wallet.php" class="btn-wallet-primary">
                        ↑ Retirer vers MoMo
                    </a>
                    <a href="history.php" class="btn-wallet-secondary">
                        📋 Historique
                    </a>
                </div>
            </div>

            <div class="stat-stack mb-3">
                <div class="stat-stack-card">
                    <span class="ss-label">Chambres total</span>
                    <span class="ss-value"><?php echo $totalChambres; ?></span>
                </div>
                <div class="stat-stack-card">
                    <span class="ss-label">Occupées</span>
                    <span class="ss-value green"><?php echo $chambresOccupees; ?></span>
                </div>
                <div class="stat-stack-card">
                    <span class="ss-label">Libres</span>
                    <span class="ss-value grey"><?php echo $chambresLibres; ?></span>
                </div>
                <div class="stat-stack-card">
                    <span class="ss-label">En retard ⚠</span>
                    <span class="ss-value red"><?php echo count($locatairesEnRetard); ?></span>
                </div>
            </div>

            <?php if (!empty($locatairesEnRetard)): ?>
            <div class="mb-3 text-end">
                <button type="button" id="btn-remind-all" class="btn btn-nimbus btn-sm py-1 px-3" style="font-size:0.75rem;">
                    <i class="bi bi-whatsapp"></i> Tout relancer
                </button>
            </div>
            <?php endif; ?>

            <div class="mb-4">
                <h2 class="room-section-title">Statut des chambres — <?php echo $nomMoisActuel; ?></h2>
                <div class="legend-bar">
                    Rouge = retard urgent · Vert = tout payé · Gris = libre
                </div>
                <?php if (empty($roomStatuses)): ?>
                    <div class="empty-state py-4">
                        <p class="text-muted small mb-2">Aucune chambre enregistrée.</p>
                        <a href="properties.php?action=add" class="btn btn-nimbus btn-sm">Créer ma propriété</a>
                    </div>
                <?php else: ?>
                    <div class="room-status-list">
                        <?php foreach ($roomStatuses as $rs):
                            $u = $rs['unit']; ?>
                        <a href="unit-detail.php?id=<?php echo $u['id']; ?>" class="room-row">
                            <span class="room-dot <?php echo $rs['dotClass']; ?>"></span>
                            <div class="flex-grow-1 min-width-0">
                                <div class="fw-bold small"><?php echo htmlspecialchars($u['code_unique']); ?> · <?php echo htmlspecialchars($rs['propName']); ?></div>
                                <div class="text-muted" style="font-size:0.72rem;"><?php echo $rs['subtitle']; ?></div>
                            </div>
                            <span class="status-pill <?php echo $rs['pillClass']; ?>"><?php echo $rs['statusLabel']; ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="hint-bar">
                    Clic sur une ligne → fiche détail de la chambre
                </div>
            </div>
        </main>

        <?php require_once __DIR__ . '/../includes/nav.php'; ?>
    </div>
    <?php require_once __DIR__ . '/../includes/scripts.php'; ?>
    <script>
    document.getElementById('btn-remind-all')?.addEventListener('click', async function() {
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        try {
            const res = await fetch('../api/remind-batch.php', { method: 'POST' });
            const data = await res.json();
            alert(data.message || (data.error ?? 'Erreur'));
        } catch (e) {
            alert('Erreur réseau');
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-whatsapp"></i> Tout relancer';
    });
    </script>
</body>
</html>
