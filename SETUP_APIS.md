# LokaGest — Guide des API à configurer

Ce document explique **où trouver chaque clé**, **comment me la transmettre**, et **à quoi elle sert** dans le projet.

---

## Checklist rapide — ce dont j'ai besoin de toi

Copie-colle ce modèle rempli et envoie-le moi (sans publier sur GitHub) :

```
=== SUPABASE ===
URL : https://xxxx.supabase.co
Anon Key : eyJ...
Service Role Key : eyJ...

=== GOOGLE OAUTH (configuré dans Supabase, pas dans le code) ===
Client ID Google : xxxx.apps.googleusercontent.com
(Client Secret reste dans Supabase uniquement)

=== FEDAPAY ===
Clé secrète : sk_sandbox_... ou sk_live_...
Mode : sandbox / live

=== WHATSAPP — Green API (recommandé) ===
Instance ID : 7103xxxxxx
API Token : xxxxxxxx

=== WHATSAPP — UltraMsg (alternative) ===
Instance ID : instancexxxxx
Token : xxxxxxxx

=== WHATSAPP — CallMeBot (gratuit, secours) ===
Clé API : 12345678

=== ADMIN ===
Mot de passe admin personnalisé : ********
URL du site en production : https://ton-domaine.bj
```

---

## Semaine 1 — Supabase + Google Login

### Supabase (base de données + auth)
1. Va sur [supabase.com](https://supabase.com) → crée un projet **LokaGest**
2. **Project Settings → API**
3. Copie :
   - **Project URL** → `SUPABASE_URL`
   - **anon public** → `SUPABASE_ANON_KEY`
   - **service_role** → `SUPABASE_SERVICE_ROLE_KEY` ⚠️ secrète, jamais côté navigateur

4. **SQL Editor** → exécute le script `supabase_schema.sql` (tables users, properties, units, tenants, payments, wallets…)

### Google OAuth
1. [Google Cloud Console](https://console.cloud.google.com) → APIs & Services → Credentials
2. Crée **OAuth 2.0 Client ID** (type Web)
3. Redirect URI autorisé : celui affiché dans **Supabase → Authentication → Google**
4. Colle Client ID + Secret dans **Supabase → Authentication → Providers → Google**
5. Dans **Supabase → URL Configuration** :
   - Site URL : `http://localhost/LokaGest`
   - Redirect URLs : `http://localhost/LokaGest/auth/callback.php`

---

## Semaine 4 — FedaPay (MoMo MTN / Moov Bénin)

1. Compte sur [fedapay.com](https://www.fedapay.com)
2. **Paramètres → Clés API** → copie la clé `sk_sandbox_...` (tests) ou `sk_live_...` (prod)
3. **Webhook** : `https://TON-DOMAINE/api/webhook-fedapay.php`
4. Sans clé → mode **simulation USSD** (déjà intégré pour tester en local)

| Variable | Exemple |
|----------|---------|
| `FEDAPAY_SECRET_KEY` | `sk_sandbox_abc123` |
| `FEDAPAY_ENV` | `sandbox` |

---

## Semaine 7 — CallMeBot (WhatsApp automatique)

1. Ajoute **+34 644 66 20 89** sur WhatsApp
2. Envoie : `I allow callmebot to send me messages`
3. Le bot répond avec ta **clé API** (chiffres)
4. Dans LokaGest → **Paramètres → Clé API CallMeBot** (ou variable `CALLMEBOT_API_KEY`)

**Rappels auto** : planifie `php cron/reminders.php` chaque jour à 8h (Planificateur de tâches Windows).

---

## Semaine 7 — Twilio (SMS si WhatsApp échoue, optionnel)

1. [twilio.com](https://www.twilio.com) → compte essai
2. Console → **Account SID**, **Auth Token**, **Phone Number**

| Variable | |
|----------|--|
| `TWILIO_ACCOUNT_SID` | |
| `TWILIO_AUTH_TOKEN` | |
| `TWILIO_NUMBER` | `+1234567890` |

---

## Comment injecter les clés dans WAMP

**Option A — `.htaccess`** (à la racine LokaGest) :

```apache
SetEnv SUPABASE_URL "https://xxx.supabase.co"
SetEnv SUPABASE_ANON_KEY "eyJ..."
SetEnv SUPABASE_SERVICE_ROLE_KEY "eyJ..."
SetEnv FEDAPAY_SECRET_KEY "sk_sandbox_..."
SetEnv FEDAPAY_ENV "sandbox"
SetEnv GREEN_API_INSTANCE_ID "7103123456"
SetEnv GREEN_API_TOKEN "votre_token"
SetEnv ULTRAMSG_INSTANCE_ID "instance12345"
SetEnv ULTRAMSG_TOKEN "votre_token"
SetEnv CALLMEBOT_API_KEY "12345678"
SetEnv ADMIN_PASSWORD "TonMotDePasseFort"
```

**Option B — Interface LokaGest** : CallMeBot dans Paramètres (session).

**Option C — Me les envoyer** : je les configure dans `config/` ou `.htaccess` pour toi.

---

## État actuel du projet par semaine

| Semaine | Fonctionnalité | Statut |
|---------|----------------|--------|
| 1 | Login Google + Dashboard | ✅ Opérationnel |
| 2 | Propriétés + Chambres | ✅ Opérationnel |
| 3 | Locataire + QR + Bail PDF | ✅ Hub QR sur fiche chambre |
| 4 | Paiement locataire + FedaPay | ✅ Simulation + prêt FedaPay |
| 5 | Wallet + Retraits | ✅ Espèces créditent le wallet |
| 6 | Clôture bail + Historique | ✅ Opérationnel |
| 7 | Rappels auto + WhatsApp | ✅ Cron + bouton « Tout relancer » |
| 8 | Admin + Mise en ligne | ⚠️ Admin OK, tests auto à faire |

---

## Prochaines étapes ensemble

1. Tu m'envoies les clés (modèle ci-dessus)
2. On active FedaPay en sandbox et on teste un vrai paiement MoMo
3. On configure CallMeBot et on vérifie l'envoi WhatsApp
4. On déploie sur ton hébergeur (InfinityFree, Railway, VPS…)
