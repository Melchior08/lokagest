# Déploiement LokaGest sur Render

## Étapes

### 1. Push sur GitHub
```bash
git init
git add .
git commit -m "Initial commit LokaGest"
git branch -M main
git remote add origin https://github.com/TON_USERNAME/lokagest.git
git push -u origin main
```

### 2. Créer le service sur Render
1. render.com → **New → Web Service**
2. Connecte ton repo GitHub
3. Paramètres :
   - **Environment** : PHP
   - **Build Command** : *(laisser vide)*
   - **Start Command** : `php -S 0.0.0.0:$PORT -t .`

### 3. Variables d'environnement à ajouter dans Render → Environment

| Variable | Valeur |
|---|---|
| `APP_URL` | `https://lokagest.onrender.com` |
| `APP_ENV` | `production` |
| `SUPABASE_URL` | ton URL Supabase |
| `SUPABASE_ANON_KEY` | ta clé anon |
| `SUPABASE_SERVICE_ROLE_KEY` | ta clé service role |
| `FEDAPAY_SECRET_KEY` | ta clé FedaPay |
| `FEDAPAY_ENV` | `sandbox` ou `live` |
| `ADMIN_PASSWORD` | ton mot de passe admin |
| `CALLMEBOT_API_KEY` | ta clé CallMeBot |
| `ULTRAMSG_INSTANCE_ID` | ton instance UltraMsg |
| `ULTRAMSG_TOKEN` | ton token UltraMsg |

### 4. Google OAuth — mettre à jour les URLs
Dans **Supabase → Authentication → URL Configuration** :
- Site URL : `https://lokagest.onrender.com`
- Redirect URLs : `https://lokagest.onrender.com/auth/callback.php`

Dans **Google Cloud Console → OAuth Client** → ajouter :
- `https://lokagest.onrender.com/auth/callback.php`

### 5. FedaPay Webhook
- URL webhook : `https://lokagest.onrender.com/api/webhook-fedapay.php`
