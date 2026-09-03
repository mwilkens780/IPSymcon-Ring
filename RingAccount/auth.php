<?php

declare(strict_types=1);

/**
 * Ring bietet keine offizielle API. Diese Klasse portiert den OAuth2-
 * "password"-Grant-Flow (inkl. 2FA-Handshake) aus der aktiv gepflegten
 * Referenzimplementierung python-ring-doorbell (auth.py/const.py) nach PHP.
 *
 * Ablauf, verifiziert gegen python-ring-doorbell (Stand 02.09.2026):
 *  1. POST an OAUTH_ENDPOINT mit grant_type=password, HTTP-Basic
 *     (client_id, leeres Passwort). Enthaelt die Antwort ein access_token,
 *     war das Login ohne 2FA erfolgreich.
 *  2. Enthaelt die Antwort weder access_token noch einen OAuth-error, hat
 *     Ring stattdessen eine 2FA-Challenge zurueckgegeben (kein fester
 *     Fehlercode -- das erkennt auch oauthlib nur am Fehlen des Tokens).
 *     Der zweite Versuch schickt zusaetzlich die Header 2fa-support:true
 *     und 2fa-code:<code>.
 *  3. Enthaelt die Antwort ein 'error'-Feld, waren die Zugangsdaten falsch.
 */
class RingApiAuth
{
    private const OAUTH_ENDPOINT = 'https://oauth.ring.com/oauth/token';
    private const CLIENT_ID      = 'ring_official_android';
    private const USER_AGENT     = 'IPSymcon-Ring/1.0';

    /**
     * @return array{status:string,store?:array,httpStatus?:int,diagnostic?:string}
     *   'diagnostic' is a short, secret-free preview of Ring's raw response --
     *   populated on every non-'ok' outcome so a failed login is debuggable
     *   from the IPS log alone instead of guessing blind (Ring's 2FA
     *   response has no documented shape, see class docblock).
     */
    public static function login(string $email, string $password, string $hardwareId, ?string $otpCode = null): array
    {
        $headers = [
            'User-Agent: ' . self::USER_AGENT,
            'hardware_id: ' . $hardwareId,
        ];
        if ($otpCode !== null && $otpCode !== '') {
            $headers[] = '2fa-support: true';
            $headers[] = '2fa-code: ' . $otpCode;
        }

        [$httpStatus, $result, $rawBody] = self::httpPost(self::OAUTH_ENDPOINT, [
            'grant_type' => 'password',
            'username'   => $email,
            'password'   => $password,
            'client_id'  => self::CLIENT_ID,
            'scope'      => 'client',
        ], $headers, true);

        if (!empty($result['access_token'])) {
            return ['status' => 'ok', 'store' => self::buildStore($result)];
        }

        $diagnostic = 'HTTP ' . $httpStatus . ': ' . substr($rawBody, 0, 300);
        if (isset($result['error'])) {
            return ['status' => 'invalid_credentials', 'httpStatus' => $httpStatus, 'diagnostic' => $diagnostic];
        }
        // Weder Token noch Fehler -- Ring erwartet den 2FA-Code (siehe Docblock).
        return ['status' => '2fa_required', 'httpStatus' => $httpStatus, 'diagnostic' => $diagnostic];
    }

    /** Refreshes an existing token store. Returns the store unchanged when still valid. */
    public static function refreshIfNeeded(array $store, string $hardwareId): array
    {
        if (!empty($store['expires_at']) && (int) $store['expires_at'] > time() + 60) {
            return $store;
        }
        if (empty($store['refresh_token'])) {
            throw new \RuntimeException('Kein Refresh-Token – erneute Anmeldung erforderlich.');
        }

        [$httpStatus, $result, $rawBody] = self::httpPost(self::OAUTH_ENDPOINT, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $store['refresh_token'],
            'client_id'     => self::CLIENT_ID,
        ], [
            'User-Agent: ' . self::USER_AGENT,
            'hardware_id: ' . $hardwareId,
        ], true);

        if (empty($result['access_token'])) {
            throw new \RuntimeException("Token-Refresh fehlgeschlagen (HTTP $httpStatus): " . substr($rawBody, 0, 300));
        }
        return self::buildStore($result);
    }

    private static function buildStore(array $response): array
    {
        return [
            'access_token'  => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? '',
            'expires_at'    => time() + (int) ($response['expires_in'] ?? 3600),
        ];
    }

    /** @return array{0:int,1:array<string,mixed>,2:string} [HTTP-Status, JSON-decoded body (leeres Array bei ungueltigem JSON), Rohkoerper] */
    private static function httpPost(string $url, array $formFields, array $headers, bool $basicAuthClientId): array
    {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($formFields),
            CURLOPT_HTTPHEADER     => array_merge($headers, ['Content-Type: application/x-www-form-urlencoded']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 15,
        ];
        if ($basicAuthClientId) {
            $opts[CURLOPT_USERPWD] = self::CLIENT_ID . ':';
        }
        curl_setopt_array($ch, $opts);
        $body   = curl_exec($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new \RuntimeException("cURL-Fehler bei Ring-Login: $error");
        }

        $bodyStr = (string) $body;
        $data    = json_decode($bodyStr, true);
        return [$status, is_array($data) ? $data : [], $bodyStr];
    }
}
