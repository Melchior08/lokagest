<?php
/**
 * LokaGest - Callback Authentification (Supabase Auth)
 * 
 * Reçoit le token d'authentification Supabase côté client, le valide côté PHP
 * auprès de l'API Supabase, inscrit ou met à jour l'utilisateur en BDD,
 * gère le statut fondateur et démarre la session PHP sécurisée.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/supabase.php';

// Si c'est une requête POST asynchrone du pont JavaScript Supabase
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Récupérer les données JSON envoyées par le client
    $input = json_decode(file_get_contents('php://input'), true);
    $accessToken = $input['access_token'] ?? null;
    $refreshToken = $input['refresh_token'] ?? null;
    
    if (!$accessToken) {
        echo json_encode(['success' => false, 'error' => 'Aucun jeton fourni']);
        exit;
    }
    
    // 1. Valider le token auprès de Supabase
    $supabaseUser = SupabaseClient::getUser($accessToken);
    
    if (!$supabaseUser) {
        echo json_encode(['success' => false, 'error' => 'Jeton d\'accès invalide ou expiré']);
        exit;
    }
    
    $authId = $supabaseUser['id']; // UUID Supabase Auth (c'est un vrai UUID !)
    $email = $supabaseUser['email'];
    
    // Extraction du prénom depuis les métadonnées Google
    $meta = $supabaseUser['user_metadata'] ?? [];
    $prenom = $meta['given_name'] ?? $meta['full_name'] ?? explode('@', $email)[0];
    
    // 2. Vérifier si l'utilisateur existe déjà dans notre table custom "users"
    $selectUser = SupabaseClient::select('users', '*', 'auth_id=eq.' . $authId);
    
    $userRecord = null;
    $isNewUser = false;
    
    if ($selectUser['status'] === 200 && !empty($selectUser['data'])) {
        $userRecord = $selectUser['data'][0];
    } else {
        $isNewUser = true;
    }
    
    if ($isNewUser) {
        // 3. Programme Fondateur (F2 : si dans les 50 premiers inscrits)
        $countResponse = SupabaseClient::select('users', 'id');
        $currentCount = 0;
        if ($countResponse['status'] === 200 && is_array($countResponse['data'])) {
            $currentCount = count($countResponse['data']);
        }
        
        $isFondateur = ($currentCount < 50);
        $dateExpiration = date('Y-m-d H:i:s', strtotime('+30 days'));
        
        // Données du nouvel utilisateur
        $newUserData = [
            'auth_id' => $authId,
            'prenom' => $prenom,
            'email' => $email,
            'telephone' => '', // Sera demandé lors de la première connexion
            'plan' => $isFondateur ? 'premium' : 'free',
            'fondateur' => $isFondateur,
            'date_expiration_gratuite' => $isFondateur ? null : $dateExpiration,
            'statut' => 'actif',
            'date_inscription' => date('Y-m-d H:i:s'),
            'derniere_activite' => date('Y-m-d H:i:s')
        ];
        
        // Insérer le nouvel utilisateur
        $insertResponse = SupabaseClient::insert('users', $newUserData);
        
        if ($insertResponse['status'] >= 200 && $insertResponse['status'] < 300) {
            $userRecord = $insertResponse['data'][0] ?? $newUserData;
            
            // Créer le portefeuille (wallet)
            $walletData = [
                'user_id' => $userRecord['id'] ?? null,
                'solde' => 0,
                'total_entre' => 0,
                'total_sorti' => 0
            ];
            
            if (!isset($userRecord['id'])) {
                $refetch = SupabaseClient::select('users', 'id', 'auth_id=eq.' . $authId);
                if ($refetch['status'] === 200 && !empty($refetch['data'])) {
                    $userRecord['id'] = $refetch['data'][0]['id'];
                    $walletData['user_id'] = $userRecord['id'];
                }
            }
            
            if ($walletData['user_id']) {
                SupabaseClient::insert('wallets', $walletData);
            }
            
            if ($isFondateur) {
                $_SESSION['welcome_fondateur'] = true;
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Erreur lors de la création de l\'utilisateur : ' . ($insertResponse['error'] ?? 'Inconnue')]);
            exit;
        }
    } else {
        // Mettre à jour la date d'activité
        SupabaseClient::update(
            'users', 
            ['derniere_activite' => date('Y-m-d H:i:s')], 
            'id=eq.' . $userRecord['id']
        );
        $userRecord['derniere_activite'] = date('Y-m-d H:i:s');
    }
    
    // 4. Initialiser la session PHP
    $_SESSION['user'] = $userRecord;
    $_SESSION['supabase_access_token'] = $accessToken;
    $_SESSION['supabase_refresh_token'] = $refreshToken;
    
    // Forcer l'écriture immédiate de la session sur le disque pour éviter toute perte lors de la redirection rapide
    session_write_close();
    
    // Déterminer la redirection
    $redirectTo = APP_URL . '/pages/dashboard.php';
    if (empty($userRecord['telephone'])) {
        $redirectTo = APP_URL . '/pages/settings.php?setup_phone=1';
    } elseif (isset($_SESSION['redirect_after_login'])) {
        $redirectTo = $_SESSION['redirect_after_login'];
        unset($_SESSION['redirect_after_login']);
    }
    
    echo json_encode([
        'success' => true, 
        'redirect' => $redirectTo
    ]);
    exit;
}

// Requête GET : retour OAuth Google ou confirmation d'email Supabase (tokens dans l'URL)
require_once __DIR__ . '/../config/supabase.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion en cours - LokaGest</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
</head>
<body>
    <div class="loading-screen">
        <div class="spinner-nimbus"></div>
        <p class="mb-0 fw-semibold" id="status-msg">Finalisation de votre connexion...</p>
        <p class="text-danger small mt-2 d-none" id="error-msg" style="color:#FF6B2B!important;max-width:280px;text-align:center;"></p>
    </div>
    <script>
        const SUPABASE_URL = "<?php echo SUPABASE_URL; ?>";
        const SUPABASE_ANON_KEY = "<?php echo SUPABASE_ANON_KEY; ?>";
        const supabaseClient = supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);

        async function finalizeLogin(session) {
            const response = await fetch('callback.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    access_token: session.access_token,
                    refresh_token: session.refresh_token
                })
            });

            const text = await response.text();
            let result;
            try {
                result = JSON.parse(text);
            } catch (e) {
                throw new Error("Réponse serveur invalide : " + text);
            }

            if (result.success) {
                window.location.href = result.redirect;
            } else {
                throw new Error(result.error || "Erreur de création de session.");
            }
        }

        async function handleAuthCallback() {
            const statusEl = document.getElementById('status-msg');
            const errorEl = document.getElementById('error-msg');

            try {
                const urlParams = new URLSearchParams(window.location.search);
                const code = urlParams.get('code');
                const hashParams = new URLSearchParams(window.location.hash.substring(1));
                const accessToken = hashParams.get('access_token');
                const refreshToken = hashParams.get('refresh_token');

                let session = null;

                if (code) {
                    const { data, error } = await supabaseClient.auth.exchangeCodeForSession(code);
                    if (error) throw error;
                    session = data.session;
                } else if (accessToken) {
                    session = { access_token: accessToken, refresh_token: refreshToken };
                } else {
                    const { data, error } = await supabaseClient.auth.getSession();
                    if (error) throw error;
                    session = data.session;
                }

                if (!session) {
                    window.location.href = 'login.php';
                    return;
                }

                await finalizeLogin(session);
            } catch (err) {
                console.error(err);
                statusEl.classList.add('d-none');
                errorEl.classList.remove('d-none');
                errorEl.textContent = err.message || "Impossible de finaliser la connexion.";
                setTimeout(() => { window.location.href = 'login.php'; }, 4000);
            }
        }

        handleAuthCallback();
    </script>
</body>
</html>
<?php
exit;
