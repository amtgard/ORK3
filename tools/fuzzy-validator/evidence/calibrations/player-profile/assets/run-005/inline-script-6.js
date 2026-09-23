
(function() {
    var overlay = document.getElementById('ork-ep-overlay');
    var pick    = document.getElementById('ork-ep-datepick');
    var body    = document.getElementById('ork-ep-body');
    if (!overlay || !pick || !body) return;

    // Stash the initial (server-rendered "today") body + date so we can
    // reset both when the popover reopens — otherwise a previously-picked
    // date sticks around and looks stale.
    var initialBody = body.innerHTML;
    var initialDate = pick.value;
    window.__orkEpResetToToday = function() {
        body.innerHTML = initialBody;
        pick.value     = initialDate;
    };

    // English ordinal — mirrors EraPhoenice::ordinal() so client-side
    // re-renders (from the date picker) match the server-side text.
    function ord(n) {
        var abs = Math.abs(n) % 100;
        if (abs >= 11 && abs <= 13) return n + 'th';
        switch (n % 10) {
            case 1:  return n + 'st';
            case 2:  return n + 'nd';
            case 3:  return n + 'rd';
            default: return n + 'th';
        }
    }
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function render(payload) {
        if (!payload || !payload.ok) {
            body.innerHTML = '<div style="color:#c53030">Could not load E.P. data.</div>';
            return;
        }
        var html = '';
        html += '<div style="font-size:26px;font-weight:700;line-height:1.15;margin:6px 0 4px;color:var(--ork-text,#1a202c);">'
              + esc(payload.ep.formatted) + '</div>';
        html += '<div style="font-style:italic;color:var(--ork-text-muted,#718096);margin-bottom:18px;">('
              + esc(payload.imperium.formatted) + ')</div>';
        if (payload.holiday) {
            html += '<div style="line-height:1.55;margin-bottom:6px;">'
                  + '<i class="fas fa-star" style="color:#d69e2e;margin-right:4px"></i>'
                  + 'That day is <strong>' + esc(payload.holiday) + '</strong>.</div>';
        }
        if (payload.last_holiday) {
            html += '<div style="line-height:1.55;">The last Amtgard holiday was <strong>'
                  + esc(payload.last_holiday.name) + '</strong>, on '
                  + esc(payload.last_holiday.civil) + '.</div>';
        }
        if (payload.next_holiday) {
            html += '<div style="line-height:1.55;margin-top:4px;">The next Amtgard holiday is <strong>'
                  + esc(payload.next_holiday.name) + '</strong>, on '
                  + esc(payload.next_holiday.civil) + '.</div>';
        }
        body.innerHTML = html;
    }

    pick.addEventListener('change', function() {
        var v = pick.value;
        if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return;
        body.innerHTML = '<div style="color:var(--ork-text-muted,#718096);padding:24px 0;">Loading&hellip;</div>';
        fetch('http://localhost:19080/orkui/index.php?Route=EraPhoenice/date/' + v, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(render)
            .catch(function() {
                body.innerHTML = '<div style="color:#c53030">Could not reach the E.P. service.</div>';
            });
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && overlay.style.display === 'flex') orkCloseEpPopover();
    });
})();
function orkOpenEpPopover() {
    var el = document.getElementById('ork-ep-overlay');
    if (!el) return;
    if (typeof window.__orkEpResetToToday === 'function') window.__orkEpResetToToday();
    el.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function orkCloseEpPopover() {
    var el = document.getElementById('ork-ep-overlay');
    if (el) { el.style.display = 'none'; document.body.style.overflow = ''; }
}
