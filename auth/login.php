<?php
/**
 * LokaGest - Page de Connexion (maquette exacte)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../lib/supabase.php';

if (isset($_SESSION['user']) && !empty($_SESSION['user']['telephone'])) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$pageTitle = 'Connexion';
$basePath = '..';
$bodyClass = '';
$extraHead = '<script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>';
require_once __DIR__ . '/../includes/head.php';
?>

<div class="auth-landing">
    <div class="logo-box">🏠</div>
    <h1 class="brand-title">LokaGest</h1>
    <p class="brand-tagline">Gestion locative simplifiée — Bénin</p>

    <div class="info-box">
        Gérez vos chambres, vos locataires et vos loyers depuis votre téléphone. Simple comme WhatsApp.
    </div>

    <div id="alert-container" class="alert d-none mb-3 text-start" role="alert"></div>

    <?php if (isset($_GET['logout']) && $_GET['logout'] == 1): ?>
        <div class="alert alert-success mb-3 text-start">Déconnexion réussie. À bientôt !</div>
    <?php endif; ?>

    <div class="auth-divider"></div>

    <button id="btn-google-login" class="btn btn-google w-100 d-flex align-items-center justify-content-center gap-2" type="button">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">
            <path fill="#EA4335" d="M12.24 10.285V14.4h6.887c-.648 2.41-2.519 4.114-5.136 4.114A5.5 5.5 0 0 1 8.5 13a5.5 5.5 0 0 1 5.491-5.5c1.492 0 2.784.577 3.756 1.51l3.014-3.013C18.847 4.21 16.59 3 13.991 3 8.473 3 4 7.473 4 12.991c0 5.518 4.473 9.991 9.991 9.991 6.136 0 10.285-4.313 10.285-10.47 0-.583-.057-1.127-.162-1.636H12.24z"/>
        </svg>
        Continuer avec Google
    </button>

    <button type="button" class="auth-email-link" data-bs-toggle="modal" data-bs-target="#emailAuthModal">
        Se connecter par email
    </button>

    <p class="text-muted small mt-5 mb-2">Installez l'app sur votre téléphone</p>
    <button type="button" id="btn-install-pwa" class="btn btn-nimbus btn-pwa w-100 d-none">
        ⬇ Ajouter à l'écran d'accueil
    </button>

    <p class="text-muted mt-4 mb-0" style="font-size:0.72rem;">lokagest.bj — Sécurisé HTTPS</p>
</div>

<!-- Modal connexion email -->
<div class="modal fade" id="emailAuthModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered mx-3">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-0">
                <h6 class="modal-title fw-bold">Connexion propriétaire</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-0">
                <form id="auth-form">
                    <input type="hidden" name="action" id="form-action" value="login">
                    <div class="d-flex gap-2 mb-3">
                        <button type="button" class="btn btn-sm btn-nimbus flex-fill" id="tab-login" data-action="login">Connexion</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="tab-signup" data-action="signup">Inscription</button>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control rounded-3" id="email" required placeholder="proprietaire@lokagest.bj">
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Mot de passe</label>
                        <input type="password" class="form-control rounded-3" id="password" required>
                    </div>
                    <button type="submit" id="btn-submit" class="btn btn-nimbus w-100">Se connecter</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const SUPABASE_URL = "<?php echo SUPABASE_URL; ?>";
    const SUPABASE_ANON_KEY = "<?php echo SUPABASE_ANON_KEY; ?>";
    const REDIRECT_URI = "<?php echo AUTH_REDIRECT_URI; ?>";
    const supabaseClient = supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);

    const form = document.getElementById('auth-form');
    const actionInput = document.getElementById('form-action');
    const submitBtn = document.getElementById('btn-submit');
    const alertContainer = document.getElementById('alert-container');
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    const tabLogin = document.getElementById('tab-login');
    const tabSignup = document.getElementById('tab-signup');

    let deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        document.getElementById('btn-install-pwa')?.classList.remove('d-none');
    });
    document.getElementById('btn-install-pwa')?.addEventListener('click', async () => {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        await deferredPrompt.userChoice;
        deferredPrompt = null;
    });

    function setMode(mode) {
        actionInput.value = mode;
        const isLogin = mode === 'login';
        tabLogin.className = isLogin ? 'btn btn-sm btn-nimbus flex-fill' : 'btn btn-sm btn-outline-secondary flex-fill';
        tabSignup.className = !isLogin ? 'btn btn-sm btn-nimbus flex-fill' : 'btn btn-sm btn-outline-secondary flex-fill';
        submitBtn.textContent = isLogin ? 'Se connecter' : 'Créer mon compte';
        hideAlert();
    }
    tabLogin.addEventListener('click', () => setMode('login'));
    tabSignup.addEventListener('click', () => setMode('signup'));

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        hideAlert();
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        const action = actionInput.value;
        try {
            let result;
            if (action === 'login') {
                result = await supabaseClient.auth.signInWithPassword({ email: emailInput.value, password: passwordInput.value });
            } else {
                result = await supabaseClient.auth.signUp({
                    email: emailInput.value, password: passwordInput.value,
                    options: { emailRedirectTo: REDIRECT_URI }
                });
            }
            if (result.error) throw result.error;
            if (action === 'signup' && result.data.user && !result.data.session) {
                showAlert('success', "Compte créé ! Vérifiez votre e-mail.");
                submitBtn.disabled = false;
                submitBtn.textContent = 'Créer mon compte';
                return;
            }
            if (result.data.session) await handlePHPLogin(result.data.session);
        } catch (error) {
            showAlert('danger', error.message || 'Erreur');
            submitBtn.disabled = false;
            submitBtn.textContent = action === 'login' ? 'Se connecter' : 'Créer mon compte';
        }
    });

    document.getElementById('btn-google-login').addEventListener('click', async () => {
        hideAlert();
        try {
            const { error } = await supabaseClient.auth.signInWithOAuth({
                provider: 'google',
                options: { redirectTo: REDIRECT_URI }
            });
            if (error) throw error;
        } catch (error) {
            showAlert('danger', 'Échec Google : ' + error.message);
        }
    });

    async function handlePHPLogin(session) {
        const response = await fetch('callback.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                access_token: session.access_token,
                refresh_token: session.refresh_token
            })
        });
        const result = JSON.parse(await response.text());
        if (result.success) window.location.href = result.redirect;
        else throw new Error(result.error || 'Erreur session');
    }

    function showAlert(type, message) {
        alertContainer.className = `alert alert-${type} mb-3 text-start`;
        alertContainer.textContent = message;
        alertContainer.classList.remove('d-none');
    }
    function hideAlert() { alertContainer.classList.add('d-none'); }

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('<?php echo $basePath; ?>/sw.js').catch(() => {});
    }
</script>
</body>
</html>
