<?php
/**
 * LokaGest - Configuration Supabase
 * 
 * Fichier contenant les clés et URL de connexion à l'API Supabase.
 * Il est recommandé de surcharger ces valeurs via des variables d'environnement
 * en production ou d'utiliser un fichier .env (via $_ENV ou getenv).
 */

// Supabase URL (ex: https://xxxxxxxxxxxxxx.supabase.co)
define('SUPABASE_URL', getenv('SUPABASE_URL') ?: 'https://cbixvproixoprojbnthq.supabase.co');

// Supabase Anon Key (Clé publique pour l'accès client)
define('SUPABASE_ANON_KEY', getenv('SUPABASE_ANON_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImNiaXh2cHJvaXhvcHJvamJudGhxIiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODExODI0NjMsImV4cCI6MjA5Njc1ODQ2M30.MbcECizaAyixAhdwl2aQgVbCQTY6Ebrs_aOET5_0Lso');

// Supabase Service Role Key (À utiliser avec précaution, uniquement côté serveur sécurisé)
define('SUPABASE_SERVICE_ROLE_KEY', getenv('SUPABASE_SERVICE_ROLE_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImNiaXh2cHJvaXhvcHJvamJudGhxIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc4MTE4MjQ2MywiZXhwIjoyMDk2NzU4NDYzfQ.orJVfwKrAibIqZ9vSX4VwUCd623E4b4mXxC9faHu0O4');
