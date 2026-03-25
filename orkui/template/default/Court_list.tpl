<?php
$courtList      = $courtList      ?? [];
$upcomingEvents = $upcomingEvents ?? [];
$kingdom_id     = $KingdomId      ?? 0;
$park_id        = $ParkId         ?? 0;
$context        = $Context        ?? 'kingdom';
$locationName   = $LocationName   ?? '';
$canManage      = $CanManage      ?? false;
$error          = $Error          ?? '';

$statusLabel = ['draft' => 'Draft', 'published' => 'Published', 'complete' => 'Complete'];
$statusColor = ['draft' => '#718096', 'published' => '#2b6cb0', 'complete' => '#276749'];
$statusBg    = ['draft' => '#edf2f7', 'published' => '#ebf8ff', 'complete' => '#f0fff4'];

$backUrl = $context === 'park'
    ? UIR . 'Park/profile/' . $park_id
    : UIR . 'Kingdom/profile/' . $kingdom_id;
?>
<style>
.cp-page { max-width: 900px; margin: 24px auto; padding: 0 16px; font-family: inherit; }
.cp-back  { color: #4a5568; font-size: 13px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; margin-bottom: 14px; }
.cp-back:hover { color: #2d3748; }
.cp-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.cp-header h1 { font-size: 22px; font-weight: 700; color: #2d3748; margin: 0; background: none; border: none; padding: 0; text-shadow: none; border-radius: 0; }
.cp-btn-primary { background: #2c5282; color: #fff; border: none; padding: 9px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
.cp-btn-primary:hover { background: #2a4a7f; color: #fff; }
.cp-court-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; margin-bottom: 12px; display: flex; align-items: center; gap: 16px; transition: box-shadow .15s; }
.cp-court-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,.1); }
.cp-court-date { font-size: 13px; color: #718096; white-space: nowrap; min-width: 90px; }
.cp-court-info { flex: 1; }
.cp-court-name { font-weight: 700; font-size: 15px; color: #2d3748; }
.cp-court-meta { font-size: 12px; color: #718096; margin-top: 2px; }
.cp-court-badges { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.cp-badge { display: inline-block; padding: 3px 9px; border-radius: 12px; font-size: 11px; font-weight: 700; }
.cp-badge-count { background: #edf2f7; color: #4a5568; padding: 3px 9px; border-radius: 12px; font-size: 11px; }
.cp-btn-link { background: none; border: 1px solid #cbd5e0; color: #4a5568; padding: 5px 12px; border-radius: 5px; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-block; }
.cp-btn-link:hover { background: #f7fafc; color: #2d3748; }
.cp-empty { text-align: center; padding: 48px 24px; color: #718096; font-size: 15px; border: 1px dashed #e2e8f0; border-radius: 8px; }

/* New Court Modal */
.cp-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 1000; align-items: center; justify-content: center; }
.cp-modal  { background: #fff; border-radius: 10px; width: 100%; max-width: 480px; box-shadow: 0 8px 32px rgba(0,0,0,.2); overflow: hidden; }
.cp-modal-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #e2e8f0; }
.cp-modal-header h3 { margin: 0; font-size: 16px; font-weight: 700; color: #2d3748; background: none; border: none; padding: 0; text-shadow: none; border-radius: 0; }
.cp-modal-close { background: none; border: none; font-size: 20px; cursor: pointer; color: #718096; line-height: 1; }
.cp-modal-body  { padding: 20px; }
.cp-field { margin-bottom: 14px; }
.cp-field label { display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .4px; }
.cp-field input, .cp-field select { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 5px; font-size: 14px; box-sizing: border-box; }
.cp-modal-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 20px; border-top: 1px solid #e2e8f0; }
.cp-btn-outline { background: #fff; border: 1px solid #cbd5e0; color: #4a5568; padding: 8px 16px; border-radius: 5px; font-size: 13px; cursor: pointer; }
.cp-error { color: #c53030; font-size: 13px; margin-top: 8px; display: none; }
</style>

<div class="cp-page">
    <a href="<?= htmlspecialchars($backUrl) ?>" class="cp-back">
        <i class="fas fa-arrow-left"></i> Back to <?= htmlspecialchars($locationName) ?>
    </a>

    <?php if ($error): ?>
        <div style="background:#fff5f5;border:1px solid #feb2b2;color:#c53030;padding:14px 18px;border-radius:6px;margin-bottom:16px">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php else: ?>

    <div class="cp-header">
        <h1><i class="fas fa-gavel" style="color:#4a5568;margin-right:8px"></i>Court Planner — <?= htmlspecialchars($locationName) ?></h1>
        <button class="cp-btn-primary" onclick="cpOpenNewCourt()">
            <i class="fas fa-plus"></i> Plan a Court
        </button>
    </div>

    <?php if (empty($courtList)): ?>
        <div class="cp-empty">
            <i class="fas fa-gavel" style="font-size:32px;margin-bottom:12px;display:block;opacity:.3"></i>
            No courts planned yet. Click <strong>Plan a Court</strong> to get started.
        </div>
    <?php else: ?>
        <?php foreach ($courtList as $court): ?>
        <?php
            $st  = $court['Status'];
            $lbl = $statusLabel[$st] ?? $st;
            $clr = $statusColor[$st] ?? '#718096';
            $bg  = $statusBg[$st]    ?? '#edf2f7';
        ?>
        <div class="cp-court-card">
            <div class="cp-court-date">
                <?= $court['CourtDate'] ? date('M j, Y', strtotime($court['CourtDate'])) : '<em style="color:#a0aec0">No date</em>' ?>
            </div>
            <div class="cp-court-info">
                <div class="cp-court-name"><?= htmlspecialchars($court['Name']) ?></div>
                <?php if ($court['EventName']): ?>
                <div class="cp-court-meta"><i class="fas fa-calendar-alt" style="margin-right:3px"></i><?= htmlspecialchars($court['EventName']) ?></div>
                <?php endif; ?>
            </div>
            <div class="cp-court-badges">
                <span class="cp-badge" style="background:<?= $bg ?>;color:<?= $clr ?>"><?= $lbl ?></span>
                <span class="cp-badge-count"><i class="fas fa-award" style="margin-right:3px"></i><?= (int)$court['AwardCount'] ?></span>
                <a href="<?= UIR ?>Court/detail/<?= (int)$court['CourtId'] ?>" class="cp-btn-link">
                    Open <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php endif; ?>
</div>

<!-- New Court Modal -->
<div class="cp-overlay" id="cp-new-court-modal">
    <div class="cp-modal">
        <div class="cp-modal-header">
            <h3><i class="fas fa-gavel" style="margin-right:8px;color:#4a5568"></i>Plan a New Court</h3>
            <button class="cp-modal-close" onclick="cpCloseNewCourt()">&times;</button>
        </div>
        <div class="cp-modal-body">
            <div class="cp-field">
                <label>Court Name <span style="color:#e53e3e">*</span></label>
                <input type="text" id="cp-new-name" placeholder="Summer Coronation Court, Crown Quals Court…" autocomplete="off">
            </div>
            <?php if (!empty($upcomingEvents)): ?>
            <div class="cp-field">
                <label>Link to Event (optional)</label>
                <select id="cp-new-event" onchange="cpOnEventChange(this,'cp-new-date')">
                    <option value="0" data-start="">— None —</option>
                    <?php foreach ($upcomingEvents as $ev): ?>
                    <option value="<?= (int)$ev['EventCalendarDetailId'] ?>" data-start="<?= $ev['EventStart'] ? date('Y-m-d', strtotime($ev['EventStart'])) : '' ?>">
                        <?= htmlspecialchars($ev['Name']) ?><?= $ev['EventStart'] ? ' (' . date('M j', strtotime($ev['EventStart'])) . ')' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="cp-field">
                <label>Date</label>
                <input type="date" id="cp-new-date">
            </div>
            <div class="cp-error" id="cp-new-error"></div>
        </div>
        <div class="cp-modal-footer">
            <button class="cp-btn-outline" onclick="cpCloseNewCourt()">Cancel</button>
            <button class="cp-btn-primary" onclick="cpSubmitNewCourt()">
                <i class="fas fa-plus"></i> Create Court
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    var uir        = '<?= UIR ?>';
    var kingdomId  = <?= (int)$kingdom_id ?>;
    var parkId     = <?= (int)$park_id ?>;

    window.cpOnEventChange = function(sel, dateId) {
        var opt = sel.options[sel.selectedIndex];
        var start = opt ? opt.getAttribute('data-start') : '';
        if (start) document.getElementById(dateId).value = start;
    };

    window.cpOpenNewCourt = function() {
        document.getElementById('cp-new-name').value  = '';
        document.getElementById('cp-new-date').value  = '';
        var evEl = document.getElementById('cp-new-event');
        if (evEl) evEl.value = '0';
        document.getElementById('cp-new-error').style.display = 'none';
        document.getElementById('cp-new-court-modal').style.display = 'flex';
        setTimeout(function() { document.getElementById('cp-new-name').focus(); }, 50);
    };

    window.cpCloseNewCourt = function() {
        document.getElementById('cp-new-court-modal').style.display = 'none';
    };

    window.cpSubmitNewCourt = function() {
        var name    = document.getElementById('cp-new-name').value.trim();
        var date    = document.getElementById('cp-new-date').value;
        var evEl    = document.getElementById('cp-new-event');
        var eventId = evEl ? evEl.value : '0';
        var errEl   = document.getElementById('cp-new-error');

        if (!name) { errEl.textContent = 'Please enter a court name.'; errEl.style.display = 'block'; return; }
        errEl.style.display = 'none';

        var fd = new FormData();
        fd.append('KingdomId',               kingdomId);
        fd.append('ParkId',                  parkId);
        fd.append('Name',                    name);
        fd.append('CourtDate',               date);
        fd.append('EventCalendarDetailId',   eventId);

        fetch(uir + 'CourtAjax/create_court', {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.status === 0 && data.court_id) {
                window.location.href = uir + 'Court/detail/' + data.court_id;
            } else {
                errEl.textContent = data.error || 'An error occurred.';
                errEl.style.display = 'block';
            }
        })
        .catch(function(e) { errEl.textContent = 'Request failed: ' + e.message; errEl.style.display = 'block'; });
    };

    // Close on backdrop click / Escape
    document.getElementById('cp-new-court-modal').addEventListener('click', function(e) {
        if (e.target === this) cpCloseNewCourt();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') cpCloseNewCourt();
    });
})();
</script>
