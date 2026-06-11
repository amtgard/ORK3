# In-App Recommendation Notifications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a recommendation results in a granted award, write in-app notifications to the recommender and any seconders, and surface them on the user's My Amtgard dashboard.

**Architecture:** A generic `ork_notification` table written by a new auto-registered lib class `class.Notification.php` (available as `Ork3::$Lib->notification`). A domain helper `notifyRecommendationGranted()` is fired — *before* the rec/seconds soft-delete — from both grant paths: `CourtAjax::grant_award` (court) and `class.Player::DeleteAwardRecommendation` (Manager Grant Now, via a `Granted` request flag). The dashboard (`Playernew_index.tpl`, own profile) renders a Notifications card; the controller marks them read on view; `PlayerAjax` endpoints dismiss them.

**Tech Stack:** PHP (ork3 lib classes use `global $DB; $this->db->Clear()/DataSet()/Execute()`), MariaDB, plain-PHP `.tpl` templates with inline CSS/JS, `pna-` dashboard CSS prefix.

**Verification model:** No PHP unit-test framework. Verify via `php -l` lint (local PHP works; Docker may be down), curl-auth session + DB read-back where the schema exists, and a dashboard/dark-mode walkthrough. The **recommendations + recommendation_seconds tables exist locally**, so Trigger 2 (Manager Grant Now) and the dashboard card **can** be exercised locally; the **court tables do not exist locally**, so Trigger 1 (court grant) is verified by lint + inspection only.

**Conventions to honor:** `$DB->Clear()` before every raw Execute; `.tpl` is plain PHP (no Smarty); no native `title` tooltips (use `data-tip`); dark-mode compatibility; human-readable times; stage files explicitly (never `git add -A`, never stage `class.Authorization.php`).

---

## File Structure

- **Create** `db-migrations/2026-06-11-add-notification-table.sql` — the table.
- **Create** `system/lib/ork3/class.Notification.php` — store + `notifyRecommendationGranted` (auto-registered as `Ork3::$Lib->notification` by `startup.php`).
- **Modify** `orkui/controller/controller.CourtAjax.php` — Trigger 1 in `grant_award`.
- **Modify** `system/lib/ork3/class.Player.php` — Trigger 2 inside `DeleteAwardRecommendation`.
- **Modify** `orkui/controller/controller.KingdomAjax.php` + `controller.ParkAjax.php` — pass `Granted` through `dismissrecommendation`.
- **Modify** `orkui/template/revised-frontend/Recommendations_manage.tpl` — send `Granted=1` from `rmDoGrant`.
- **Modify** `orkui/controller/controller.Player.php` — load notifications + mark read on own-profile view.
- **Modify** `orkui/controller/controller.PlayerAjax.php` — `dismiss_notification` + `dismiss_all_notifications`.
- **Modify** `orkui/template/revised-frontend/Playernew_index.tpl` — Notifications card (HTML + CSS + JS).

---

### Task 1: Migration — `ork_notification`

**Files:**
- Create: `db-migrations/2026-06-11-add-notification-table.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Generic in-app notification store. v1 writers: recommendation-granted
-- notifications to recommenders + seconders (see class.Notification.php).
CREATE TABLE IF NOT EXISTS ork_notification (
    notification_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    mundane_id       INT UNSIGNED NOT NULL,            -- who sees this notification
    type             VARCHAR(40)  NOT NULL,            -- 'rec_granted' | 'second_granted'
    message          VARCHAR(400) NOT NULL,            -- rendered sentence (denormalized)
    link             VARCHAR(255) NULL DEFAULT NULL,   -- where clicking navigates
    read_at          TIMESTAMP NULL DEFAULT NULL,
    dismissed_at     TIMESTAMP NULL DEFAULT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id),
    KEY idx_user_active (mundane_id, dismissed_at, read_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

- [ ] **Step 2: Apply to the local DB (recommendations schema exists locally)**

Run: `docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-06-11-add-notification-table.sql`
Expected: no error. If Docker is down, note it and apply where the DB is reachable.
Verify: `docker exec ork3-php8-db mariadb -u root -proot ork -e "SHOW COLUMNS FROM ork_notification;"` shows all 8 columns.

- [ ] **Step 3: Commit**

```bash
git add db-migrations/2026-06-11-add-notification-table.sql
git commit -m "Notifications: add ork_notification table"
```

---

### Task 2: `class.Notification.php` — store + domain helper

**Files:**
- Create: `system/lib/ork3/class.Notification.php`

- [ ] **Step 1: Write the class**

```php
<?php

class Notification {

    private $db;

    public function __construct() {
        global $DB;
        $this->db = $DB;
    }

    private function esc($v) {
        return str_replace(["'", '\\'], ["''", '\\\\'], (string)$v);
    }

    // Insert one notification. Returns 1 on insert, 0 if the target is invalid.
    public function Add($mundaneId, $type, $message, $link = null) {
        $mundaneId = (int)$mundaneId;
        if ($mundaneId <= 0) return 0;
        $linkSql = ($link === null || $link === '') ? 'NULL' : "'" . $this->esc($link) . "'";
        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'notification (mundane_id, type, message, link, created_at) VALUES ('
            . $mundaneId . ", '" . $this->esc($type) . "', '" . $this->esc($message) . "', " . $linkSql . ', NOW())'
        );
        return 1;
    }

    // Non-dismissed notifications for a user, newest first (read + unread).
    public function GetForUser($mundaneId, $limit = 20) {
        $mundaneId = (int)$mundaneId; $limit = (int)$limit;
        if ($mundaneId <= 0) return [];
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

    public function CountUnread($mundaneId) {
        $mundaneId = (int)$mundaneId;
        if ($mundaneId <= 0) return 0;
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'notification
              WHERE mundane_id = ' . $mundaneId . ' AND read_at IS NULL AND dismissed_at IS NULL'
        );
        return ($rs && $rs->Next()) ? (int)$rs->c : 0;
    }

    public function MarkAllRead($mundaneId) {
        $mundaneId = (int)$mundaneId;
        if ($mundaneId <= 0) return;
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'notification SET read_at = NOW()
              WHERE mundane_id = ' . $mundaneId . ' AND read_at IS NULL AND dismissed_at IS NULL'
        );
    }

    // Dismiss one notification — scoped to its owner so users can't dismiss others'.
    public function Dismiss($notificationId, $mundaneId) {
        $notificationId = (int)$notificationId; $mundaneId = (int)$mundaneId;
        if ($notificationId <= 0 || $mundaneId <= 0) return;
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'notification SET dismissed_at = NOW()
              WHERE notification_id = ' . $notificationId . ' AND mundane_id = ' . $mundaneId
        );
    }

    public function DismissAll($mundaneId) {
        $mundaneId = (int)$mundaneId;
        if ($mundaneId <= 0) return;
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'notification SET dismissed_at = NOW()
              WHERE mundane_id = ' . $mundaneId . ' AND dismissed_at IS NULL'
        );
    }

    // Domain helper: notify the recommender + active seconders that a recommendation
    // was granted. MUST be called BEFORE the rec/seconds soft-delete so the seconds
    // query (deleted_at IS NULL) still returns rows. Reads + inserts only.
    public function notifyRecommendationGranted($recId, $grantedById) {
        $recId = (int)$recId; $grantedById = (int)$grantedById;
        if ($recId <= 0) return;

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
        if (!$rs || !$rs->Next()) return;

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
            $this->Add($recommenderId, 'rec_granted',
                'Your recommendation for ' . $persona . ' (' . $awardLabel . ') was granted.', $link);
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
                if ($sid <= 0 || $sid === $recommenderId || $sid === $recipientId || $sid === $grantedById) continue;
                $this->Add($sid, 'second_granted',
                    $persona . ' received ' . $awardLabel . ' — a recommendation you seconded.', $link);
            }
        }
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l system/lib/ork3/class.Notification.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verify auto-registration**

`startup.php` scans `system/lib/ork3/class.*.php` and registers each as `Ork3::$Lib->{lowercasename}`. Confirm the class name is exactly `Notification` (→ `Ork3::$Lib->notification`) and the constructor takes no args.
Run: `grep -n "class Notification" system/lib/ork3/class.Notification.php`
Expected: `class Notification {`

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.Notification.php
git commit -m "Notifications: class.Notification store + notifyRecommendationGranted"
```

---

### Task 3: Trigger 1 — court grant (`CourtAjax::grant_award`)

**Files:**
- Modify: `orkui/controller/controller.CourtAjax.php` (in `grant_award`, the rec soft-delete block)

- [ ] **Step 1: Fire the notification before the soft-delete**

Find this block in `grant_award`:

```php
        // Soft-delete the linked recommendation
        if ((int)$ca->recommendations_id > 0) {
            $DB->Clear();
            $DB->Execute(
                'UPDATE ' . DB_PREFIX . 'recommendations
                 SET deleted_by = ' . $uid . ', deleted_at = NOW()
                 WHERE recommendations_id = ' . (int)$ca->recommendations_id
            );
        }
```

Replace it with (notify first, then soft-delete; notify is non-blocking):

```php
        // Notify the recommender + seconders BEFORE soft-deleting the recommendation
        // (the seconds query needs them still live). Non-blocking: never fail the grant.
        if ((int)$ca->recommendations_id > 0) {
            try {
                Ork3::$Lib->notification->notifyRecommendationGranted((int)$ca->recommendations_id, $uid);
            } catch (\Throwable $e) { /* notifications are best-effort */ }

            $DB->Clear();
            $DB->Execute(
                'UPDATE ' . DB_PREFIX . 'recommendations
                 SET deleted_by = ' . $uid . ', deleted_at = NOW()
                 WHERE recommendations_id = ' . (int)$ca->recommendations_id
            );
        }
```

- [ ] **Step 2: Lint**

Run: `php -l orkui/controller/controller.CourtAjax.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.CourtAjax.php
git commit -m "Notifications: notify advocates on court grant"
```

---

### Task 4: Trigger 2 — Manager Grant Now (`DeleteAwardRecommendation` + plumbing)

**Files:**
- Modify: `system/lib/ork3/class.Player.php` (`DeleteAwardRecommendation`, before the soft-delete)
- Modify: `orkui/controller/controller.KingdomAjax.php` (`dismissrecommendation`)
- Modify: `orkui/controller/controller.ParkAjax.php` (`dismissrecommendation`)
- Modify: `orkui/template/revised-frontend/Recommendations_manage.tpl` (`rmDoGrant`)

- [ ] **Step 1: Fire notify in `DeleteAwardRecommendation` when `$request['Granted']` is set**

In `system/lib/ork3/class.Player.php`, inside `DeleteAwardRecommendation`, find the soft-delete line:

```php
					$cascade_at = date('Y-m-d H:i:s');
					$awardRec->deleted_by = $request['RequestedBy'];
```

Replace with (notify before the soft-delete + cascade, only when granted):

```php
					$cascade_at = date('Y-m-d H:i:s');
					// Granted-from-Manager: notify advocates BEFORE the rec/seconds soft-delete.
					if (!empty($request['Granted'])) {
						try {
							Ork3::$Lib->notification->notifyRecommendationGranted(
								(int)$awardRec->recommendations_id, (int)$request['RequestedBy']);
						} catch (\Throwable $e) { /* best-effort */ }
					}
					$awardRec->deleted_by = $request['RequestedBy'];
```

- [ ] **Step 2: Lint class.Player.php**

Run: `php -l system/lib/ork3/class.Player.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Pass `Granted` through the KingdomAjax dismiss endpoint**

In `orkui/controller/controller.KingdomAjax.php`, find the `dismissrecommendation` request:

```php
			$r = $this->Player->delete_player_recommendation([
				'Token'             => $this->session->token,
				'RecommendationsId' => $rec_id,
				'RequestedBy'       => $this->session->user_id,
			]);
```

Replace with:

```php
			$r = $this->Player->delete_player_recommendation([
				'Token'             => $this->session->token,
				'RecommendationsId' => $rec_id,
				'RequestedBy'       => $this->session->user_id,
				'Granted'           => !empty($_POST['Granted']) ? 1 : 0,
			]);
```

- [ ] **Step 4: Pass `Granted` through the ParkAjax dismiss endpoint**

In `orkui/controller/controller.ParkAjax.php`, find the matching `dismissrecommendation` call to `delete_player_recommendation` (same shape: Token / RecommendationsId / RequestedBy) and add the same `'Granted' => !empty($_POST['Granted']) ? 1 : 0,` line to its request array.

Run first to locate it: `grep -n "delete_player_recommendation" orkui/controller/controller.ParkAjax.php`
If ParkAjax does **not** have a `dismissrecommendation` action, skip this step and note it (the Manager uses KingdomAjax for kingdom scope and ParkAjax for park scope — both must carry `Granted` if both exist).

- [ ] **Step 5: Send `Granted=1` from the Manager grant flow**

In `orkui/template/revised-frontend/Recommendations_manage.tpl`, in `rmDoGrant`, find the dismiss POST:

```javascript
    }).then(function () {
        var fd2 = new FormData(); fd2.append('RecommendationsId', rec.RecommendationsId);
        return rmPost(rmRecAjaxBase('dismissrecommendation'), fd2);
```

Replace with (mark this dismissal as a grant so the server notifies):

```javascript
    }).then(function () {
        var fd2 = new FormData();
        fd2.append('RecommendationsId', rec.RecommendationsId);
        fd2.append('Granted', '1');
        return rmPost(rmRecAjaxBase('dismissrecommendation'), fd2);
```

- [ ] **Step 6: Lint the controllers + template**

Run:
```
php -l orkui/controller/controller.KingdomAjax.php
php -l orkui/controller/controller.ParkAjax.php
php -l orkui/template/revised-frontend/Recommendations_manage.tpl
```
Expected: `No syntax errors detected` for each.

- [ ] **Step 7: Verify plain Dismiss does NOT send Granted**

Confirm the standalone Dismiss handlers in `Recommendations_manage.tpl` (the `dismissrecommendation` POSTs at the row-dismiss and bulk-dismiss handlers, NOT inside `rmDoGrant`) do not append `Granted`.
Run: `grep -n "append('Granted'" orkui/template/revised-frontend/Recommendations_manage.tpl`
Expected: exactly **one** match (inside `rmDoGrant`).

- [ ] **Step 8: Commit**

```bash
git add system/lib/ork3/class.Player.php orkui/controller/controller.KingdomAjax.php orkui/controller/controller.ParkAjax.php orkui/template/revised-frontend/Recommendations_manage.tpl
git commit -m "Notifications: notify advocates on Manager Grant Now (Granted flag)"
```

---

### Task 5: Dashboard load + mark-read (`controller.Player::profile`)

**Files:**
- Modify: `orkui/controller/controller.Player.php` (`profile()`, after the cancel-rsvp block ~line 297)

- [ ] **Step 1: Load notifications for the own-profile view and mark them read**

In `profile()`, immediately after the cancel-RSVP block (the `if ($uid > 0 && $uid === (int)$id && isset($this->request->cancel_rsvp_detail_id)) { ... }`), add:

```php
		// My Amtgard dashboard notifications (own profile only). Load the snapshot for
		// this render (unread highlight intact), then mark read so the next visit is clean.
		$this->data['Notifications']      = [];
		$this->data['NotificationUnread'] = 0;
		if ($uid > 0 && $uid === (int)$id) {
			$this->data['Notifications']      = Ork3::$Lib->notification->GetForUser($uid, 20);
			$this->data['NotificationUnread'] = Ork3::$Lib->notification->CountUnread($uid);
			Ork3::$Lib->notification->MarkAllRead($uid);
		}
```

- [ ] **Step 2: Lint**

Run: `php -l orkui/controller/controller.Player.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.Player.php
git commit -m "Notifications: load + mark-read on own-profile dashboard"
```

---

### Task 6: Dismiss endpoints (`controller.PlayerAjax`)

**Files:**
- Modify: `orkui/controller/controller.PlayerAjax.php` (add two methods, mirroring `add_second`)

- [ ] **Step 1: Add the two endpoints**

Add these two methods to the `Controller_PlayerAjax` class (e.g. after `add_second`):

```php
	public function dismiss_notification($p = null) {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) { echo json_encode(['status' => 5, 'error' => 'Not logged in']); exit; }
		$nid = (int)($_POST['NotificationId'] ?? $p ?? 0);
		if (!valid_id($nid)) { echo json_encode(['status' => 1, 'error' => 'Invalid notification']); exit; }
		Ork3::$Lib->notification->Dismiss($nid, (int)$this->session->user_id);
		echo json_encode(['status' => 0]); exit;
	}

	public function dismiss_all_notifications($p = null) {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) { echo json_encode(['status' => 5, 'error' => 'Not logged in']); exit; }
		Ork3::$Lib->notification->DismissAll((int)$this->session->user_id);
		echo json_encode(['status' => 0]); exit;
	}
```

- [ ] **Step 2: Lint**

Run: `php -l orkui/controller/controller.PlayerAjax.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.PlayerAjax.php
git commit -m "Notifications: PlayerAjax dismiss + dismiss-all endpoints"
```

---

### Task 7: Notifications card on the dashboard (`Playernew_index.tpl`)

**Files:**
- Modify: `orkui/template/revised-frontend/Playernew_index.tpl` (CSS block; `pna-feed` top; JS block)

- [ ] **Step 1: Add a relative-time helper near the other dashboard PHP helpers (top of file, in the leading `<?php ... ?>` setup area)**

```php
<?php if (!function_exists('pnaRelTime')) {
    function pnaRelTime($ts) {
        $t = strtotime((string)$ts);
        if (!$t) return '';
        $d = time() - $t;
        if ($d < 60)     return 'just now';
        if ($d < 3600)   return floor($d / 60) . 'm ago';
        if ($d < 86400)  return floor($d / 3600) . 'h ago';
        if ($d < 604800) return floor($d / 86400) . 'd ago';
        return date('M j', $t);
    }
} ?>
```

- [ ] **Step 2: Add the card as the first item in the `pna-feed` column**

Find the start of the feed column (`<div class="pna-feed">` or `class="pna-feed"`) inside the `$isOwnProfile` My Amtgard section, and insert this as its first child:

```php
<?php $pnaNotifs = $Notifications ?? []; if (($isOwnProfile ?? false) && count($pnaNotifs)) {
    $pnaUnread = 0; foreach ($pnaNotifs as $n) { if (empty($n['read_at'])) $pnaUnread++; }
?>
<div class="pna-card pna-notif-card" id="pna-notif-card">
    <div class="pna-notif-head">
        <span class="pna-notif-title"><i class="fas fa-bell"></i> Notifications<?php if ($pnaUnread) { ?> <span class="pna-notif-count"><?= (int)$pnaUnread ?></span><?php } ?></span>
        <button type="button" class="pna-notif-clearall" onclick="pnaDismissAllNotifs()">Clear all</button>
    </div>
    <ul class="pna-notif-list" id="pna-notif-list">
        <?php foreach ($pnaNotifs as $n) {
            $nid    = (int)$n['notification_id'];
            $unread = empty($n['read_at']);
            $msg    = htmlspecialchars($n['message']);
            $lnk    = (string)($n['link'] ?? '');
            $rel    = htmlspecialchars(pnaRelTime($n['created_at']));
        ?>
        <li class="pna-notif-item<?= $unread ? ' pna-notif-unread' : '' ?>" data-nid="<?= $nid ?>">
            <i class="fas fa-award pna-notif-icon"></i>
            <?php if ($lnk) { ?><a class="pna-notif-msg" href="<?= htmlspecialchars($lnk) ?>"><?= $msg ?></a><?php } else { ?><span class="pna-notif-msg"><?= $msg ?></span><?php } ?>
            <span class="pna-notif-time"><?= $rel ?></span>
            <button type="button" class="pna-notif-x" data-tip="Dismiss" onclick="pnaDismissNotif(<?= $nid ?>)">&times;</button>
        </li>
        <?php } ?>
    </ul>
</div>
<?php } ?>
```

- [ ] **Step 3: Add CSS for the card (in the template's `<style>` block, near the other `pna-` rules)**

```css
.pna-notif-card { padding: 0; overflow: hidden; }
.pna-notif-head { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border-bottom: 1px solid #e2e8f0; }
.pna-notif-title { font-weight: 700; font-size: 14px; color: #2d3748; }
.pna-notif-title i { color: #b7791f; margin-right: 5px; }
.pna-notif-count { display: inline-block; min-width: 18px; height: 18px; line-height: 18px; text-align: center; background: #c53030; color: #fff; border-radius: 9px; font-size: 11px; font-weight: 700; padding: 0 5px; margin-left: 4px; }
.pna-notif-clearall { background: none; border: none; color: #718096; font-size: 12px; cursor: pointer; padding: 2px 4px; }
.pna-notif-clearall:hover { color: #2b6cb0; text-decoration: underline; }
.pna-notif-list { list-style: none; margin: 0; padding: 0; }
.pna-notif-item { display: flex; align-items: center; gap: 9px; padding: 10px 14px; border-bottom: 1px solid #edf2f7; font-size: 13px; }
.pna-notif-item:last-child { border-bottom: none; }
.pna-notif-unread { background: #ebf8ff; box-shadow: inset 3px 0 0 #2b6cb0; }
.pna-notif-icon { color: #b7791f; flex-shrink: 0; }
.pna-notif-msg { flex: 1; color: #2d3748; text-decoration: none; }
a.pna-notif-msg:hover { text-decoration: underline; }
.pna-notif-time { color: #a0aec0; font-size: 11px; white-space: nowrap; }
.pna-notif-x { background: none; border: none; color: #a0aec0; font-size: 18px; line-height: 1; cursor: pointer; padding: 0 2px; }
.pna-notif-x:hover { color: #c53030; }
/* dark mode */
html[data-theme="dark"] .pna-notif-head { border-color: #2d3748; }
html[data-theme="dark"] .pna-notif-title { color: #e2e8f0; }
html[data-theme="dark"] .pna-notif-item { border-color: #1f2733; }
html[data-theme="dark"] .pna-notif-unread { background: #1a2740; box-shadow: inset 3px 0 0 #2b6cb0; }
html[data-theme="dark"] .pna-notif-msg { color: #e2e8f0; }
html[data-theme="dark"] .pna-notif-clearall { color: #718096; }
```

- [ ] **Step 4: Add the JS (in the template's `<script>` block)**

```javascript
function pnaNotifMaybeHide() {
    var list = document.getElementById('pna-notif-list');
    if (list && !list.children.length) {
        var c = document.getElementById('pna-notif-card');
        if (c) c.remove();
    }
}
function pnaDismissNotif(nid) {
    var fd = new FormData(); fd.append('NotificationId', nid);
    fetch('<?= UIR ?>PlayerAjax/dismiss_notification', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (j && j.status === 0) {
                var li = document.querySelector('.pna-notif-item[data-nid="' + nid + '"]');
                if (li) li.remove();
                pnaNotifMaybeHide();
            }
        });
}
function pnaDismissAllNotifs() {
    fetch('<?= UIR ?>PlayerAjax/dismiss_all_notifications', { method: 'POST', credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (j && j.status === 0) {
                var c = document.getElementById('pna-notif-card');
                if (c) c.remove();
            }
        });
}
```

- [ ] **Step 5: Lint**

Run: `php -l orkui/template/revised-frontend/Playernew_index.tpl`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add orkui/template/revised-frontend/Playernew_index.tpl
git commit -m "Notifications: dashboard card (auto-read-on-view, dismiss, dark mode)"
```

---

### Task 8: Verification

**Files:** none (manual verification).

- [ ] **Step 1: Lint everything**

```
php -l system/lib/ork3/class.Notification.php
php -l system/lib/ork3/class.Player.php
php -l orkui/controller/controller.CourtAjax.php
php -l orkui/controller/controller.KingdomAjax.php
php -l orkui/controller/controller.ParkAjax.php
php -l orkui/controller/controller.Player.php
php -l orkui/controller/controller.PlayerAjax.php
php -l orkui/template/revised-frontend/Recommendations_manage.tpl
php -l orkui/template/revised-frontend/Playernew_index.tpl
```
Expected: `No syntax errors detected` for all.

- [ ] **Step 2: Local end-to-end of Trigger 2 + dashboard (recommendations schema exists locally)**

Using a curl-auth session (see the project's local-curl-auth pattern: log in via `Login/login`, reuse one cookie jar):
  1. Pick a recommendation in the DB with a known `recommended_by_id` and at least one active row in `ork_recommendation_seconds`.
  2. POST `KingdomAjax/kingdom/{kid}/dismissrecommendation` with `RecommendationsId={id}` **and** `Granted=1`.
  3. DB read-back: `SELECT mundane_id, type, message FROM ork_notification WHERE type IN ('rec_granted','second_granted') ORDER BY notification_id DESC LIMIT 10;` — expect one `rec_granted` for the recommender and one `second_granted` per active seconder; none for the granter/recipient.
  4. Repeat with `Granted` omitted → expect **no** new notification rows (plain dismiss).

- [ ] **Step 3: Dashboard render + dark mode**

Log in as the recommender from Step 2 and load `Player/profile/{their_id}`. Confirm the Notifications card shows with the unread highlight + count; reload → rows now read (no highlight); click ✕ on one → it disappears (AJAX); Clear all → card disappears. Toggle dark mode and confirm the card, unread highlight, count badge, and dismiss control are all readable. Confirm another user's profile does **not** show these notifications.

- [ ] **Step 4: Court path (inspection only — no local court tables)**

Re-read the `grant_award` edit (Task 3) and confirm `notifyRecommendationGranted` is called inside the `recommendations_id > 0` guard **before** the soft-delete `UPDATE`. Note in the report that the court-grant trigger is verified by inspection + lint only, pending an environment with the court schema.

- [ ] **Step 5: Final commit (only if verification required fixes)**

```bash
git add <changed files>
git commit -m "Notifications: verification fixes"
```

---

## Self-Review

**Spec coverage:**
- `ork_notification` table → Task 1. ✓
- `class.Notification` store (Add/GetForUser/CountUnread/MarkAllRead/Dismiss/DismissAll) + `notifyRecommendationGranted` → Task 2. ✓
- Trigger 1 court grant, notify before soft-delete → Task 3. ✓
- Trigger 2 Manager Grant Now via `Granted` flag through DeleteAwardRecommendation + Kingdom/ParkAjax + rmDoGrant; plain Dismiss excluded → Task 4 (Steps 1,3,4,5,7). ✓
- Non-blocking notify (try/catch) → Tasks 3 & 4. ✓
- Notify captured before the seconds cascade soft-delete → Task 4 Step 1 (inserted before `$awardRec->deleted_by`/`save()` and the cascade). ✓
- Dashboard load + auto-read-on-view → Task 5. ✓
- Per-item dismiss + Clear all endpoints + card → Tasks 6, 7. ✓
- Hidden when empty; unread highlight + count → Task 7 Step 2/3. ✓
- Excludes self/recipient; seconder exclusions → Task 2 helper. ✓
- Anonymous recommender still notified → Task 2 helper (no `mask_giver` gate on the recommender insert). ✓

**Placeholder scan:** No TBD/TODO; every code step has complete code. Task 4 Step 4 contains a conditional ("if ParkAjax lacks the action, skip + note") — this is a real instruction with a grep to resolve it, not a placeholder. ✓

**Type/name consistency:** `Ork3::$Lib->notification` matches the class name `Notification` (lowercased by `startup.php`). Method names (`Add`, `GetForUser`, `CountUnread`, `MarkAllRead`, `Dismiss`, `DismissAll`, `notifyRecommendationGranted`) are identical across Tasks 2/5/6. Data keys (`notification_id`, `type`, `message`, `link`, `read_at`, `created_at`) match between `GetForUser` (Task 2), the controller (`Notifications`/`NotificationUnread`, Task 5), and the template (Task 7). The endpoint names (`dismiss_notification`, `dismiss_all_notifications`) match between Task 6 and the JS fetch URLs in Task 7. ✓
