# Guide de Configuration des API - LokaGest Bénin

Ce fichier contient les instructions étape par étape pour configurer les services et clés API nécessaires au bon fonctionnement de **LokaGest** (Supabase, FedaPay, CallMeBot WhatsApp, Twilio SMS).

---

## 1. BASE DE DONNÉES & AUTHENTIFICATION (Supabase)

### Étape A : Création du projet
1. Créez un compte gratuit sur [Supabase](https://supabase.com/).
2. Créez un nouveau projet nommé `LokaGest`.
3. Une fois le projet prêt, allez dans **Project Settings** > **API** pour récupérer vos clés :
   - **Project URL** (ex: `https://xxxxxxxxxxxxxx.supabase.co`)
   - **Anon Public Key** (Clé publique pour le client)
   - **Service Role Key** (Clé secrète d'administration backend)

### Étape B : Initialisation des tables (SQL)
1. Dans votre console Supabase, ouvrez l'onglet **SQL Editor**.
2. Cliquez sur **New Query**.
3. Copiez-collez l'intégralité du contenu du fichier d'initialisation fourni à :
   `C:\Users\MELCHIOR\.gemini\antigravity\brain\5c0c71ae-3e5c-4678-85e1-b92e5051fd2c\scratch\supabase_schema.sql` (ou à la racine de votre projet si déplacé).
4. Cliquez sur **Run** pour exécuter le script. Toutes les tables, index et structures de sécurité RLS seront créés en moins de 3 secondes.

### Étape C : Configuration Google OAuth
1. Sur Supabase, allez dans **Authentication** > **Providers** > **Google**.
2. Activez Google Auth.
3. Configurez l'application Google OAuth dans votre console Google Cloud et récupérez le **Client ID** et le **Client Secret**.
4. Renseignez ces identifiants dans la page de configuration de Supabase.
5. Copiez l'**Redirect URI** fourni par Supabase et ajoutez-le aux redirect URIs autorisés de votre application Google Console.
6. Dans Supabase Auth, définissez la redirection vers votre page de callback : `http://localhost/LokaGest/auth/callback.php` (ou votre domaine en production).

---

## 2. PAIEMENTS MOBILE MONEY (FedaPay Bénin)

LokaGest intègre l'API REST de FedaPay pour encaisser les loyers par MTN Mobile Money et Moov Money Bénin.

1. Créez un compte sur [FedaPay](https://www.fedapay.com/).
2. Dans votre tableau de bord FedaPay, activez le mode **Sandbox** (pour les tests) ou **Live** (production).
3. Allez dans **Paramètres** > **Clés API** et récupérez :
   - La **Clé secrète** (commence par `sk_sandbox_` ou `sk_live_`).
4. **Webhook :** Configurez un webhook dans FedaPay pointant vers `https://votre-domaine.bj/api/webhook-fedapay.php` (ou utilisez la simulation intégrée en local si la clé secrète est laissée vide).

---

## 3. NOTIFICATIONS WHATSAPP (CallMeBot API)

CallMeBot permet d'envoyer gratuitement des messages automatiques et des liens PDF par WhatsApp.

1. Pour obtenir votre clé d'API CallMeBot gratuite, ajoutez le numéro CallMeBot à vos contacts WhatsApp et envoyez-lui un message :
   - Envoyez le texte `I allow callmebot to send me messages` au numéro : **+34 644 66 20 89** (ou suivez les instructions sur [callmebot.com](https://www.callmebot.com/)).
2. Le bot vous répondra avec votre **API Key** personnelle.
3. Configurez cette clé API dans le profil de chaque propriétaire (dans `pages/settings.php`) ou comme variable système générale.

---

## 4. SMS DE SECOURS (Twilio Fallback)

Si WhatsApp échoue ou n'est pas disponible, LokaGest bascule sur un SMS standard.

1. Créez un compte d'essai sur [Twilio](https://www.twilio.com/).
2. Récupérez vos identifiants dans la console :
   - **Account SID**
   - **Auth Token**
   - **Twilio Phone Number** (Numéro expéditeur)
3. Renseignez-les dans vos variables d'environnement.

---

## 5. RENSEIGNER LES CLÉS DANS L'APPLICATION

Vous pouvez configurer ces clés de deux façons :

### Option A : Par variables d'environnement (Recommandé)
Configurez-les dans votre serveur web (WampServer `httpd.conf` ou fichier `.htaccess` ou via l'interface Railway/InfinityFree) :

```apache
SetEnv SUPABASE_URL "https://cbixvproixoprojbnthq.supabase.co"
SetEnv SUPABASE_ANON_KEY "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImNiaXh2cHJvaXhvcHJvamJudGhxIiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODExODI0NjMsImV4cCI6MjA5Njc1ODQ2M30.MbcECizaAyixAhdwl2aQgVbCQTY6Ebrs_aOET5_0Lso"
SetEnv SUPABASE_SERVICE_ROLE_KEY "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImNiaXh2cHJvaXhvcHJvamJudGhxIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc4MTE4MjQ2MywiZXhwIjoyMDk2NzU4NDYzfQ.orJVfwKrAibIqZ9vSX4VwUCd623E4b4mXxC9faHu0O4"
SetEnv FEDAPAY_SECRET_KEY "sk_sandbox_votre_cle"
SetEnv FEDAPAY_ENV "sandbox"
SetEnv CALLMEBOT_API_KEY "votre-cle-whatsapp"
SetEnv TWILIO_ACCOUNT_SID "votre-sid-twilio"
SetEnv TWILIO_AUTH_TOKEN "votre-token-twilio"
SetEnv TWILIO_NUMBER "+1234567890"
```

### Option B : Modification directe des fichiers de configuration
Si vous ne pouvez pas utiliser de variables d'environnement, modifiez directement le fichier de configuration à [config/supabase.php](file:///c:/wamp64/www/LokaGest/config/supabase.php) :

```php
define('SUPABASE_URL', 'https://votre-projet.supabase.co');
define('SUPABASE_ANON_KEY', 'votre-cle-anon-publique');
define('SUPABASE_SERVICE_ROLE_KEY', 'votre-cle-service-role-secrete');
```
