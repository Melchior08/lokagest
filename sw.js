/**
 * LokaGest - Service Worker PWA
 * 
 * Assure le support hors ligne, la mise en cache des ressources statiques
 * et optimise la vitesse de chargement de l'application.
 */

const CACHE_NAME = 'lokagest-cache-v1';
const ASSETS_TO_CACHE = [
    '/LokaGest/auth/login.php',
    '/LokaGest/css/style.css',
    '/LokaGest/manifest.json',
    'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2'
];

// Phase d'installation : mise en cache des ressources critiques
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[Service Worker] Mise en cache des ressources statiques');
                // addAll échouera si l'une des ressources n'est pas accessible (ex: pas d'internet lors de l'install)
                // Donc on gère l'ajout des ressources de manière résiliente
                return Promise.allSettled(
                    ASSETS_TO_CACHE.map(url => {
                        return cache.add(url).catch(err => {
                            console.warn(`[Service Worker] Échec de la mise en cache de la ressource : ${url}`, err);
                        });
                    })
                );
            })
            .then(() => self.skipWaiting())
    );
});

// Activation : Nettoyage des anciens caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('[Service Worker] Suppression de l\'ancien cache', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// Interception des requêtes : Stratégie hybride
self.addEventListener('fetch', (event) => {
    const requestUrl = new URL(event.request.url);

    // Ne pas intercepter les requêtes vers l'API Supabase ou FedaPay
    if (requestUrl.hostname.includes('supabase.co') || requestUrl.hostname.includes('fedapay.com')) {
        return;
    }

    // Stratégie pour les assets statiques (CSS, JS, Fonts, Images) : Cache-First
    if (
        event.request.destination === 'style' ||
        event.request.destination === 'script' ||
        event.request.destination === 'image' ||
        event.request.destination === 'font' ||
        ASSETS_TO_CACHE.includes(requestUrl.pathname)
    ) {
        event.respondWith(
            caches.match(event.request).then((cachedResponse) => {
                if (cachedResponse) {
                    // Mettre à jour le cache en arrière-plan (Stale-While-Revalidate)
                    fetch(event.request).then((networkResponse) => {
                        if (networkResponse.status === 200) {
                            caches.open(CACHE_NAME).then((cache) => {
                                cache.put(event.request, networkResponse);
                            });
                        }
                    }).catch(() => {/* Ignore network errors */});
                    
                    return cachedResponse;
                }
                return fetch(event.request);
            })
        );
    } else {
        // Stratégie pour les pages PHP dynamiques : Network-First
        // On essaie d'abord d'obtenir la page la plus récente du réseau.
        // Si pas de réseau, on bascule sur la version en cache.
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    // Mettre en cache la copie fraîche si la réponse est valide
                    if (response.status === 200 && event.request.method === 'GET') {
                        const responseCopy = response.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(event.request, responseCopy);
                        });
                    }
                    return response;
                })
                .catch(() => {
                    // Si échec réseau (hors ligne), renvoyer la copie du cache
                    return caches.match(event.request).then((cachedResponse) => {
                        if (cachedResponse) {
                            return cachedResponse;
                        }
                        
                        // Si la ressource demandée n'est pas du tout dans le cache,
                        // on peut renvoyer un fallback (optionnel) ou échouer
                        if (event.request.headers.get('accept').includes('text/html')) {
                            // En cas de page HTML non trouvée en cache, on pourrait charger une page offline.html
                            return caches.match('/LokaGest/auth/login.php');
                        }
                    });
                })
        );
    }
});
