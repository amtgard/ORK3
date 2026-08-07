<?php

class Dangeraudit extends Ork3
{
    public function __construct()
    {
        parent::__construct();
        $this->audit = new yapo($this->db, DB_PREFIX . 'danger_audit');
    }

    // Credentials and session material that must never be persisted into an
    // audit row. Callers used to be responsible for stripping these themselves
    // and mostly did not: 642,535 existing rows carry a live session Token
    // verbatim in `parameters`. Only remove_auth_h ever unset() it, which is why
    // the practice looked inconsistent. Scrubbing centrally here means no caller
    // can get it wrong.
    // Listed in normalized form: lower-cased with underscores and dashes removed,
    // which is how scrub() compares them.
    private static $REDACTED_KEYS = [
        'token', 'password', 'passwordconfirm', 'confirmpassword', 'newpassword',
        'oldpassword', 'currentpassword', 'appsecret', 'apikey', 'secret',
        'sessionid', 'csrf', 'csrftoken', 'applicationauthorizationkey',
        'appauthkey', 'passwordsalt',
    ];

    // Recursively drop credential-bearing keys. Matching is case-insensitive and
    // ignores underscores/dashes so Token / token / password_salt all hit.
    private function scrub($value, $depth = 0)
    {
        if ($depth > 8) {
            return $value;
        }
        // Some callers hand us a stdClass state snapshot rather than an array.
        // json_encode renders both as a JSON object, so normalizing is lossless.
        if (is_object($value) && !($value instanceof JsonSerializable)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $clean = array();
        foreach ($value as $k => $v) {
            $needle = strtolower(str_replace(array('_', '-'), '', (string)$k));
            if (in_array($needle, self::$REDACTED_KEYS, true)) {
                $clean[$k] = '[redacted]';
                continue;
            }
            $clean[$k] = is_array($v) ? $this->scrub($v, $depth + 1) : $v;
        }
        return $clean;
    }

    public function audit($call, $parameters, $entity, $entity_id, $prior_state = null, $post_state = null)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($_SESSION['is_authorized_mundane_id']);
        $this->audit->clear();
        $this->audit->method_call = $call;
        $this->audit->parameters = json_encode($this->scrub($parameters));
        $this->audit->entity = $entity;
        $this->audit->entity_id = $entity_id;
        $this->audit->prior_state = json_encode($this->scrub($prior_state));
        $this->audit->post_state = json_encode($this->scrub($post_state));
        $this->audit->by_whom_id = $mundane_id;
        $this->audit->modified_at = date('Y-m-d H:i:s');
        $this->audit->save();
        // Yapo does not reliably persist entity_id (int column with DEFAULT 0).
        // Patch it directly after insert using the last-inserted PK.
        $eid = (int)$entity_id;
        if ($eid > 0) {
            $pk = (int)$this->audit->{$this->audit->primarykey()};
            if ($pk > 0) {
                // Clear leftover bind params from the audit save — without this,
                // PDO would bind them to this placeholder-free UPDATE and fail
                // silently (ERRMODE_WARNING), leaving entity_id at 0.
                $this->db->Clear();
                $this->db->Execute("UPDATE " . DB_PREFIX . "danger_audit SET entity_id = $eid WHERE danger_audit_id = $pk");
            }
        }
    }

}
