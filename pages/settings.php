<?php
/**
 * LokaGest - Configuration du Profil et Paramètres
 * 
 * Permet au propriétaire de renseigner son numéro de téléphone (obligatoire au 1er login),
 * d'activer/désactiver le Mode Gestionnaire,
 * et de suivre son plan d'abonnement (Free/Premium/Fondateur).
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/supabase.php';
// Si pas connecté en session, rediriger vers login
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('Location: ' . APP_URL . '/auth/login.php');
    exit;
}

$user = $_SESSION['user'];
$userId = $user['id'];

$errorMsg = null;
$successMsg = null;

// Vérifier si le propriétaire doit configurer obligatoirement son téléphone (F2)
$isSetupPhoneMode = isset($_GET['setup_phone']) && $_GET['setup_phone'] == 1;

// Traitement de la mise à jour
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isSetupPhoneMode) {
        $telephone = trim($_POST['telephone'] ?? '');
        $telephone = preg_replace('/[^0-9]/', '', $telephone);
        
        if (strlen($telephone) !== 8) {
            $errorMsg = "Veuillez entrer un numéro de téléphone béninois valide à 8 chiffres (sans indicatif).";
        } else {
            // Mettre à jour dans Supabase
            $updateRes = SupabaseClient::update('users', ['telephone' => $telephone], 'id=eq.' . $userId);
            
            if ($updateRes['status'] >= 200 && $updateRes['status'] < 300) {
                // Mettre à jour en session
                $_SESSION['user']['telephone'] = $telephone;
                
                header('Location: dashboard.php');
                exit;
            } else {
                $errorMsg = "Erreur lors de l'enregistrement du téléphone : " . ($updateRes['error'] ?? 'Inconnue');
            }
        }
    } else {
        // Mode modification classique
        $prenom = trim($_POST['prenom'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $telephone = preg_replace('/[^0-9]/', '', $telephone);
        
        // Mode gestionnaire (F34)
        $modeGestionnaire = isset($_POST['mode_gestionnaire']) ? 1 : 0;
        
        if (empty($prenom) || strlen($telephone) !== 8) {
            $errorMsg = "Veuillez remplir tous les champs obligatoires avec un numéro de téléphone valide.";
        } else {
            $updateData = [
                'prenom' => $prenom,
                'telephone' => $telephone
            ];
            
            $updateRes = SupabaseClient::update('users', $updateData, 'id=eq.' . $userId);
            
            if ($updateRes['status'] >= 200 && $updateRes['status'] < 300) {
                // Mettre à jour en session
                $_SESSION['user']['prenom'] = $prenom;
                $_SESSION['user']['telephone'] = $telephone;
                
                // Activer / désactiver le mode gestionnaire en session (F34)
                if ($modeGestionnaire === 1) {
                    $_SESSION['mode_gestionnaire'] = true;
                } else {
                    unset($_SESSION['mode_gestionnaire']);
                }

                $successMsg = "Paramètres mis à jour avec succès !";
                // Recharger les données locales
                $user = $_SESSION['user'];
            } else {
                $errorMsg = "Erreur lors de la mise à jour : " . ($updateRes['error'] ?? 'Inconnue');
            }
        }
    }
}

$pageTitle = 'Paramètres';
$activeNav = $isSetupPhoneMode ? '' : 'settings';
$basePath = '..';
require_once __DIR__ . '/../includes/head.php';
?>

    <div class="app-container <?php echo $isSetupPhoneMode ? '' : 'has-nav'; ?>">
        <header class="app-header">
            <div class="header-inner">
                <h1 class="header-title"><?php echo $isSetupPhoneMode ? 'Bienvenue !' : 'Paramètres'; ?></h1>
                <div class="header-sub"><?php echo $isSetupPhoneMode ? 'Dernière étape pour activer votre compte' : 'Profil & abonnement'; ?></div>
            </div>
        </header>

        <main class="app-main text-start">
            
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

            <?php if ($isSetupPhoneMode): ?>
                <!-- Mode configuration initiale de téléphone (F2) -->
                <div class="animate-fade-in">
                    <div class="empty-state py-4">
                        <div class="empty-icon"><i class="bi bi-phone-vibrate"></i></div>
                        <h5>Votre numéro MoMo</h5>
                        <p>MTN ou Moov Money — 8 chiffres sans indicatif.</p>
                    </div>
                    <form action="settings.php?setup_phone=1" method="POST" class="nimbus-card nimbus-card-body">
                        <div class="mb-4">
                            <label for="telephone" class="form-label small fw-semibold text-secondary">Numéro de téléphone *</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-secondary rounded-start-3">+229</span>
                                <input type="tel" class="form-control rounded-end-3 fw-bold fs-5 text-center" id="telephone" name="telephone" placeholder="97000000" pattern="[0-9]{8}" required autofocus>
                            </div>
                            <div class="form-text small" style="font-size: 0.75rem;">Saisir uniquement les 8 chiffres de votre numéro de mobile.</div>
                        </div>

                        <button type="submit" class="btn btn-nimbus w-100">
                            <i class="bi bi-shield-check me-2"></i>Valider mon inscription
                        </button>
                    </form>
                </div>

            <?php else: ?>
                <!-- Formulaire classique de paramètres -->
                <form action="settings.php" method="POST" class="animate-fade-in mb-4">
                    
                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Mon Profil</h6>

                    <div class="mb-3">
                        <label for="prenom" class="form-label small fw-semibold text-secondary">Prénom *</label>
                        <input type="text" class="form-control rounded-3" id="prenom" name="prenom" value="<?php echo htmlspecialchars($user['prenom']); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label small fw-semibold text-secondary">Adresse Email</label>
                        <input type="email" class="form-control rounded-3 bg-light text-muted" id="email" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                    </div>

                    <div class="mb-4">
                        <label for="telephone" class="form-label small fw-semibold text-secondary">Numéro de téléphone *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light text-secondary rounded-start-3">+229</span>
                            <input type="tel" class="form-control rounded-end-3" id="telephone" name="telephone" value="<?php echo htmlspecialchars($user['telephone']); ?>" pattern="[0-9]{8}" required>
                        </div>
                    </div>

                    <h6 class="fw-bold text-dark border-bottom pb-2 my-4">Sécurité & Rôles</h6>
                    <div class="card border-0 bg-light p-3 rounded-4 mb-4">
                        <div class="form-check form-switch d-flex justify-content-between align-items-center ps-0">
                            <div>
                                <label class="form-check-label fw-bold text-dark small" for="mode_gestionnaire">Mode Gestionnaire Limité (F34)</label>
                                <span class="d-block text-muted small" style="font-size: 0.72rem; max-width: 320px;">
                                    Permet de prêter l'application à un gardien ou intendant. Bloque l'accès au portefeuille et retraits d'argent.
                                </span>
                            </div>
                            <input class="form-check-input ms-0" type="checkbox" role="switch" id="mode_gestionnaire" name="mode_gestionnaire" value="1" <?php echo isset($_SESSION['mode_gestionnaire']) ? 'checked' : ''; ?>>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-nimbus w-100 mb-3">
                        <i class="bi bi-save2 me-2"></i>Sauvegarder les modifications
                    </button>
                </form>

                <!-- Section Abonnement / Statut Fondateur (F21) -->
                <h6 class="fw-bold text-dark border-bottom pb-2 my-4">Mon Abonnement</h6>
                <div class="card border-0 rounded-4 shadow-sm p-3 mb-4 bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small d-block">PLAN ACTUEL</span>
                            <b class="text-success text-uppercase"><?php echo htmlspecialchars($user['plan']); ?></b>
                            <?php if ($user['fondateur']): ?>
                                <span class="badge bg-warning text-dark fw-bold ms-1" style="font-size: 0.65rem;">⚡ FONDATEUR</span>
                            <?php endif; ?>
                        </div>
                        <span class="badge bg-success py-2 px-3 rounded-pill fw-semibold">
                            Actif
                        </span>
                    </div>

                    <?php if ($user['fondateur']): ?>
                        <div class="mt-3 pt-3 border-top border-secondary-subtle">
                            <span class="text-muted small d-block">AVANTAGE FONDATEUR</span>
                            <span class="small text-dark fw-bold">Commission 0% sur MTN MoMo et Moov Money Bénin à vie !</span>
                        </div>
                    <?php else: ?>
                        <div class="mt-3 pt-3 border-top border-secondary-subtle">
                            <span class="text-muted small d-block">EXPIRATION DE LA GRATUITÉ</span>
                            <span class="small text-dark fw-bold">Le <?php echo date('d/m/Y', strtotime($user['date_expiration_gratuite'])); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Bouton de Déconnexion (F4) -->
                <div class="my-4">
                    <a href="../auth/logout.php" class="btn btn-nimbus-outline w-100 d-block text-center text-decoration-none" style="color:var(--danger);border-color:var(--danger);">
                        <i class="bi bi-box-arrow-right me-2"></i>Déconnexion
                    </a>
                </div>
            <?php endif; ?>

        </main>

        <?php if (!$isSetupPhoneMode) require_once __DIR__ . '/../includes/nav.php'; ?>
    </div>
    <?php require_once __DIR__ . '/../includes/scripts.php'; ?>
</body>
</html>
