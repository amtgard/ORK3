<?php include_once __DIR__ . '/_monarchy_only_badge.tpl'; ?>
<style>
.mo-badge { display:inline-flex; align-items:center; gap:4px; margin-left:6px; font-size:11px; font-weight:600; color:#6b46c1; background:rgba(107,70,193,.08); border:1px solid rgba(107,70,193,.45); border-radius:3px; padding:0 5px; white-space:nowrap; position:relative; vertical-align:1px; }
html[data-theme="dark"] .mo-badge { color:#c4b5fd; background:rgba(196,181,253,.10); border-color:rgba(196,181,253,.45); }
.mo-check-hint { font-size:11px; color:#718096; margin-top:3px; padding-left:24px; }
html[data-theme="dark"] .mo-check-hint { color:#a0aec0; }
</style>
<script>
window.orkMonarchyOnlyBadge = function (level) {
    level = parseInt(level, 10) || 0;
    if (level <= 0) return '';
    var tip = level >= 2 ? 'Visible only to kingdom officers' : 'Visible to park and kingdom officers';
    return '<span class="mo-badge" data-tip="' + tip + '"><i class="fas fa-eye-slash" aria-hidden="true"></i> Monarchy Only</span>';
};
</script>
