/**
 * LokaGest - Script d'Authentification et PWA Client
 * 
 * Gère l'enregistrement du Service Worker (sw.js) (F1) et intercepte l'événement
 * d'installation pour afficher la bannière "Ajouter à l'écran d'accueil".
 */

let deferredPrompt;

// 1. Enregistrement du Service Worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        const base = (window.LOKAGEST_BASE || '').replace(/\/$/, '') || '/LokaGest';
        navigator.serviceWorker.register(base + '/sw.js')
            .then((reg) => {
                console.log('[PWA] Service Worker enregistré avec succès', reg.scope);
            })
            .catch((err) => {
                console.warn('[PWA] Échec d\'enregistrement du Service Worker', err);
            });
    });
}

// 2. Bannière d'installation PWA (F1)
window.addEventListener('beforeinstallprompt', (e) => {
    // Empêcher l'affichage de la bannière automatique du navigateur
    e.preventDefault();
    deferredPrompt = e;
    
    // Créer et afficher notre bannière LokaGest personnalisée en bas de l'écran
    showInstallBanner();
});

function showInstallBanner() {
    const banner = document.createElement('div');
    banner.id = 'pwa-install-banner';
    banner.innerHTML = `
        <div class="card border-0 rounded-4 shadow p-3 m-3 text-white position-fixed bottom-0 start-50 translate-middle-x w-90 max-width-480 z-index-1000 animate-fade-in" style="width: 90%; background: linear-gradient(135deg, #121212, #FF6B2B);">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold mb-1">⚡ Installer LokaGest</h6>
                    <p class="small mb-0" style="font-size: 0.75rem;">Ajoutez LokaGest à votre écran d'accueil pour l'ouvrir plus vite et hors ligne.</p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-light text-success rounded-pill px-3 fw-bold" id="btn-pwa-install">Installer</button>
                    <button class="btn btn-xs text-white" onclick="document.getElementById('pwa-install-banner').remove()"><i class="bi bi-x-lg"></i></button>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(banner);
    
    document.getElementById('btn-pwa-install').addEventListener('click', async () => {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            console.log(`[PWA] Choix d'installation : ${outcome}`);
            deferredPrompt = null;
            banner.remove();
        }
    });
}
