<?php
/**
 * LokaGest - Détail de la Propriété et Gestion des Chambres
 * 
 * Permet de visualiser toutes les chambres d'une propriété spécifique,
 * d'en ajouter de nouvelles (avec génération de code unique permanent LKG-[QUARTIER]-CH[N])
 * et de gérer le lien du groupe WhatsApp de l'immeuble.
 */

require_once __DIR__ . '/../lib/auth_guard.php';
require_once __DIR__ . '/../lib/supabase.php';

$user = $_SESSION['user'];
$userId = $user['id'];

$propertyId = $_GET['id'] ?? null;

if (!$propertyId) {
    header('Location: properties.php');
    exit;
}

// 1. Récupérer les détails de la propriété
$propertyResponse = SupabaseClient::select('properties', '*', 'id=eq.' . $propertyId . '&user_id=eq.' . $userId);

if ($propertyResponse['status'] !== 200 || empty($propertyResponse['data'])) {
    header('Location: properties.php');
    exit;
}

$property = $propertyResponse['data'][0];

$errorMsg = null;
$successMsg = null;

// 2. Traitement d'ajout de chambre (Unit) - F7
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_unit'])) {
    $type = trim($_POST['type'] ?? 'Chambre');
    $loyerReference = floatval($_POST['loyer_reference'] ?? 0);
    
    if ($loyerReference <= 0) {
        $errorMsg = "Le loyer de référence doit être supérieur à 0 FCFA.";
    } else {
        // Génération du code unique permanent LKG-[QUARTIER]-CH[N]
        // Nettoyer le quartier pour la nomenclature
        $quartierClean = preg_replace('/[^a-zA-Z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $property['quartier']));
        $quartierClean = strtoupper($quartierClean);
        
        // Compter les chambres existantes dans la propriété
        $unitsCountRes = SupabaseClient::select('units', 'id', 'property_id=eq.' . $propertyId);
        $nextNum = 1;
        if ($unitsCountRes['status'] === 200 && is_array($unitsCountRes['data'])) {
            $nextNum = count($unitsCountRes['data']) + 1;
        }
        
        $codeUnique = "LKG-" . $quartierClean . "-CH" . $nextNum;
        
        // Vérifier si le code existe déjà pour éviter les doublons
        $checkCode = SupabaseClient::select('units', 'id', 'code_unique=eq.' . $codeUnique);
        while ($checkCode['status'] === 200 && !empty($checkCode['data'])) {
            $nextNum++;
            $codeUnique = "LKG-" . $quartierClean . "-CH" . $nextNum;
            $checkCode = SupabaseClient::select('units', 'id', 'code_unique=eq.' . $codeUnique);
        }
        
        $newUnit = [
            'property_id' => $propertyId,
            'code_unique' => $codeUnique,
            'type' => $type,
            'loyer_reference' => $loyerReference,
            'statut' => 'libre',
            'photo_url' => 'https://images.unsplash.com/photo-1522771739844-6a9f6d5f14af?auto=format&fit=crop&w=400&q=80', // Placeholder par défaut
            'date_creation' => date('Y-m-d H:i:s')
        ];
        
        $insertResponse = SupabaseClient::insert('units', $newUnit);
        
        if ($insertResponse['status'] >= 200 && $insertResponse['status'] < 300) {
            $successMsg = "Chambre créée avec succès sous le code permanent : $codeUnique";
        } else {
            $errorMsg = "Erreur lors de la création de la chambre : " . ($insertResponse['error'] ?? 'Inconnue');
        }
    }
}

// 3. Récupérer toutes les chambres (units) de la propriété
$units = [];
$unitsResponse = SupabaseClient::select('units', '*', 'property_id=eq.' . $propertyId . '&order=code_unique.asc');
if ($unitsResponse['status'] === 200 && is_array($unitsResponse['data'])) {
    $units = $unitsResponse['data'];
}

// 4. Récupérer les locataires associés pour afficher leurs noms et l'état de leur loyer
$tenantsByUnit = [];
$paymentsByUnit = [];
$moisEnCours = date('Y-m');

if (!empty($units)) {
    $unitIds = array_column($units, 'id');
    $unitIdsList = implode(',', $unitIds);
    
    // Locataires actifs
    $tenantsRes = SupabaseClient::select('tenants', '*', 'unit_id=in.(' . $unitIdsList . ')&statut=eq.actif');
    if ($tenantsRes['status'] === 200 && !empty($tenantsRes['data'])) {
        foreach ($tenantsRes['data'] as $tenant) {
            $tenantsByUnit[$tenant['unit_id']] = $tenant;
        }
    }
    
    // Paiements confirmés du mois en cours
    $paymentsRes = SupabaseClient::select('payments', '*', 'unit_id=in.(' . $unitIdsList . ')&statut=eq.confirme&mois=eq.' . $moisEnCours);
    if ($paymentsRes['status'] === 200 && !empty($paymentsRes['data'])) {
        foreach ($paymentsRes['data'] as $pay) {
            $paymentsByUnit[$pay['unit_id']] = $pay;
        }
    }
}

$pageTitle = $property['nom'];
$activeNav = 'properties';
$basePath = '..';
require_once __DIR__ . '/../includes/head.php';
?>

    <div class="app-container has-nav">
        <header class="app-header">
            <div class="header-inner d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <a href="properties.php" class="header-back"><i class="bi bi-arrow-left"></i></a>
                    <div>
                        <h1 class="header-title mb-0"><?php echo htmlspecialchars($property['nom']); ?></h1>
                        <div class="header-sub"><?php echo count($units); ?> chambre<?php echo count($units) > 1 ? 's' : ''; ?></div>
                    </div>
                </div>
                <button type="button" class="btn-header-action" data-bs-toggle="modal" data-bs-target="#addUnitModal">+ Chambre</button>
            </div>
        </header>

        <main class="app-main">
            
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger border-0 shadow-sm small py-2 mb-3" role="alert">
                    <?php echo htmlspecialchars($errorMsg); ?>
                </div>
            <?php endif; ?>

            <?php if ($successMsg): ?>
                <div class="alert alert-success border-0 shadow-sm small py-2 mb-3" role="alert">
                    <?php echo htmlspecialchars($successMsg); ?>
                </div>
            <?php endif; ?>

            <!-- Détails de l'immeuble et WhatsApp de groupe -->
            <div class="card border-0 rounded-4 shadow-sm p-3 mb-4 bg-light">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <span class="text-muted small"><i class="bi bi-geo-alt-fill me-1"></i><?php echo htmlspecialchars($property['adresse']); ?></span>
                        <h6 class="text-dark small mt-1 mb-0"><?php echo htmlspecialchars($property['quartier'] . ', ' . $property['ville'] . ' (Bénin)'); ?></h6>
                    </div>
                </div>
                
                <!-- Lien groupe WhatsApp immeuble (F35) -->
                <div class="mt-3 pt-3 border-top border-secondary-subtle d-flex justify-content-between align-items-center">
                    <div>
                        <span class="d-block small text-muted">Groupe WhatsApp de l'immeuble</span>
                        <span class="small text-success fw-semibold"><i class="bi bi-whatsapp me-1"></i>Groupe locataires</span>
                    </div>
                    <button class="btn btn-outline-success btn-sm rounded-pill px-3 fw-bold" onclick="shareGroupLink()">
                        Partager <i class="bi bi-share"></i>
                    </button>
                </div>
            </div>

            <!-- Liste des Chambres -->
            <h6 class="fw-bold text-dark mb-3">Liste des Chambres / Unités</h6>
            
            <?php if (empty($units)): ?>
                <div class="text-center py-5 border-dashed rounded-4 p-4">
                    <div class="text-muted display-6 mb-2"><i class="bi bi-house-door"></i></div>
                    <h6 class="fw-semibold">Aucune chambre dans cette propriété</h6>
                    <p class="text-muted small mb-3">Ajoutez des chambres pour y affecter des locataires et suivre leurs loyers.</p>
                    <button type="button" class="btn btn-nimbus btn-sm" data-bs-toggle="modal" data-bs-target="#addUnitModal">
                        Créer une chambre
                    </button>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($units as $unit): ?>
                        <?php 
                        $hasTenant = isset($tenantsByUnit[$unit['id']]);
                        $tenant = $hasTenant ? $tenantsByUnit[$unit['id']] : null;
                        
                        // Déterminer la couleur du statut (F8)
                        $statusBadgeColor = 'bg-secondary'; // Libre = gris
                        $statusLabel = 'Libre';
                        
                        if ($unit['statut'] === 'occupee') {
                            // Vérifier si payé pour le mois en cours
                            $isPaid = isset($paymentsByUnit[$unit['id']]);
                            if ($isPaid) {
                                $statusBadgeColor = 'bg-success'; // Vert = payée
                                $statusLabel = 'Payé';
                            } else {
                                $jourActuel = (int)date('d');
                                if ($jourActuel > 5) {
                                    $statusBadgeColor = 'bg-danger'; // Rouge = retard
                                    $statusLabel = 'Retard';
                                } else {
                                    $statusBadgeColor = 'bg-warning text-dark'; // Orange = en attente
                                    $statusLabel = 'En attente';
                                }
                            }
                        } elseif ($unit['statut'] === 'transition') {
                            $statusBadgeColor = 'bg-info text-white';
                            $statusLabel = 'Transition';
                        }
                        ?>
                        <a href="unit-detail.php?id=<?php echo $unit['id']; ?>" class="card border-0 rounded-3 shadow-sm text-decoration-none text-dark transition-hover">
                            <div class="d-flex align-items-center p-3">
                                <div class="flex-grow-1 text-start">
                                    <div class="d-flex align-items-center gap-2">
                                        <h6 class="fw-bold mb-0"><?php echo htmlspecialchars($unit['code_unique']); ?></h6>
                                        <span class="badge <?php echo $statusBadgeColor; ?> small rounded-pill" style="font-size: 0.7rem;">
                                            <?php echo $statusLabel; ?>
                                        </span>
                                    </div>
                                    <span class="text-muted small d-block mt-1">
                                        Type : <?php echo htmlspecialchars($unit['type']); ?> • Réf : <?php echo number_format($unit['loyer_reference'], 0, ',', ' '); ?> F
                                    </span>
                                    <?php if ($hasTenant): ?>
                                        <span class="small text-success d-block fw-semibold mt-1">
                                            <i class="bi bi-person-fill me-1"></i><?php echo htmlspecialchars($tenant['prenom']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="small text-muted d-block mt-1">
                                            <i>Aucun locataire actif</i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted px-2">
                                    <i class="bi bi-chevron-right"></i>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </main>

        <!-- Modale de Création de Chambre (F7) -->
        <div class="modal fade" id="addUnitModal" tabindex="-1" aria-labelledby="addUnitModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered mx-3 max-width-480">
                <div class="modal-content rounded-4 border-0 shadow">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold" id="addUnitModalLabel">Créer une Chambre / Unité</h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="property-detail.php?id=<?php echo $propertyId; ?>" method="POST">
                        <div class="modal-body py-4">
                            <div class="mb-3">
                                <label for="type" class="form-label small fw-semibold text-secondary">Type d'unité *</label>
                                <select class="form-select rounded-3" id="type" name="type">
                                    <option value="Chambre">Chambre standard</option>
                                    <option value="Appartement">Appartement</option>
                                    <option value="Boutique">Boutique / Bureau</option>
                                    <option value="Studio">Studio</option>
                                </select>
                            </div>
                            
                            <div class="mb-2">
                                <label for="loyer_reference" class="form-label small fw-semibold text-secondary">Loyer de référence (mensuel) *</label>
                                <div class="input-group">
                                    <input type="number" class="form-control rounded-start-3" id="loyer_reference" name="loyer_reference" placeholder="Ex: 25000" required>
                                    <span class="input-group-text bg-light text-secondary rounded-end-3">FCFA</span>
                                </div>
                                <div class="form-text small" style="font-size: 0.72rem;">Le code de la chambre (LKG-[QUARTIER]-CH[N]) sera généré automatiquement.</div>
                            </div>
                        </div>
                        <div class="modal-footer border-0 pb-4">
                            <button type="button" class="btn btn-light rounded-3 px-3 w-40" data-bs-dismiss="modal">Fermer</button>
                            <button type="submit" name="add_unit" class="btn btn-nimbus px-4">Créer</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php require_once __DIR__ . '/../includes/nav.php'; ?>
    </div>
    <script>
        function shareGroupLink() {
            const link = "https://chat.whatsapp.com/invite/lokagest-exemple";
            if (navigator.share) {
                navigator.share({ title: 'Groupe WhatsApp Immeuble', text: 'Rejoignez le groupe WhatsApp LokaGest :', url: link }).catch(() => {});
            } else {
                navigator.clipboard.writeText(link);
                alert("Lien copié !");
            }
        }
    </script>
    <?php require_once __DIR__ . '/../includes/scripts.php'; ?>
</body>
</html>
