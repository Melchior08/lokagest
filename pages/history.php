<?php
/**
 * LokaGest - Historique des Paiements
 * 
 * Permet de visualiser l'historique global de tous les loyers réglés,
 * d'appliquer des filtres multicritères et d'exporter en format CSV ou PDF/Imprimable.
 */

require_once __DIR__ . '/../lib/auth_guard.php';
require_once __DIR__ . '/../lib/supabase.php';

$user = $_SESSION['user'];
$userId = $user['id'];

// 1. Récupérer les filtres de recherche
$filterProperty = $_GET['property_id'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterMois = $_GET['mois'] ?? '';

// Récupérer les propriétés du propriétaire pour le sélecteur
$properties = [];
$propResponse = SupabaseClient::select('properties', '*', 'user_id=eq.' . $userId);
if ($propResponse['status'] === 200 && is_array($propResponse['data'])) {
    $properties = $propResponse['data'];
}
$propertyIds = array_column($properties, 'id');

// Récupérer les chambres correspondantes pour les filtres et associations
$unitCodes = [];
$unitMap = [];
if (!empty($propertyIds)) {
    $propFilter = 'property_id=in.(' . implode(',', $propertyIds) . ')';
    $unitsRes = SupabaseClient::select('units', '*', $propFilter);
    if ($unitsRes['status'] === 200 && is_array($unitsRes['data'])) {
        foreach ($unitsRes['data'] as $u) {
            $unitCodes[$u['id']] = $u['code_unique'];
            $unitMap[$u['id']] = $u;
        }
    }
}

// 2. Construire la requête de récupération des paiements
$payments = [];
if (!empty($unitMap)) {
    $unitIds = array_keys($unitMap);
    
    // Filtre de base : toutes les chambres de l'utilisateur
    $query = 'unit_id=in.(' . implode(',', $unitIds) . ')';
    
    // Appliquer filtres optionnels
    if (!empty($filterProperty)) {
        // Filtrer les unités de cette propriété uniquement
        $propUnitsRes = SupabaseClient::select('units', 'id', 'property_id=eq.' . $filterProperty);
        $propUnitIds = [];
        if ($propUnitsRes['status'] === 200 && is_array($propUnitsRes['data'])) {
            $propUnitIds = array_column($propUnitsRes['data'], 'id');
        }
        
        if (!empty($propUnitIds)) {
            $query = 'unit_id=in.(' . implode(',', $propUnitIds) . ')';
        } else {
            // Forcer un tableau vide s'il n'y a pas d'unités pour cette propriété
            $query = 'unit_id=eq.00000000-0000-0000-0000-000000000000';
        }
    }
    
    if (!empty($filterStatus)) {
        $query .= '&statut=eq.' . $filterStatus;
    }
    
    if (!empty($filterMois)) {
        $query .= '&mois=eq.' . $filterMois;
    }
    
    $query .= '&order=date_paiement.desc';
    
    $paymentsRes = SupabaseClient::select('payments', '*', $query);
    if ($paymentsRes['status'] === 200 && is_array($paymentsRes['data'])) {
        $payments = $paymentsRes['data'];
    }
}

// 3. Charger le nom des locataires pour chaque paiement
$tenantsMap = [];
if (!empty($payments)) {
    $tenantIds = array_unique(array_column($payments, 'tenant_id'));
    $tenantIdsList = implode(',', $tenantIds);
    $tenantsRes = SupabaseClient::select('tenants', 'id, prenom, telephone', 'id=in.(' . $tenantIdsList . ')');
    if ($tenantsRes['status'] === 200 && is_array($tenantsRes['data'])) {
        foreach ($tenantsRes['data'] as $t) {
            $tenantsMap[$t['id']] = $t;
        }
    }
}

// -------------------------------------------------------------
// TRAITEMENT EXPORT CSV (F16)
// -------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Vider le tampon de sortie
    ob_end_clean();
    
    // Configurer les headers HTTP
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Historique_Paiements_LokaGest_' . date('Ymd_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Écrire l'en-tête de colonnes (avec BOM UTF-8 pour Excel)
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['Date', 'Reçu N°', 'Chambre', 'Locataire', 'Téléphone', 'Mois de Loyer', 'Montant (FCFA)', 'Mode', 'Statut']);
    
    foreach ($payments as $p) {
        $locName = $tenantsMap[$p['tenant_id']]['prenom'] ?? 'Inconnu';
        $locPhone = $tenantsMap[$p['tenant_id']]['telephone'] ?? '';
        $chName = $unitCodes[$p['unit_id']] ?? 'N/A';
        $moisFmt = date('m/Y', strtotime($p['mois'] . '-01'));
        
        fputcsv($output, [
            date('d/m/Y H:i:s', strtotime($p['date_paiement'])),
            $p['numero_recu'],
            $chName,
            $locName,
            '+229 ' . $locPhone,
            $moisFmt,
            $p['montant'],
            $p['mode'],
            $p['statut']
        ]);
    }
    
    fclose($output);
    exit;
}

// -------------------------------------------------------------
// TRAITEMENT IMPRESSION PDF (F16)
// -------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once __DIR__ . '/../lib/pdf_generator.php';
    // Utiliser TCPDF pour générer la liste filtrée
    // Pour simplifier l'accès, on renvoie une version HTML d'impression de table premium
    ob_end_clean();
    
    echo "<html><head><title>Historique des Paiements - LokaGest</title>";
    echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>";
    echo "<style>
        body { padding: 25px; font-family: sans-serif; background: #fff; }
        @media print {
            .no-print { display: none !important; }
        }
    </style></head><body>";
    
    echo "<div class='no-print text-center mb-4'><button class='btn btn-success rounded-pill px-4 fw-bold' onclick='window.print()'>🖨️ Imprimer le rapport</button> <a href='history.php' class='btn btn-light rounded-pill border ms-2'>Retour</a></div>";
    echo "<div class='container'>";
    echo "<div class='d-flex justify-content-between align-items-center mb-4 border-bottom pb-2'>";
    echo "<div><h2 class='text-success fw-bold m-0'>LokaGest</h2><span class='text-muted small'>Bilan d'historique des loyers</span></div>";
    echo "<div class='text-end'><span class='fw-bold'>" . date('d/m/Y') . "</span><br><span class='small text-muted'>Propriétaire : " . htmlspecialchars($user['prenom']) . "</span></div>";
    echo "</div>";
    
    echo "<table class='table table-bordered table-striped table-sm small'>";
    echo "<thead class='table-success'><tr><th>Date</th><th>Chambre</th><th>Locataire</th><th>Loyer Mois</th><th>Montant</th><th>Mode</th><th>Statut</th></tr></thead><tbody>";
    
    $totalAmount = 0;
    foreach ($payments as $p) {
        $locName = $tenantsMap[$p['tenant_id']]['prenom'] ?? 'Inconnu';
        $chName = $unitCodes[$p['unit_id']] ?? 'N/A';
        $moisFmt = date('m/Y', strtotime($p['mois'] . '-01'));
        $totalAmount += $p['montant'];
        
        echo "<tr>";
        echo "<td>" . date('d/m/Y H:i', strtotime($p['date_paiement'])) . "</td>";
        echo "<td>" . htmlspecialchars($chName) . "</td>";
        echo "<td>" . htmlspecialchars($locName) . "</td>";
        echo "<td>" . $moisFmt . "</td>";
        echo "<td class='fw-bold'>" . number_format($p['montant'], 0, ',', ' ') . " F</td>";
        echo "<td>" . htmlspecialchars($p['mode']) . "</td>";
        echo "<td>" . htmlspecialchars($p['statut']) . "</td>";
        echo "</tr>";
    }
    
    echo "</tbody></table>";
    echo "<div class='text-end fw-bold fs-5 mt-3 border-top pt-2'>TOTAL RECETTE ENCAISSÉE : " . number_format($totalAmount, 0, ',', ' ') . " FCFA</div>";
    echo "</div></body></html>";
    exit;
}

$pageTitle = 'Historique';
$activeNav = 'history';
$basePath = '..';
require_once __DIR__ . '/../includes/head.php';
?>

    <div class="app-container has-nav">
        <header class="app-header">
            <div class="header-inner d-flex align-items-center gap-3">
                <a href="dashboard.php" class="header-back"><i class="bi bi-arrow-left"></i></a>
                <div>
                    <h1 class="header-title mb-0">Historique</h1>
                    <div class="header-sub">Tous vos paiements de loyers</div>
                </div>
            </div>
        </header>

        <main class="app-main text-start">
            <div class="nimbus-card nimbus-card-body mb-3">
                <form action="history.php" method="GET" class="row g-2">
                    <div class="col-12">
                        <label for="property_id" class="form-label small fw-semibold text-secondary mb-1">Filtrer par propriété</label>
                        <select class="form-select form-select-sm rounded-3" id="property_id" name="property_id">
                            <option value="">-- Toutes les propriétés --</option>
                            <?php foreach ($properties as $prop): ?>
                                <option value="<?php echo $prop['id']; ?>" <?php echo $filterProperty === $prop['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($prop['nom']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6">
                        <label for="status" class="form-label small fw-semibold text-secondary mb-1">Statut</label>
                        <select class="form-select form-select-sm rounded-3" id="status" name="status">
                            <option value="">-- Tous --</option>
                            <option value="confirme" <?php echo $filterStatus === 'confirme' ? 'selected' : ''; ?>>Confirmé</option>
                            <option value="en_attente" <?php echo $filterStatus === 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                            <option value="echoue" <?php echo $filterStatus === 'echoue' ? 'selected' : ''; ?>>Échoué</option>
                        </select>
                    </div>

                    <div class="col-6">
                        <label for="mois" class="form-label small fw-semibold text-secondary mb-1">Mois</label>
                        <input type="month" class="form-control form-control-sm rounded-3" id="mois" name="mois" value="<?php echo htmlspecialchars($filterMois); ?>">
                    </div>

                    <div class="col-12 mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-nimbus btn-sm w-50 py-2">
                            <i class="bi bi-funnel-fill me-1"></i>Filtrer
                        </button>
                        <a href="history.php" class="btn btn-nimbus-outline btn-sm w-50 py-2 d-inline-flex align-items-center justify-content-center text-decoration-none">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Boutons d'exportation -->
            <?php if (!empty($payments)): ?>
                <div class="d-flex gap-2 mb-4">
                    <a href="?export=csv&property_id=<?php echo $filterProperty; ?>&status=<?php echo $filterStatus; ?>&mois=<?php echo $filterMois; ?>" class="btn btn-outline-success btn-sm w-50 py-2 rounded-3 fw-bold">
                        <i class="bi bi-filetype-csv me-1"></i>Exporter en CSV
                    </a>
                    <a href="?export=pdf&property_id=<?php echo $filterProperty; ?>&status=<?php echo $filterStatus; ?>&mois=<?php echo $filterMois; ?>" target="_blank" class="btn btn-outline-success btn-sm w-50 py-2 rounded-3 fw-bold">
                        <i class="bi bi-file-pdf me-1"></i>Imprimer / PDF
                    </a>
                </div>
            <?php endif; ?>

            <!-- Résultats de la recherche -->
            <h6 class="fw-bold text-dark mb-3">Reçus enregistrés</h6>

            <?php if (empty($payments)): ?>
                <div class="card border-0 rounded-4 shadow-sm p-4 text-center">
                    <p class="text-muted small mb-0">Aucun paiement trouvé avec les critères de recherche.</p>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($payments as $p): ?>
                        <?php 
                        $locName = $tenantsMap[$p['tenant_id']]['prenom'] ?? 'Locataire';
                        $chName = $unitCodes[$p['unit_id']] ?? 'Chambre';
                        $isConf = ($p['statut'] === 'confirme');
                        $isFail = ($p['statut'] === 'echoue');
                        
                        $badgeClass = 'bg-success-subtle text-success';
                        if ($isFail) $badgeClass = 'bg-danger-subtle text-danger';
                        elseif (!$isConf) $badgeClass = 'bg-warning-subtle text-warning';
                        ?>
                        <div class="card border-0 rounded-3 shadow-sm p-3 d-flex flex-row justify-content-between align-items-center">
                            <div>
                                <b class="text-dark" style="font-size: 0.85rem;"><?php echo htmlspecialchars($chName); ?> — <?php echo htmlspecialchars($locName); ?></b>
                                <span class="d-block text-muted mt-1" style="font-size: 0.7rem;">
                                    Mois : <?php echo date('m/Y', strtotime($p['mois'] . '-01')); ?> • Mode : <?php echo htmlspecialchars($p['mode']); ?><br>
                                    Enregistré le : <?php echo date('d/m/Y H:i', strtotime($p['date_paiement'])); ?>
                                </span>
                            </div>
                            <div class="text-end">
                                <span class="fw-bold text-success d-block" style="font-size: 0.95rem;"><?php echo number_format($p['montant'], 0, ',', ' '); ?> F</span>
                                <span class="badge <?php echo $badgeClass; ?> rounded-pill" style="font-size: 0.65rem;">
                                    <?php echo htmlspecialchars($p['statut']); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </main>

        <?php require_once __DIR__ . '/../includes/nav.php'; ?>
    </div>
    <?php require_once __DIR__ . '/../includes/scripts.php'; ?>
</body>
</html>
