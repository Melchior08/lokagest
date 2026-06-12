/**
 * LokaGest - Initialisation Client Supabase JS
 * 
 * Permet d'accéder aux API Supabase côté navigateur (Auth, Storage).
 */

class LokaSupabase {
    constructor(url, anonKey) {
        this.url = url;
        this.anonKey = anonKey;
        this.client = null;
        
        if (window.supabase) {
            this.client = window.supabase.createClient(url, anonKey);
        } else {
            console.warn("La bibliothèque Supabase JS CDN n'est pas chargée.");
        }
    }

    getClient() {
        return this.client;
    }
}
