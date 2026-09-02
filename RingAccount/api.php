<?php

declare(strict_types=1);

/**
 * Authenticated calls against Ring's undocumented cloud API, endpoints and
 * request/response shapes verified against python-ring-doorbell (ring.py/
 * doorbot.py/const.py, Stand 02.09.2026) since Ring has no official API.
 */
class RingApiClient
{
    private const API_BASE = 'https://api.ring.com';

    private string $accessToken;
    private string $hardwareId;

    public function __construct(string $accessToken, string $hardwareId)
    {
        $this->accessToken = $accessToken;
        $this->hardwareId  = $hardwareId;
    }

    /** Raw device list: ['doorbots'=>[...], 'authorized_doorbots'=>[...], 'stickup_cams'=>[...], 'chimes'=>[...]]. */
    public function getDevices(): array
    {
        [$status, $body] = $this->request('GET', '/clients_api/ring_devices');
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data)) {
            throw new \RuntimeException("HTTP $status beim Abrufen der Geräteliste: " . substr($body, 0, 300));
        }
        return $data;
    }

    /** Video-fähige Geräte (Doorbells + Stickup Cams), zusammengeführt aus den drei Kategorien. */
    public function getVideoDevices(): array
    {
        $devices = $this->getDevices();
        return array_merge(
            $devices['doorbots'] ?? [],
            $devices['authorized_doorbots'] ?? [],
            $devices['stickup_cams'] ?? []
        );
    }

    /** Findet ein einzelnes Gerät per ID aus der Geräteliste (liefert battery_life/settings/... mit). */
    public function getDeviceById(int $deviceId): ?array
    {
        foreach ($this->getVideoDevices() as $device) {
            if ((int) ($device['id'] ?? 0) === $deviceId) {
                return $device;
            }
        }
        return null;
    }

    public function setMotionDetection(int $deviceId, bool $enabled): void
    {
        $path = '/devices/v1/devices/' . $deviceId . '/settings';
        [$status, $body] = $this->request('PATCH', $path, [
            'motion_settings' => ['motion_detection_enabled' => $enabled],
        ]);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("HTTP $status beim Setzen der Bewegungserkennung: " . substr($body, 0, 300));
        }
    }

    /**
     * Triggert eine frische Aufnahme und laedt sie herunter. Ring beantwortet
     * den Trigger sofort, das Bild selbst braucht laut Referenzimplementierung
     * ein bis wenige Sekunden -- wird per Timestamp-Vergleich abgewartet statt
     * blind zu pollen (identischer Ablauf wie async_get_snapshot()).
     *
     * @return string|null Raw JPEG bytes, or null if no fresh snapshot arrived in time.
     */
    public function fetchSnapshot(int $deviceId, int $retries = 5, int $delaySeconds = 1): ?string
    {
        $timestampPath = '/clients_api/snapshots/timestamps';
        $payload       = ['doorbot_ids' => [$deviceId]];

        $this->request('POST', $timestampPath, $payload);
        $requestedAt = time();

        for ($i = 0; $i < $retries; $i++) {
            sleep($delaySeconds);
            [$status, $body] = $this->request('POST', $timestampPath, $payload);
            $data = json_decode($body, true);
            $ts   = (int) (($data['timestamps'][0]['timestamp'] ?? 0) / 1000);
            if ($status === 200 && $ts > $requestedAt) {
                [$imgStatus, $imgBody] = $this->request('GET', '/clients_api/snapshots/image/' . $deviceId, null, false);
                return $imgStatus === 200 ? $imgBody : null;
            }
        }
        return null;
    }

    /**
     * @param mixed $jsonBody Request-Body, wenn gesetzt -- als JSON kodiert ausser $isJsonBody ist false.
     * @param bool $isJsonBody Bei false wird $jsonBody unveraendert (roh) gesendet statt es zu json_encode()n.
     * @return array{0:int,1:string} [HTTP-Status, Antwortkoerper]
     */
    private function request(string $method, string $path, $jsonBody = null, bool $isJsonBody = true): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'User-Agent: IPSymcon-Ring/1.0',
            'hardware_id: ' . $this->hardwareId,
        ];

        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => self::API_BASE . $path,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 20,
        ];
        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = $isJsonBody ? json_encode($jsonBody) : $jsonBody;
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $body   = curl_exec($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new \RuntimeException("cURL-Fehler bei Ring-API-Aufruf $method $path: $error");
        }

        return [$status, (string) $body];
    }
}
