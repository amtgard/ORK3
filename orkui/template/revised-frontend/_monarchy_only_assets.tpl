<?php include_once __DIR__ . '/_monarchy_only_badge.tpl'; ?>
<style>
.mo-badge { display:inline-flex; align-items:center; gap:4px; margin-left:6px; font-size:11px; font-weight:600; color:#6b46c1; background:rgba(107,70,193,.08); border:1px solid rgba(107,70,193,.45); border-radius:3px; padding:0 5px; white-space:nowrap; position:relative; vertical-align:1px; }
html[data-theme="dark"] .mo-badge { color:#c4b5fd; background:rgba(196,181,253,.10); border-color:rgba(196,181,253,.45); }
.mo-check-hint { font-size:11px; color:#718096; margin-top:3px; padding-left:24px; }
html[data-theme="dark"] .mo-check-hint { color:#a0aec0; }
.mo-note-hint { margin-top:6px; font-size:12px; line-height:1.4; color:#553c9a; background:#faf5ff; border:1px solid #d6bcfa; border-radius:5px; padding:6px 8px; }
.mo-note-hint[hidden] { display:none; }
.mo-note-hint i { margin-right:4px; color:#6b46c1; }
html[data-theme="dark"] .mo-note-hint { color:#d6bcfa; background:rgba(196,181,253,.10); border-color:rgba(196,181,253,.4); }
html[data-theme="dark"] .mo-note-hint i { color:#c4b5fd; }
</style>
<script>
window.orkMonarchyOnlyBadge = function (level) {
    level = parseInt(level, 10) || 0;
    if (level <= 0) return '';
    var tip = level >= 2 ? 'Visible only to kingdom officers' : 'Visible to park and kingdom officers';
    return '<span class="mo-badge" data-tip="' + tip + '"><i class="fas fa-eye-slash" aria-hidden="true"></i> Monarchy Only</span>';
};
// Grant-from-rec modals: a Monarchy Only rec's reason is never copied into the
// public award note. Shows (or hides) a short explanation right under the note box.
window.orkMonarchyOnlyNoteHint = function (noteEl, show) {
    if (!noteEl || !noteEl.parentNode) return;
    var id = (noteEl.id || 'mo-note') + '-mo-hint';
    var hint = document.getElementById(id);
    if (!show) { if (hint) hint.hidden = true; return; }
    if (!hint) {
        hint = document.createElement('div');
        hint.id = id;
        hint.className = 'mo-note-hint';
        hint.innerHTML = '<i class="fas fa-eye-slash" aria-hidden="true"></i>This recommendation is Monarchy Only \u2014 its reason is not copied into the public award note.';
        noteEl.parentNode.insertBefore(hint, noteEl.nextSibling);
    }
    hint.hidden = false;
};
</script>
