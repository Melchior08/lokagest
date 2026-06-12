<?php
/**
 * LokaGest - Client PHP Supabase
 * 
 * Wrapper cURL pour interagir avec les API REST et d'authentification de Supabase.
 */

require_once __DIR__ . '/../config/supabase.php';

class SupabaseClient {
    private static $url = SUPABASE_URL;
    private static $anonKey = SUPABASE_ANON_KEY;
    private static $serviceRoleKey = SUPABASE_SERVICE_ROLE_KEY;

    /**
     * Effectue une requête HTTP cURL vers l'API Supabase
     * 
     * @param string $method GET, POST, PATCH, PUT, DELETE
     * @param string $path Le chemin de l'API (ex: '/rest/v1/users' ou '/auth/v1/user')
     * @param mixed $body Le corps de la requête (sera encodé en JSON)
     * @param array $headers En-têtes HTTP personnalisés
     * @return array Réponse décodée sous forme de tableau associatif ['status' => int, 'data' => mixed, 'error' => string|null]
     */
    public static function request(string $method, string $path, $body = null, array $headers = []): array {
        $url = rtrim(self::$url, '/') . '/' . ltrim($path, '/');
        
        $ch = curl_init($url);
        
        // En-têtes de base requis par Supabase
        $defaultHeaders = [
            'apikey: ' . self::$anonKey,
            'Content-Type: application/json'
        ];

        // Si aucun en-tête d'autorisation n'est fourni, on utilise l'Anon Key par défaut
        $hasAuth = false;
        foreach ($headers as $hdr) {
            if (stripos($hdr, 'Authorization:') === 0) {
                $hasAuth = true;
                break;
            }
        }
        if (!$hasAuth) {
            $defaultHeaders[] = 'Authorization: Bearer ' . self::$anonKey;
        }

        $allHeaders = array_merge($defaultHeaders, $headers);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        // Désactiver la vérification SSL en local pour éviter les erreurs de certificat d'autorité de confiance
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if ($body !== null) {
            $jsonData = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        curl_close($ch);

        if ($response === false) {
            return [
                'status' => 500,
                'data' => null,
                'error' => 'Erreur de connexion cURL : ' . $curlError
            ];
        }

        $decodedData = json_decode($response, true);
        
        // Certaines requêtes de modification (comme POST ou PATCH) ne renvoient pas de corps si aucun Prefer n'est défini
        $errorMsg = null;
        if ($httpCode >= 400) {
            $errorMsg = isset($decodedData['message']) ? $decodedData['message'] : 'Erreur Supabase inconnue';
            if (isset($decodedData['error_description'])) {
                $errorMsg = $decodedData['error_description'];
            }
        }

        return [
            'status' => $httpCode,
            'data' => $decodedData,
            'error' => $errorMsg
        ];
    }

    /**
     * Valide un JWT Supabase et récupère les données utilisateur
     * 
     * @param string $accessToken Le jeton JWT de l'utilisateur
     * @return array|null Tableau contenant les données de l'utilisateur ou null en cas d'erreur
     */
    public static function getUser(string $accessToken): ?array {
        $headers = [
            'Authorization: Bearer ' . $accessToken
        ];
        
        $response = self::request('GET', '/auth/v1/user', null, $headers);
        
        if ($response['status'] === 200 && !empty($response['data'])) {
            return $response['data'];
        }
        
        return null;
    }

    /**
     * Recherche de données dans une table (SELECT)
     * 
     * @param string $table Nom de la table
     * @param string $select Colonnes à sélectionner (par défaut '*')
     * @param string $query Chaîne de requête additionnelle (ex: 'id=eq.12&limit=1')
     * @param string|null $userJwt Token de l'utilisateur pour appliquer les RLS (sélectionnable)
     * @return array
     */
    public static function select(string $table, string $select = '*', string $query = '', ?string $userJwt = null): array {
        $path = '/rest/v1/' . $table . '?select=' . urlencode($select);
        if (!empty($query)) {
            $path .= '&' . $query;
        }

        $headers = [];
        if ($userJwt) {
            $headers[] = 'Authorization: Bearer ' . $userJwt;
        } else {
            // Utiliser Service Role si pas de JWT utilisateur pour forcer la lecture backend sécurisée
            $headers[] = 'Authorization: Bearer ' . self::$serviceRoleKey;
        }

        return self::request('GET', $path, null, $headers);
    }

    /**
     * Insère des données dans une table (INSERT)
     * 
     * @param string $table Nom de la table
     * @param array $data Tableau associatif des données à insérer
     * @param string|null $userJwt Token utilisateur pour authentification RLS
     * @return array
     */
    public static function insert(string $table, array $data, ?string $userJwt = null): array {
        $path = '/rest/v1/' . $table;
        
        $headers = [
            'Prefer: return=representation' // Demande à Supabase de renvoyer l'objet inséré
        ];
        
        if ($userJwt) {
            $headers[] = 'Authorization: Bearer ' . $userJwt;
        } else {
            $headers[] = 'Authorization: Bearer ' . self::$serviceRoleKey;
        }

        return self::request('POST', $path, $data, $headers);
    }

    /**
     * Met à jour des données dans une table (UPDATE)
     * 
     * @param string $table Nom de la table
     * @param array $data Données à modifier
     * @param string $query Chaîne pour filtrer (ex: 'id=eq.12')
     * @param string|null $userJwt Token utilisateur pour authentification RLS
     * @return array
     */
    public static function update(string $table, array $data, string $query, ?string $userJwt = null): array {
        $path = '/rest/v1/' . $table . '?' . $query;
        
        $headers = [
            'Prefer: return=representation'
        ];
        
        if ($userJwt) {
            $headers[] = 'Authorization: Bearer ' . $userJwt;
        } else {
            $headers[] = 'Authorization: Bearer ' . self::$serviceRoleKey;
        }

        return self::request('PATCH', $path, $data, $headers);
    }

    /**
     * Supprime des données (DELETE)
     * 
     * @param string $table Nom de la table
     * @param string $query Chaîne de filtrage (ex: 'id=eq.12')
     * @param string|null $userJwt Token utilisateur
     * @return array
     */
    public static function delete(string $table, string $query, ?string $userJwt = null): array {
        $path = '/rest/v1/' . $table . '?' . $query;
        
        $headers = [
            'Prefer: return=representation'
        ];
        
        if ($userJwt) {
            $headers[] = 'Authorization: Bearer ' . $userJwt;
        } else {
            $headers[] = 'Authorization: Bearer ' . self::$serviceRoleKey;
        }

        return self::request('DELETE', $path, null, $headers);
    }
}
