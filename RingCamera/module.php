<?php

declare(strict_types=1);

/**
 * Eine physische Ring-Kamera/Doorbell. Haelt keine eigene Sitzung -- liest
 * Status ueber die zentrale RingAccount-Instanz (gleiches Prinzip wie
 * EcovacsVacuum/NestProtect: Zustand von einer bereits authentifizierten
 * Quelle ableiten statt jede Instanz separat einzuloggen).
 *
 * Registriert bewusst dieselben Variablen-Idents wie das bestehende
 * Blink-Home-Device-Modul (thumbnail/battery/motion_detection/snapshot),
 * damit das Camera-Dashboard-Modul beide Hersteller ohne Sonderfaelle lesen
 * kann (siehe IPSymcon-CameraDashboard).
 */
class RingCamera extends IPSModule
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('account_instance', 0);
        $this->RegisterPropertyInteger('device_id', 0);
        $this->RegisterPropertyInteger('update_interval', 300);

        $this->RegisterVariableInteger('battery', $this->Translate('Batterie'), '~Battery.100', 1);
        $this->RegisterVariableBoolean('motion_detection', $this->Translate('Bewegungserkennung'), '~Switch', 2);
        $this->EnableAction('motion_detection');
        $this->RegisterVariableBoolean('snapshot', $this->Translate('Snapshot'), '', 3);
        $this->EnableAction('snapshot');

        $this->RegisterTimer('UpdateTimer', 0, 'RINC_Refresh($_IPS[\'TARGET\']);');
        // Keine eigene Kachel -- die Anzeige uebernimmt CameraDashboard.
        $this->SetVisualizationType(0);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->ensureThumbnailMedia();

        $accountId = $this->ReadPropertyInteger('account_instance');
        $deviceId  = $this->ReadPropertyInteger('device_id');
        if ($accountId <= 0 || $deviceId <= 0 || !@IPS_InstanceExists($accountId)) {
            $this->SetStatus(201);
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $interval = $this->ReadPropertyInteger('update_interval');
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);
        $this->SetStatus(102);
        $this->Refresh();
    }

    public function RequestAction($Ident, $Value): void
    {
        try {
            switch ($Ident) {
                case 'motion_detection':
                    $this->setMotionDetection((bool) $Value);
                    break;
                case 'snapshot':
                    $this->takeSnapshot();
                    break;
                default:
                    $this->LogMessage("RingCamera RequestAction: unknown ident {$Ident}", KL_WARNING);
            }
        } catch (\Throwable $e) {
            $this->LogMessage('RingCamera RequestAction ' . $Ident . ': ' . $e->getMessage(), KL_ERROR);
        }
    }

    /**
     * Fragt Batterie/Bewegungserkennung ab (billig, ein GET) und stoesst
     * danach ein frisches Standbild an (teurer -- bis zu ~7 HTTP-Aufrufe,
     * da Ring erst eine neue Aufnahme triggert und dann auf deren Timestamp
     * wartet). Ring dokumentiert kein festes Tageslimit wie BMW, aber ohne
     * bekannte Grenze ist ein zurückhaltendes Standard-Intervall (300s)
     * sinnvoller als raten.
     */
    public function Refresh(): void
    {
        try {
            $accountId = $this->ReadPropertyInteger('account_instance');
            $deviceId  = $this->ReadPropertyInteger('device_id');
            if ($accountId <= 0 || $deviceId <= 0 || !@IPS_InstanceExists($accountId)) {
                $this->SetStatus(201);
                return;
            }

            $raw = RIN_GetDeviceData($accountId, $deviceId);
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $this->LogMessage('RingCamera Refresh: Gerätedaten nicht lesbar (Account-Instanz-Log prüfen).', KL_ERROR);
                $this->SetStatus(200);
                return;
            }
            $this->applyBatteryState((bool) ($data['external'] ?? false), $data['battery']);
            $this->SetValue('motion_detection', (bool) $data['motionDetection']);

            $this->takeSnapshot();

            $this->SetStatus(102);
        } catch (\Throwable $e) {
            $this->LogMessage('RingCamera Refresh: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
    }

    /**
     * Ring meldet ueber `external_connection`, ob eine Stickup Cam dauerhaft
     * an eine Stromversorgung angeschlossen ist statt per Akku zu laufen --
     * in dem Fall gibt es keinen sinnvollen Ladezustand, die `battery`-
     * Variable wird entfernt. Das ist der einzige Signalweg: CameraDashboard
     * blendet die Batterie-Anzeige bereits aus, wenn die Variable fehlt
     * (identisches Verhalten wie bei Blink-Kameras ohne Akku-Reporting), und
     * der Profile/Battery Monitor im Alarm Dashboard scannt nach Variablen
     * mit dem `~Battery.100`-Profil -- ohne Variable also auch kein Fehlalarm
     * fuer eine Kamera, die den Akku gar nicht braucht.
     */
    private function applyBatteryState(bool $externallyPowered, ?int $batteryPercent): void
    {
        $batteryId = @IPS_GetObjectIDByIdent('battery', $this->InstanceID);

        if ($externallyPowered) {
            if ($batteryId !== false) {
                $this->UnregisterVariable('battery');
            }
            return;
        }

        $this->RegisterVariableInteger('battery', $this->Translate('Batterie'), '~Battery.100', 1);
        if ($batteryPercent !== null) {
            $this->SetValue('battery', $batteryPercent);
        }
    }

    private function setMotionDetection(bool $state): void
    {
        $accountId = $this->ReadPropertyInteger('account_instance');
        $deviceId  = $this->ReadPropertyInteger('device_id');
        if ($accountId <= 0 || $deviceId <= 0) {
            return;
        }
        if (RIN_SetMotionDetection($accountId, $deviceId, $state)) {
            $this->SetValue('motion_detection', $state);
        }
    }

    private function takeSnapshot(): void
    {
        $accountId = $this->ReadPropertyInteger('account_instance');
        $deviceId  = $this->ReadPropertyInteger('device_id');
        if ($accountId <= 0 || $deviceId <= 0) {
            return;
        }

        $base64 = RIN_TriggerSnapshot($accountId, $deviceId);
        if ($base64 === '') {
            $this->LogMessage('RingCamera: kein frisches Standbild erhalten (Ring hat rechtzeitig nicht geantwortet).', KL_WARNING);
            return;
        }

        $mediaId = $this->ensureThumbnailMedia();
        IPS_SetMediaContent($mediaId, $base64);
        IPS_SendMediaEvent($mediaId);
    }

    /** Legt das "thumbnail"-Media-Objekt einmalig an (Ident matcht CameraDashboard/BlinkHomeDevice) und liefert seine ID. */
    private function ensureThumbnailMedia(): int
    {
        $mediaId = @IPS_GetObjectIDByIdent('thumbnail', $this->InstanceID);
        if ($mediaId !== false && @IPS_MediaExists($mediaId)) {
            return $mediaId;
        }

        // Vollstaendiger Pfad, gleiches Muster wie Blink Home Device
        // (CreateMediaImage()) -- IPS_SetMediaFile erwartet dort einen
        // absoluten Pfad, kein media/-relativer.
        $file = IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR . $this->InstanceID . '_thumbnail.jpg';

        $mediaId = IPS_CreateMedia(MEDIATYPE_IMAGE);
        IPS_SetParent($mediaId, $this->InstanceID);
        IPS_SetIdent($mediaId, 'thumbnail');
        IPS_SetName($mediaId, $this->Translate('Standbild'));
        IPS_SetMediaCached($mediaId, true);
        IPS_SetMediaFile($mediaId, $file, false);
        return $mediaId;
    }
}
