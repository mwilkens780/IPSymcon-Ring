<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api.php';

/**
 * Haelt den Login fuer ein Ring-Konto und stellt den Zugriff fuer beliebig
 * viele RingCamera-Instanzen bereit (gleiches Prinzip wie EcovacsAccount/
 * NestAccount: eine Sitzung, keine separate pro Kamera). Ring bietet keine
 * offizielle API -- Login/Token-Handling ist aus python-ring-doorbell
 * (aktiv gepflegte Home-Assistant-Integration) nach PHP portiert, siehe
 * auth.php/api.php fuer die verifizierten Endpunkte.
 */
class RingAccount extends IPSModule
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('email', '');
        $this->RegisterPropertyString('password', '');

        $this->RegisterAttributeString('oauth_store', '');
        $this->RegisterAttributeString('hardware_id', '');
        $this->RegisterAttributeBoolean('pending_2fa', false);
        $this->RegisterAttributeString('tsv_state', '');

        $this->SetVisualizationType(0);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if ($this->ReadAttributeString('hardware_id') === '') {
            $this->WriteAttributeString('hardware_id', $this->generateHardwareId());
        }

        $hasCreds = trim($this->ReadPropertyString('email')) !== '' && $this->ReadPropertyString('password') !== '';
        if (!$hasCreds) {
            $this->SetStatus(201);
            return;
        }

        if (!empty($this->readStore())) {
            $this->SetStatus(102);
        } else {
            $this->SetStatus(202);
        }
    }

    // ─── Configuration form ────────────────────────────────────────────────────

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $statusElement = null;
        if ($this->ReadAttributeBoolean('pending_2fa')) {
            $statusElement = [
                'type'    => 'Label',
                'caption' => $this->twoFaLabel($this->ReadAttributeString('tsv_state')),
            ];
        } elseif (!empty($this->readStore())) {
            $statusElement = ['type' => 'Label', 'caption' => 'Angemeldet.'];
        } elseif (trim($this->ReadPropertyString('email')) !== '') {
            $statusElement = ['type' => 'Label', 'caption' => "Nicht angemeldet.\nBitte 'Anmelden' klicken."];
        }
        if ($statusElement !== null) {
            array_splice($form['elements'], 0, 0, [$statusElement]);
        }

        return json_encode($form);
    }

    // ─── Public methods (config form buttons) ──────────────────────────────────

    public function StartLogin(): string
    {
        $email    = trim($this->ReadPropertyString('email'));
        $password = $this->ReadPropertyString('password');
        if ($email === '' || $password === '') {
            return 'Bitte zuerst E-Mail und Passwort eintragen.';
        }

        try {
            $result = RingApiAuth::login($email, $password, $this->hardwareId());
            return $this->handleLoginResult($result);
        } catch (\Exception $e) {
            $this->LogMessage('Ring Account StartLogin: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
            return 'Anmeldung fehlgeschlagen -- Details im IPS-Log.';
        }
    }

    public function SubmitOtp(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return 'Bitte den Bestätigungscode eingeben.';
        }

        $email    = trim($this->ReadPropertyString('email'));
        $password = $this->ReadPropertyString('password');

        try {
            $result = RingApiAuth::login($email, $password, $this->hardwareId(), $code);
            return $this->handleLoginResult($result);
        } catch (\Exception $e) {
            $this->LogMessage('Ring Account SubmitOtp: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
            return 'Code-Bestätigung fehlgeschlagen -- Details im IPS-Log.';
        }
    }

    public function ResetAuth(): void
    {
        $this->WriteAttributeString('oauth_store', '');
        $this->WriteAttributeBoolean('pending_2fa', false);
        $this->WriteAttributeString('tsv_state', '');
        $this->SetStatus(202);
        $this->LogMessage('Ring Account: Auth zurückgesetzt. Bitte erneut anmelden.', KL_MESSAGE);
    }

    /** Listet alle Kameras/Doorbells im Konto (Name + ID, fuer die device_id-Einstellung der RingCamera-Instanz). */
    public function ListDevices(): string
    {
        try {
            $devices = $this->createApi()->getVideoDevices();
        } catch (\Exception $e) {
            $this->LogMessage('Ring Account ListDevices: ' . $e->getMessage(), KL_ERROR);
            return 'Geräteabfrage fehlgeschlagen -- Details im IPS-Log.';
        }

        if (count($devices) === 0) {
            return 'Keine Kameras/Doorbells in diesem Konto gefunden.';
        }

        $lines = ['Gefundene Geräte (ID für die RingCamera-Instanz):'];
        foreach ($devices as $d) {
            $lines[] = '- ' . ($d['description'] ?? '?') . ' (ID: ' . ($d['id'] ?? '?') . ', ' . ($d['kind'] ?? '?') . ')';
        }
        return implode("\n", $lines);
    }

    // ─── Public methods (called by RingCamera instances) ───────────────────────

    /** @return string JSON: {battery, motionDetection} oder '' bei Fehler. */
    public function GetDeviceData(int $deviceId): string
    {
        try {
            $device = $this->createApi()->getDeviceById($deviceId);
        } catch (\Exception $e) {
            $this->LogMessage('Ring Account GetDeviceData: ' . $e->getMessage(), KL_ERROR);
            return '';
        }
        if ($device === null) {
            $this->LogMessage("Ring Account: Gerät $deviceId nicht im Konto gefunden.", KL_WARNING);
            return '';
        }

        $this->LogMessage('Ring Account DEBUG raw device: ' . json_encode($device), KL_MESSAGE);

        $battery = $device['battery_life'] ?? null;
        return json_encode([
            'battery'        => $battery !== null ? (int) $battery : null,
            'external'       => (bool) ($device['external_connection'] ?? false),
            'motionDetection' => (bool) ($device['settings']['motion_detection_enabled'] ?? false),
        ]);
    }

    public function SetMotionDetection(int $deviceId, bool $enabled): bool
    {
        try {
            $this->createApi()->setMotionDetection($deviceId, $enabled);
            return true;
        } catch (\Exception $e) {
            $this->LogMessage('Ring Account SetMotionDetection: ' . $e->getMessage(), KL_ERROR);
            return false;
        }
    }

    /** @return string Base64-kodiertes JPEG, oder '' wenn kein frisches Bild rechtzeitig ankam. */
    public function TriggerSnapshot(int $deviceId): string
    {
        try {
            $jpeg = $this->createApi()->fetchSnapshot($deviceId);
            return $jpeg !== null ? base64_encode($jpeg) : '';
        } catch (\Exception $e) {
            $this->LogMessage('Ring Account TriggerSnapshot: ' . $e->getMessage(), KL_ERROR);
            return '';
        }
    }

    // ─── Private helpers ────────────────────────────────────────────────────────

    private function handleLoginResult(array $result): string
    {
        // Ring's 2FA response has no documented/fixed shape (see auth.php
        // docblock) -- every non-'ok' outcome is logged AND appended to the
        // returned/echoed message so it's visible right in the popup. The
        // IPS message log floods with unrelated sensor telemetry (multiple
        // lines/second from OpenDTU etc.), so a rare login-attempt line is
        // effectively unfindable there in practice -- the popup is the only
        // reliably visible place for this.
        $suffix = '';
        if (isset($result['diagnostic'])) {
            $this->LogMessage('Ring Account Login-Antwort (' . $result['status'] . '): ' . $result['diagnostic'], KL_MESSAGE);
            $suffix = "\n\n[Diagnose] " . $result['diagnostic'];
        }

        switch ($result['status']) {
            case 'ok':
                $this->WriteAttributeString('oauth_store', json_encode($result['store']));
                $this->WriteAttributeBoolean('pending_2fa', false);
                $this->WriteAttributeString('tsv_state', '');
                $this->SetStatus(102);
                $this->ReloadForm();
                return 'Anmeldung erfolgreich.';
            case '2fa_required':
                $tsvState = $result['tsvState'] ?? '';
                $this->WriteAttributeBoolean('pending_2fa', true);
                $this->WriteAttributeString('tsv_state', $tsvState);
                $this->ReloadForm();
                return $this->twoFaLabel($tsvState) . $suffix;
            default:
                $this->WriteAttributeBoolean('pending_2fa', false);
                $this->WriteAttributeString('tsv_state', '');
                $this->SetStatus(202);
                $this->ReloadForm();
                return 'Anmeldung fehlgeschlagen -- E-Mail/Passwort prüfen.' . $suffix;
        }
    }

    /**
     * Ring meldet unterschiedliche 2FA-Methoden ueber 'tsv_state' -- 'totp'
     * bedeutet einen Code aus einer bereits fuer das Ring-Konto eingerichteten
     * Authenticator-App (Google Authenticator/Authy/...), dabei wird NICHTS
     * zugestellt (live bestaetigt 03.09.2026: erster Live-Test scheiterte,
     * weil die vorherige generische "per SMS/E-Mail/App gesendet"-Meldung
     * das faelschlich als "wird zugestellt" nahelegte). 'sms'/'email' sind
     * die einzigen Faelle, in denen Ring tatsaechlich etwas verschickt.
     */
    private function twoFaLabel(string $tsvState): string
    {
        switch ($tsvState) {
            case 'totp':
                return "Ring erwartet einen Code aus deiner Authenticator-App (Google Authenticator/Authy/...), die für dieses Ring-Konto eingerichtet ist -- es wird NICHTS zugestellt. Bitte den dort aktuell angezeigten 6-stelligen Code unten eingeben.";
            case 'sms':
                return "Ring hat einen Bestätigungscode per SMS gesendet.\nBitte unten eingeben und auf 'Code bestätigen' klicken.";
            case 'email':
                return "Ring hat einen Bestätigungscode per E-Mail gesendet.\nBitte unten eingeben und auf 'Code bestätigen' klicken.";
            default:
                return "Ring verlangt einen Bestätigungscode (Methode: " . ($tsvState !== '' ? $tsvState : 'unbekannt') . "). Bitte in der Ring-App/Authenticator-App nachsehen und unten eingeben.";
        }
    }

    private function createApi(): RingApiClient
    {
        $store = $this->readStore();
        if (empty($store)) {
            throw new \RuntimeException('Nicht angemeldet. Bitte in den Instanz-Einstellungen anmelden.');
        }
        $store = RingApiAuth::refreshIfNeeded($store, $this->hardwareId());
        $this->WriteAttributeString('oauth_store', json_encode($store));
        return new RingApiClient($store['access_token'], $this->hardwareId());
    }

    private function readStore(): array
    {
        $json = $this->ReadAttributeString('oauth_store');
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function hardwareId(): string
    {
        $id = $this->ReadAttributeString('hardware_id');
        if ($id === '') {
            $id = $this->generateHardwareId();
            $this->WriteAttributeString('hardware_id', $id);
        }
        return $id;
    }

    /**
     * Ring bindet den 2FA-Status an diese ID -- einmal generieren und
     * dauerhaft speichern, NIEMALS pro Login neu erzeugen (sonst fragt Ring
     * bei jeder Anmeldung erneut nach einem 2FA-Code).
     */
    private function generateHardwareId(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
