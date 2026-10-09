<?php

class Notification
{
    private $db;

    public function __construct()
    {
        global $DB;
        $this->db = $DB;
    }

    private function esc($v)
    {
        return str_replace(["'", '\\'], ["''", '\\\\'], (string)$v);
    }

    // Insert one notification. Returns 1 on insert, 0 if the target is invalid.
    public function Add($mundaneId, $type, $message, $link = null)
    {
        $mundaneId = (int)$mundaneId;
        if ($mundaneId <= 0) {
            return 0;
        }
        $linkSql = ($link === null || $link === '') ? 'NULL' : "'" . $this->esc($link) . "'";
        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'notification (mundane_id, type, message, link, created_at) VALUES ('
            . $mundaneId . ", '" . $this->esc($type) . "', '" . $this->esc($message) . "', " . $linkSql . ', NOW())'
        );
        return 1;
    }

    // Non-dismissed notifications for a user, newest first (read + unread).
    public function GetForUser($mundaneId, $limit = 20)
    {
        $mundaneId = (int)$mundaneId;
        $limit = (int)$limit;
        if ($mundaneId <= 0) {
            return [];
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT notification_id, type, message, link, read_at, created_at
               FROM ' . DB_PREFIX . 'notification
              WHERE mundane_id = ' . $mundaneId . ' AND dismissed_at IS NULL
              ORDER BY created_at DESC, notification_id DESC
              LIMIT ' . $limit
        );
        $out = [];
        if ($rs) {
            while ($rs->Next()) {
                $out[] = [
                    'notification_id' => (int)$rs->notification_id,
                    'type'            => $rs->type,
                    'message'         => $rs->message,
                    'link'            => $rs->link,
                    'read_at'         => $rs->read_at,
                    'created_at'      => $rs->created_at,
                ];
            }
        }
        return $out;
    }

    public function CountUnread($mundaneId)
    {
        $mundaneId = (int)$mundaneId;
        if ($mundaneId <= 0) {
            return 0;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'notification
              WHERE mundane_id = ' . $mundaneId . ' AND read_at IS NULL AND dismissed_at IS NULL'
        );
        return ($rs && $rs->Next()) ? (int)$rs->c : 0;
    }

    public function MarkAllRead($mundaneId)
    {
        $mundaneId = (int)$mundaneId;
        if ($mundaneId <= 0) {
            return;
        }
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'notification SET read_at = NOW()
              WHERE mundane_id = ' . $mundaneId . ' AND read_at IS NULL AND dismissed_at IS NULL'
        );
    }

    // Dismiss one notification — scoped to its owner so users can't dismiss others'.
    public function Dismiss($notificationId, $mundaneId)
    {
        $notificationId = (int)$notificationId;
        $mundaneId = (int)$mundaneId;
        if ($notificationId <= 0 || $mundaneId <= 0) {
            return;
        }
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'notification SET dismissed_at = NOW()
              WHERE notification_id = ' . $notificationId . ' AND mundane_id = ' . $mundaneId
        );
    }

    public function DismissAll($mundaneId)
    {
        $mundaneId = (int)$mundaneId;
        if ($mundaneId <= 0) {
            return;
        }
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'notification SET dismissed_at = NOW()
              WHERE mundane_id = ' . $mundaneId . ' AND dismissed_at IS NULL'
        );
    }

    // Domain helper: notify the recommender + active seconders that a recommendation
    // was granted. MUST be called BEFORE the rec/seconds soft-delete so the seconds
    // query (deleted_at IS NULL) still returns rows. Reads + inserts only.
    public function notifyRecommendationGranted($recId, $grantedById)
    {
        $recId = (int)$recId;
        $grantedById = (int)$grantedById;
        if ($recId <= 0) {
            return;
        }

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT r.recommended_by_id, r.mundane_id, r.rank,
                    m.persona AS recipient_persona,
                    ka.name AS ka_name, a.name AS a_name, a.is_ladder
               FROM ' . DB_PREFIX . 'recommendations r
               LEFT JOIN ' . DB_PREFIX . 'mundane m       ON m.mundane_id      = r.mundane_id
               LEFT JOIN ' . DB_PREFIX . 'kingdomaward ka ON ka.kingdomaward_id = r.kingdomaward_id
               LEFT JOIN ' . DB_PREFIX . 'award a         ON a.award_id        = ka.award_id
              WHERE r.recommendations_id = ' . $recId . ' LIMIT 1'
        );
        if (!$rs || !$rs->Next()) {
            return;
        }

        $recipientId   = (int)$rs->mundane_id;
        $recommenderId = (int)$rs->recommended_by_id;
        $persona       = (string)($rs->recipient_persona ?? '');
        $awardName     = $rs->ka_name ?: ($rs->a_name ?: 'an award');
        $rank          = (int)$rs->rank;
        $isLadder      = (int)$rs->is_ladder;
        $awardLabel    = $awardName . (($isLadder && $rank) ? ' (Rank ' . $rank . ')' : '');
        $link          = (defined('UIR') ? UIR : '') . 'Player/profile/' . $recipientId;

        // Recommender (anonymous recs still notify the recommender themselves).
        if ($recommenderId > 0 && $recommenderId !== $grantedById && $recommenderId !== $recipientId) {
            $this->Add(
                $recommenderId,
                'rec_granted',
                'Your recommendation for ' . $persona . ' (' . $awardLabel . ') was granted.',
                $link
            );
        }

        // Seconders — live only; excludes the recommender, recipient, and granter.
        $this->db->Clear();
        $sr = $this->db->DataSet(
            'SELECT supporter_mundane_id FROM ' . DB_PREFIX . 'recommendation_seconds
              WHERE recommendations_id = ' . $recId . ' AND deleted_at IS NULL'
        );
        if ($sr) {
            while ($sr->Next()) {
                $sid = (int)$sr->supporter_mundane_id;
                if ($sid <= 0 || $sid === $recommenderId || $sid === $recipientId || $sid === $grantedById) {
                    continue;
                }
                $this->Add(
                    $sid,
                    'second_granted',
                    $persona . ' received ' . $awardLabel . ' — a recommendation you seconded.',
                    $link
                );
            }
        }
    }

    // Domain helper: a kingdom (or principality) officer has passed a recommendation
    // down, so tell the officers of the recipient's park that there is something on
    // their Recommendations tab to act on. Reads + inserts only.
    //
    // One notice covers "one or more": an officer who already has an unread notice
    // for this park is not sent another, so passing down twenty recommendations in
    // one sitting is one notification, not twenty. Once they have read it, the next
    // pass-down tells them again.
    public function notifyRecommendationPassedDown($recId, $passedById)
    {
        $recId      = (int)$recId;
        $passedById = (int)$passedById;
        if ($recId <= 0) {
            return;
        }

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT m.park_id, k.parent_kingdom_id
               FROM ' . DB_PREFIX . 'recommendations r
               JOIN ' . DB_PREFIX . 'mundane m      ON m.mundane_id = r.mundane_id
               LEFT JOIN ' . DB_PREFIX . 'kingdom k ON k.kingdom_id = m.kingdom_id
              WHERE r.recommendations_id = ' . $recId . ' LIMIT 1'
        );
        if (!$rs || !$rs->Next()) {
            return;
        }
        $parkId = (int)$rs->park_id;
        if ($parkId <= 0) {
            return;
        }
        // The park's own realm did the delegating: a principality's parks answer to
        // the principality, everyone else's to the kingdom.
        $realm   = (int)$rs->parent_kingdom_id > 0 ? 'Principality' : 'Kingdom';
        $message = 'Your ' . $realm . ' has authorized you to give out one or more higher level awards. '
            . 'Check your Park Recommendations tab for details.';
        $link    = (defined('UIR') ? UIR : '') . 'Park/index/' . $parkId . '&tab=recommendations';

        // The seats that act on a park's recommendations. The role is normalised
        // before comparing so the seat is found whether it is stored as the title
        // ('Prime Minister') or as a slug ('prime_minister').
        $this->db->Clear();
        $os = $this->db->DataSet(
            'SELECT DISTINCT o.mundane_id
               FROM ' . DB_PREFIX . 'officer o
              WHERE o.park_id = ' . $parkId . '
                AND o.mundane_id > 0
                AND REPLACE(LOWER(o.role), \'_\', \' \') IN (\'monarch\', \'regent\', \'prime minister\')'
        );
        $officers = [];
        if ($os) {
            while ($os->Next()) {
                $officers[] = (int)$os->mundane_id;
            }
        }

        foreach ($officers as $officerId) {
            if ($officerId === $passedById) {
                continue;
            }
            $this->db->Clear();
            $pending = $this->db->DataSet(
                'SELECT 1 FROM ' . DB_PREFIX . 'notification
                  WHERE mundane_id = ' . $officerId . '
                    AND type = \'recs_passed_down\'
                    AND link = \'' . $this->esc($link) . '\'
                    AND read_at IS NULL AND dismissed_at IS NULL
                  LIMIT 1'
            );
            if ($pending && $pending->Next()) {
                continue;
            }
            $this->Add($officerId, 'recs_passed_down', $message, $link);
        }
    }
}
