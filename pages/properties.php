<?php
/**
 * LokaGest - Gestion des Propriétés
 * 
 * Permet de lister toutes les propriétés d'un propriétaire et d'en ajouter
 * de nouvelles (avec téléversement de photo de couverture et stockage local/distant).
 */

require_once __DIR__ . '/../lib/auth_guard.php';
require_once __DIR__ . '/../lib/supabase.php';

$user = $_SESSION['user'];
$userId = $user['id'];

$errorMsg = null;
$successMsg = null;

// Mode ajout de propriété
$isAddMode = isset($_GET['action']) && $_GET['action'] === 'add';

// Traitement du formulaire d'ajout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAddMode) {
    $nom = trim($_POST['nom'] ?? '');
    $adresse = trim($_POST['adresse'] ?? '');
    $quartier = trim($_POST['quartier'] ?? '');
    $ville = trim($_POST['ville'] ?? '');
    
    if (empty($nom) || empty($adresse) || empty($quartier) || empty($ville)) {
        $errorMsg = "Veuillez remplir tous les champs obligatoires.";
    } else {
        $photoUrl = null;
        
        // Gérer le téléversement d'image
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['photo']['tmp_name'];
            $fileName = $_FILES['photo']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($fileExtension, $allowedExtensions)) {
                // Créer le dossier uploads local s'il n'existe pas
                $uploadDestDir = __DIR__ . '/../uploads/properties/';
                if (!is_dir($uploadDestDir)) {
                    mkdir($uploadDestDir, 0755, true);
                }
                
                $newFileName = uniqid('prop_', true) . '.' . $fileExtension;
                $destPath = $uploadDestDir . $newFileName;
                
                if (move_uploaded_file($fileTmpPath, $destPath)) {
                    // Chemin d'accès relatif pour l'URL de base
                    $photoUrl = APP_URL . '/uploads/properties/' . $newFileName;
                }
            } else {
                $errorMsg = "Format d'image non autorisé (formats acceptés : JPG, PNG, WEBP).";
            }
        }
        
        // Si aucune image ou échec, on peut utiliser un placeholder de secours premium (Unsplash)
        if (!$photoUrl) {
            $photoUrl = 'https://images.unsplash.com/photo-1564013799919-ab600027ffc6?auto=format&fit=crop&w=400&q=80';
        }
        
        if (!$errorMsg) {
            // Insérer la propriété dans Supabase
            $newProperty = [
                'user_id' => $userId,
                'nom' => $nom,
                'adresse' => $adresse,
                'quartier' => $quartier,
                'ville' => $ville,
                'photo_url' => $photoUrl,
                'date_creation' => date('Y-m-d H:i:s')
            ];
            
            $insertResponse = SupabaseClient::insert('properties', $newProperty);
            
            if ($insertResponse['status'] >= 200 && $insertResponse['status'] < 300) {
                header('Location: properties.php?success=1');
                exit;
            } else {
                $errorMsg = "Erreur lors de l'enregistrement en base de données : " . ($insertResponse['error'] ?? 'Inconnue');
            }
        }
    }
}

// Récupération des propriétés pour affichage
$properties = [];
$response = SupabaseClient::select('properties', '*', 'user_id=eq.' . $userId . '&order=date_creation.desc');
if ($response['status'] === 200 && is_array($response['data'])) {
    $properties = $response['data'];
}

if (isset($_GET['success']) && $_GET['success'] == 1) {
    $successMsg = "Propriété ajoutée avec succès !";
}

$pageTitle = $isAddMode ? 'Ajouter une propriété' : 'Mes propriétés';
$activeNav = 'properties';
$basePath = '..';
require_once __DIR__ . '/../includes/head.php';
?>

    <div class="app-container has-nav">
        <header class="app-header">
            <div class="header-inner d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <?php if ($isAddMode): ?>
                        <a href="properties.php" class="header-back"><i class="bi bi-arrow-left"></i></a>
                    <?php endif; ?>
                    <div>
                        <h1 class="header-title mb-0"><?php echo $isAddMode ? 'Nouvelle propriété' : 'Mes Propriétés'; ?></h1>
                        <?php if (!$isAddMode): ?><div class="header-sub"><?php echo count($properties); ?> bien<?php echo count($properties) > 1 ? 's' : ''; ?> enregistré<?php echo count($properties) > 1 ? 's' : ''; ?></div><?php endif; ?>
                    </div>
                </div>
                <?php if (!$isAddMode): ?>
                    <a href="?action=add" class="btn-header-action">+ Ajouter</a>
                <?php endif; ?>
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

            <?php if ($isAddMode): ?>
                <!-- Formulaire d'ajout -->
                <form action="properties.php?action=add" method="POST" enctype="multipart/form-data" class="animate-fade-in">
                    <div class="mb-3">
                        <label for="nom" class="form-label small fw-semibold text-secondary">Nom de la propriété *</label>
                        <input type="text" class="form-control rounded-3" id="nom" name="nom" placeholder="Ex: Résidence Horizon" required>
                    </div>

                    <div class="mb-3">
                        <label for="adresse" class="form-label small fw-semibold text-secondary">Adresse précise *</label>
                        <input type="text" class="form-control rounded-3" id="adresse" name="adresse" placeholder="Ex: Rue 412, face pharmacie" required>
                    </div>

                    <div class="row mb-3">
                        <div class="col-6">
                            <label for="quartier" class="form-label small fw-semibold text-secondary">Quartier *</label>
                            <input type="text" class="form-control rounded-3" id="quartier" name="quartier" placeholder="Ex: Fidjrossè" required>
                        </div>
                        <div class="col-6">
                            <label for="ville" class="form-label small fw-semibold text-secondary">Ville *</label>
                            <input type="text" class="form-control rounded-3" id="ville" name="ville" placeholder="Ex: Cotonou" required>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="photo" class="form-label small fw-semibold text-secondary">Photo de couverture</label>
                        <input class="form-control rounded-3" type="file" id="photo" name="photo" accept="image/*">
                        <div class="form-text small" style="font-size: 0.75rem;">Formats acceptés : JPG, PNG, WEBP. Max 2 Mo.</div>
                    </div>

                    <button type="submit" class="btn btn-nimbus w-100 mb-3">
                        <i class="bi bi-check-circle me-2"></i>Enregistrer la Propriété
                    </button>
                    <a href="properties.php" class="btn btn-outline-secondary w-100 py-3 rounded-3">
                        Annuler
                    </a>
                </form>

            <?php else: ?>
                <!-- Liste des propriétés -->
                <?php if (empty($properties)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="bi bi-building"></i></div>
                        <h5>Aucune propriété</h5>
                        <p>Commencez par ajouter votre premier bien immobilier.</p>
                        <a href="?action=add" class="btn btn-nimbus">Ajouter ma propriété</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($properties as $prop):
                        $cntRes = SupabaseClient::select('units', 'id', 'property_id=eq.' . $prop['id']);
                        $chCount = ($cntRes['status'] === 200 && is_array($cntRes['data'])) ? count($cntRes['data']) : 0;
                    ?>
                        <div class="property-card">
                            <div class="prop-image">
                                <img src="<?php echo htmlspecialchars($prop['photo_url']); ?>" alt="">
                                <div class="prop-overlay">
                                    <h3 class="prop-name"><?php echo htmlspecialchars($prop['nom']); ?></h3>
                                    <span class="prop-location"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($prop['quartier'] . ', ' . $prop['ville']); ?></span>
                                </div>
                            </div>
                            <div class="prop-body">
                                <span class="badge-nimbus">🏠 <?php echo $chCount; ?> chambre<?php echo $chCount > 1 ? 's' : ''; ?></span>
                                <a href="property-detail.php?id=<?php echo $prop['id']; ?>" class="btn btn-nimbus btn-sm">Gérer <i class="bi bi-arrow-right"></i></a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>

        </main>

        <?php require_once __DIR__ . '/../includes/nav.php'; ?>
    </div>
    <?php require_once __DIR__ . '/../includes/scripts.php'; ?>
</body>
</html>
