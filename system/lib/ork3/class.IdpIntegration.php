<?php

/**
 * Server-to-server IDP integration (JSON: IdpIntegration/*).
 *
 * Flow A v2: ORK mails claim codes after synchronously validating send nonces with the IDP.
 */
class IdpIntegration extends Ork3
{
    private const PURPOSE_CLAIM_ORK = 'claim_ork';
    private const TTL_SECONDS = 600;
    private const MAX_SENDS_PER_MAILBOX_HOUR = 5;
    private const MAX_SENDS_GLOBAL_HOUR = 500;
    private const MAX_CODE_ATTEMPTS = 8;
    private const CLAIM_CODE_LENGTH = 16;
    private const CLAIM_CODE_PATTERN = '/^[A-Z0-9]{16}$/';

    private yapo $mundane;
    private yapo $idpAuth;
    private yapo $challenge;

    public function __construct()
    {
        parent::__construct();
        $this->mundane = new yapo($this->db, DB_PREFIX . 'mundane');
        $this->idpAuth = new yapo($this->db, DB_PREFIX . 'idp_auth');
        $this->challenge = new yapo($this->db, DB_PREFIX . 'idp_mailbox_challenge');
    }

    /**
     * @param array{SendNonce?: string} $request
     */
    public function BeginClaimOrkMail($request): array
    {
        $sendNonce = trim((string) ($request['SendNonce'] ?? ''));
        if ($sendNonce === '') {
            return NoAuthorization();
        }

        $claims = $this->validateSendNonceWithIdp($sendNonce);
        if ($claims === null) {
            $this->log->Write('IdpIntegration BeginClaimOrkMail rejected', 0, LOG_EDIT, [
                'reason' => 'idp_nonce_rejected',
            ]);

            return NoAuthorization();
        }

        $mundaneId = (int) ($claims['mundane_id'] ?? 0);
        $idpUserId = trim((string) ($claims['idp_user_id'] ?? ''));
        if ($mundaneId <= 0 || $idpUserId === '') {
            return NoAuthorization();
        }

        return $this->mailClaimCodeForMundane($mundaneId, $idpUserId);
    }

    /**
     * @param array{IdpUserId?: string, MundaneId?: int|string, Code?: string} $request
     */
    public function ValidateClaimOrkCode($request): array
    {
        $idpUserId = trim((string) ($request['IdpUserId'] ?? ''));
        $mundaneId = (int) ($request['MundaneId'] ?? 0);
        $code = trim((string) ($request['Code'] ?? ''));
        $code = strtoupper($code);
        if ($idpUserId === '' || $mundaneId <= 0 || !preg_match(self::CLAIM_CODE_PATTERN, $code)) {
            return NoAuthorization();
        }

        if (!$this->loadOpenChallenge($idpUserId, $mundaneId)) {
            return NoAuthorization();
        }

        if ((int) $this->challenge->attempts >= self::MAX_CODE_ATTEMPTS) {
            return NoAuthorization();
        }

        $expires = strtotime((string) $this->challenge->expires_at);
        if ($expires === false || $expires < time()) {
            return NoAuthorization();
        }

        if (!password_verify($code, (string) $this->challenge->code_hash)) {
            $this->challenge->attempts = (int) $this->challenge->attempts + 1;
            $this->challenge->save();

            return NoAuthorization();
        }

        $this->mundane->clear();
        $this->mundane->mundane_id = $mundaneId;
        if (!$this->mundane->find()) {
            return NoAuthorization();
        }

        $email = trim((string) $this->mundane->email);
        $now = date('Y-m-d H:i:s');
        $this->challenge->consumed_at = $now;
        $this->challenge->save();

        $this->reassignIdpAuthAfterClaim($mundaneId, $idpUserId);

        $result = Success();
        $result['Email'] = $email;
        $result['MundaneId'] = $mundaneId;

        $this->log->Write('IdpIntegration ValidateClaimOrkCode success', 0, LOG_EDIT, [
            'mundane_id' => $mundaneId,
            'idp_user_id' => $idpUserId,
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function validateSendNonceWithIdp(string $sendNonce): ?array
    {
        if (!defined('IDP_API_URL') || !defined('IDP_CLIENT_ID') || !defined('IDP_CLIENT_SECRET')) {
            return null;
        }

        $url = rtrim((string) IDP_API_URL, '/') . '/resources/ork/validate-send-nonce';
        $clientId = (string) IDP_CLIENT_ID;
        $clientSecret = (string) IDP_CLIENT_SECRET;
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $payload = json_encode(['send_nonce' => $sendNonce]);
        if ($payload === false) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_USERPWD, $clientId . ':' . $clientSecret);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $status !== 200) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    private function mailClaimCodeForMundane(int $mundaneId, string $idpUserId): array
    {
        $this->mundane->clear();
        $this->mundane->mundane_id = $mundaneId;
        if (!$this->mundane->find()) {
            $this->log->Write('IdpIntegration BeginClaimOrkMail skipped', 0, LOG_EDIT, [
                'reason' => 'no_mundane',
                'mundane_id' => $mundaneId,
            ]);

            return Success();
        }

        $email = strtolower(trim((string) $this->mundane->email));
        if ($email === '' || !str_contains($email, '@')
            || (int) $this->mundane->suspended !== 0
            || (int) $this->mundane->penalty_box !== 0) {
            $this->log->Write('IdpIntegration BeginClaimOrkMail skipped', 0, LOG_EDIT, [
                'reason' => 'no_mailbox',
                'mundane_id' => $mundaneId,
                'sent_to_hash' => $email === '' ? null : hash('sha256', $email),
            ]);

            return Success();
        }

        $sentToHash = hash('sha256', $email);
        if ($this->isThrottled($sentToHash)) {
            $this->log->Write('IdpIntegration BeginClaimOrkMail throttled', 0, LOG_EDIT, [
                'mundane_id' => $mundaneId,
                'sent_to_hash' => $sentToHash,
                'idp_user_id' => $idpUserId,
            ]);

            return Success();
        }

        $challengeId = $this->newUuid();
        $code = $this->generateClaimCode();
        $now = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + self::TTL_SECONDS);
        $this->challenge->clear();
        $this->challenge->id = $challengeId;
        $this->challenge->purpose = self::PURPOSE_CLAIM_ORK;
        $this->challenge->idp_user_id = $idpUserId;
        $this->challenge->mundane_id = $mundaneId;
        $this->challenge->code_hash = password_hash($code, PASSWORD_DEFAULT);
        $this->challenge->sent_to_hash = $sentToHash;
        $this->challenge->send_count = 1;
        $this->challenge->attempts = 0;
        $this->challenge->expires_at = $expires;
        $this->challenge->consumed_at = null;
        $this->challenge->created_at = $now;
        $this->challenge->save();

        $this->sendClaimMail($email, $code);
        $logDetail = [
            'mundane_id' => $mundaneId,
            'challenge_id' => $challengeId,
            'sent_to_hash' => $sentToHash,
            'idp_user_id' => $idpUserId,
        ];
        if ((getenv('ENVIRONMENT') ?: '') === 'DEV') {
            $logDetail['dev_claim_code'] = $code;
        }
        $this->log->Write('IdpIntegration BeginClaimOrkMail mailed', 0, LOG_EDIT, $logDetail);

        return Success();
    }

    private function loadOpenChallenge(string $idpUserId, int $mundaneId): bool
    {
        $prefix = DB_PREFIX;
        $purpose = self::PURPOSE_CLAIM_ORK;
        $idpEsc = addslashes($idpUserId);
        $row = $this->db->query(
            "SELECT id FROM {$prefix}idp_mailbox_challenge"
            . " WHERE purpose = '{$purpose}' AND idp_user_id = '{$idpEsc}' AND mundane_id = {$mundaneId}"
            . " AND consumed_at IS NULL ORDER BY created_at DESC LIMIT 1"
        );
        if (!$row->next()) {
            return false;
        }

        $this->challenge->clear();
        $this->challenge->id = (string) $row->id;
        if (!$this->challenge->find()) {
            return false;
        }

        return true;
    }

    /**
     * Flow A possession claim: bind this mundane to the IDP user who proved mailbox access.
     * Clears stored OAuth tokens so the claimer establishes a fresh IDP session on next ORK login.
     */
    private function reassignIdpAuthAfterClaim(int $mundaneId, string $idpUserId): void
    {
        $now = date('Y-m-d H:i:s');
        $this->idpAuth->clear();
        $this->idpAuth->mundane_id = $mundaneId;
        $hadRow = $this->idpAuth->find();
        $previousIdpUserId = $hadRow ? trim((string) $this->idpAuth->idp_user_id) : '';

        $this->idpAuth->mundane_id = $mundaneId;
        $this->idpAuth->idp_user_id = $idpUserId;
        $this->idpAuth->access_token = '';
        $this->idpAuth->refresh_token = '';
        $this->idpAuth->expires_at = null;
        if ($hadRow) {
            $this->idpAuth->updated_at = $now;
        } else {
            $this->idpAuth->created_at = $now;
        }
        $this->idpAuth->save();

        if ($previousIdpUserId !== '' && $previousIdpUserId !== $idpUserId) {
            $this->log->Write('IdpIntegration claim reassigned idp_auth', 0, LOG_EDIT, [
                'mundane_id' => $mundaneId,
                'from_idp_user_id' => $previousIdpUserId,
                'to_idp_user_id' => $idpUserId,
            ]);
        }
    }

    private function isThrottled(string $sentToHash): bool
    {
        $since = date('Y-m-d H:i:s', time() - 3600);
        $prefix = DB_PREFIX;
        $purpose = self::PURPOSE_CLAIM_ORK;
        $row = $this->db->query(
            "SELECT COUNT(*) AS c FROM {$prefix}idp_mailbox_challenge"
            . " WHERE purpose = '{$purpose}' AND created_at >= '{$since}'"
        );
        $row->next();
        if ((int) ($row->c ?? 0) >= self::MAX_SENDS_GLOBAL_HOUR) {
            return true;
        }

        $row = $this->db->query(
            "SELECT COUNT(*) AS c FROM {$prefix}idp_mailbox_challenge"
            . " WHERE purpose = '{$purpose}' AND sent_to_hash = '{$sentToHash}' AND created_at >= '{$since}'"
        );
        $row->next();

        return (int) ($row->c ?? 0) >= self::MAX_SENDS_PER_MAILBOX_HOUR;
    }

    private function sendClaimMail(string $email, string $code): void
    {
        $profileUrl = defined('IDP_BASE_URL')
            ? rtrim((string) IDP_BASE_URL, '/') . '/resources/profile'
            : 'https://idp.amtgard.com/resources/profile';

        $from = trim((string) AMAZON_SES_FROM_EMAIL);
        if ($from === '') {
            $from = 'ork3@amtgard.com';
        }

        $host = trim((string) AMAZON_SES_HOST);
        $username = trim((string) AMAZON_SES_USERNAME);
        if ($host === '' || $username === '' || trim((string) AMAZON_SES_PASSWORD) === '') {
            error_log('IdpIntegration sendClaimMail: SES not configured (host/username/password required)');

            return;
        }

        error_log(json_encode([
            'event' => 'IdpIntegration sendClaimMail start',
            'ses_host' => $host,
            'ses_port' => AMAZON_SES_PORT,
            'from' => $from,
            'to_hash' => hash('sha256', strtolower($email)),
        ], JSON_UNESCAPED_SLASHES));

        $m = new Mail('smtp', $host, AMAZON_SES_USERNAME, AMAZON_SES_PASSWORD, AMAZON_SES_PORT);
        $m->setTo($email);
        $m->setFrom($from);
        $m->setSender($from);
        $m->setSubject('Your Amtgard verification code');
        $m->setText(
            "Your Amtgard verification code is {$code}. It expires in 10 minutes. Enter it in all caps.\n\n"
            . "Open your Amtgard Sign-In profile and enter the code to link your ORK player record:\n"
            . "{$profileUrl}\n"
        );

        ob_start();
        $m->send();
        $smtpDiagnostics = trim(strip_tags((string) ob_get_clean()));

        if ($smtpDiagnostics !== '') {
            error_log('IdpIntegration sendClaimMail: SMTP failure: ' . preg_replace('/\s+/', ' ', $smtpDiagnostics));

            return;
        }

        error_log('IdpIntegration sendClaimMail: SMTP completed OK');
        if ((getenv('ENVIRONMENT') ?: '') === 'DEV') {
            error_log('IdpIntegration sendClaimMail: DEV claim code for mailbox hash '
                . hash('sha256', strtolower($email)) . ' is ' . $code);
        }
    }

    private function generateClaimCode(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < self::CLAIM_CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
