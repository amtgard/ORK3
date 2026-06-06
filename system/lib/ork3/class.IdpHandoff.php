<?php

/**
 * ORK→IDP handoff JWT signer.
 *
 * Lives in its own class (rather than on Authorization) because the project's
 * pre-commit hook never lets class.Authorization.php into a commit.
 *
 * Auto-discovered by startup.php's scandir loop over system/lib/ork3/ and
 * accessible at Ork3::$Lib->idphandoff.
 */
class IdpHandoff extends Ork3
{
	private static function b64url($data) {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	/**
	 * Mint a short-lived HS256 JWT for the IDP /auth/connect handoff.
	 * Claims: iss=ork, aud=idp, sub=mundane_id (string-coerced), email,
	 * iat, exp (+900s), jti (uuidv4). The IDP's OrkLinkTokenService verifies
	 * with the same shared secret and records the jti in link_token_jti to
	 * prevent replay.
	 */
	/**
	 * Resolve the HS256 shared secret used for both link-token (ORK->IDP) and
	 * completion-token (IDP->ORK) verification. Prefers the new canonical
	 * IDP_ORK_SHARED_SECRET; falls back to the legacy IDP_LINK_TOKEN_SECRET
	 * during deploy transition. Returns '' when neither is set.
	 */
	private static function sharedSecret() {
		if (defined('IDP_ORK_SHARED_SECRET') && strlen(IDP_ORK_SHARED_SECRET) >= 32) {
			return IDP_ORK_SHARED_SECRET;
		}
		if (defined('IDP_LINK_TOKEN_SECRET') && strlen(IDP_LINK_TOKEN_SECRET) >= 32) {
			return IDP_LINK_TOKEN_SECRET;
		}
		return '';
	}

	public function mintLinkToken($mundaneId, $email)
	{
		$secret = self::sharedSecret();
		if (strlen($secret) < 32) {
			throw new Exception('IDP/ORK shared secret unset or too short (set IDP_ORK_SHARED_SECRET)');
		}
		$header   = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
		$bytes    = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		$hex      = bin2hex($bytes);
		$uuid     = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
		$now      = time();
		$payload  = self::b64url(json_encode([
			'iss'   => 'ork',
			'aud'   => 'idp',
			'sub'   => (string)(int)$mundaneId,
			'email' => (string)$email,
			'iat'   => $now,
			'exp'   => $now + 900,
			'jti'   => $uuid,
		]));
		$signing = $header . '.' . $payload;
		$sig     = self::b64url(hash_hmac('sha256', $signing, $secret, true));
		return $signing . '.' . $sig;
	}

	private static function b64url_decode($data) {
		$pad = strlen($data) % 4;
		if ($pad) {
			$data .= str_repeat('=', 4 - $pad);
		}
		return base64_decode(strtr($data, '-_', '+/'), true);
	}

	/**
	 * Verify a completion JWT issued by the IDP after a successful /auth/connect
	 * round trip. Claims expected: iss=idp, aud=ork, sub=idp_user_id (string),
	 * mundane_id (int), iat, exp.
	 *
	 * @return array{idp_user_id: string, mundane_id: int}|null
	 */
	public function verifyCompletionToken($jwt)
	{
		$secret = self::sharedSecret();
		if (strlen($secret) < 32 || !is_string($jwt) || substr_count($jwt, '.') !== 2) {
			return null;
		}
		[$h, $p, $s] = explode('.', $jwt);
		$expected = self::b64url(hash_hmac('sha256', "$h.$p", $secret, true));
		if (!hash_equals($expected, $s)) {
			return null;
		}
		$payload = json_decode(self::b64url_decode($p), true);
		if (!is_array($payload)) {
			return null;
		}
		if (($payload['iss'] ?? null) !== 'idp' || ($payload['aud'] ?? null) !== 'ork') {
			return null;
		}
		$now = time();
		if (!isset($payload['exp']) || (int)$payload['exp'] < $now - 30) {
			return null;
		}
		$idpUserId = $payload['sub'] ?? '';
		$mundaneId = (int)($payload['mundane_id'] ?? 0);
		$jti       = (string)($payload['jti'] ?? '');
		if ($idpUserId === '' || $mundaneId <= 0) {
			return null;
		}
		return [
			'idp_user_id' => (string)$idpUserId,
			'mundane_id'  => $mundaneId,
			'jti'         => $jti,
		];
	}

	/**
	 * Verify a completion JWT AND atomically consume its jti so the redirect
	 * URL cannot be replayed within the exp window. The jti is recorded in
	 * ork_idp_completion_jti via INSERT; a duplicate-key (replay) returns null.
	 *
	 * Returns null on any failure — signature, claim shape, expiry, missing
	 * jti, or replay.
	 *
	 * @return array{idp_user_id: string, mundane_id: int, jti: string}|null
	 */
	public function verifyAndConsumeCompletionToken($jwt)
	{
		$claims = $this->verifyCompletionToken($jwt);
		if ($claims === null) {
			return null;
		}
		if (empty($claims['jti'])) {
			// Older IDP versions may not include jti; refuse rather than risk
			// silent replay holes.
			return null;
		}

		// Yapo runs PDO in ERRMODE_WARNING, so INSERTs that violate the PK
		// don't throw — they silently no-op. Use INSERT IGNORE + ROW_COUNT()
		// to detect the duplicate path. ROW_COUNT()=1 means we just inserted
		// (first use), 0 means the jti was already present (replay).
		global $DB;
		try {
			$DB->Clear();
			$DB->jti = (string)$claims['jti'];
			$DB->Execute('INSERT IGNORE INTO ' . DB_PREFIX . 'idp_completion_jti (jti, seen_at) VALUES (:jti, NOW())');
			$DB->Clear();

			$rs = $DB->DataSet('SELECT ROW_COUNT() AS rc');
			$rows = ($rs && $rs->Next()) ? (int)$rs->rc : 0;
			$DB->Clear();

			if ($rows < 1) {
				return null; // replay (jti already present) or insert failure — fail closed
			}
		} catch (\Throwable $e) {
			$DB->Clear();
			return null;
		}

		return $claims;
	}

	/**
	 * Best-effort cleanup of consumed jti rows older than 1 hour. Safe to call
	 * from a daily cron. The exp window is 5 minutes; keeping the row an hour
	 * is a generous replay-protection floor.
	 */
	public function cleanCompletionJti()
	{
		global $DB;
		$DB->Clear();
		try {
			$DB->Execute('DELETE FROM ' . DB_PREFIX . 'idp_completion_jti WHERE seen_at < (NOW() - INTERVAL 1 HOUR)');
		} catch (\Throwable $e) {
			// best-effort
		}
		$DB->Clear();
	}

	/**
	 * Mirror an ORK-side link write to bastion-idp via the new S2S endpoint.
	 * Best-effort: updates ork_idp_auth.idp_mirror_status to 'synced' or 'failed'
	 * based on the result. Never throws.
	 *
	 * Lives here (rather than on Authorization) because the project's pre-commit
	 * hook never lets class.Authorization.php be committed, and the original
	 * implementation in Authorization.php had a bad require path that fataled
	 * whenever it was actually exercised.
	 */
	public function mirrorToIdp($idpUserId, $mundaneId)
	{
		// TODO(operations): rows stuck in idp_mirror_status='failed' for >24h
		// should trigger an admin alert / nightly reconciliation cron that
		// retries the IDP mirror call. See finding M3 in the QA review.
		global $DB;
		if (!class_exists('Model_AmtgardIdpLink')) {
			require_once DIR_MODEL . 'model.AmtgardIdpLink.php';
		}
		$model = new Model_AmtgardIdpLink();
		$ok = $model->linkOrkProfile($idpUserId, (int)$mundaneId);

		// Yapo binds via $this->Data + named :placeholders, not positional ?.
		$DB->Clear();
		$DB->idp_mirror_status = $ok ? 'synced' : 'failed';
		$DB->idp_user_id       = (string)$idpUserId;
		$DB->mundane_id        = (int)$mundaneId;
		$DB->Execute(
			"UPDATE " . DB_PREFIX . "idp_auth SET idp_mirror_status = :idp_mirror_status, idp_mirror_last_attempt = NOW() " .
			"WHERE idp_user_id = :idp_user_id AND mundane_id = :mundane_id"
		);
		$DB->Clear();
	}
}
