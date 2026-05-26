/* OrkPlayerSearch — the one playersearch component. Custom dropdown, never jQuery UI.
   Usage:
     OrkPlayerSearch.attach(inputEl, {
       parkId, kingdomId, restrictTo, includeInactive, includeSuspended, limit,
       onSelect: function(player){...},   // player = normalized row from SearchAjax/players
       uir: window.UIR                    // optional; defaults to global UIR
     });
*/
window.OrkPlayerSearch = (function () {
  var DEBOUNCE = 220, MINLEN = 2;
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

  function attach(input, opts) {
    opts = opts || {};
    if (!input || input._opsAttached) return;
    input._opsAttached = true;
    var uir = opts.uir || window.UIR || '';
    var dd = document.createElement('div');
    dd.className = 'ops-ac-results';
    document.body.appendChild(dd);
    var timer, items = [], active = -1;

    function close(){ dd.classList.remove('ops-ac-open'); active = -1; }
    function position(){
      var r = input.getBoundingClientRect();
      dd.style.position = 'fixed';
      dd.style.left = r.left + 'px';
      dd.style.top  = (r.bottom + 2) + 'px';
      dd.style.width = r.width + 'px';
    }
    function render(data){
      items = data || [];
      if (!items.length){ dd.innerHTML = '<div class="ops-ac-empty">No players found</div>'; }
      else {
        dd.innerHTML = items.map(function(p, i){
          var loc = (p.KAbbr||'') + (p.PAbbr ? ':'+p.PAbbr : '');
          return '<div class="ops-ac-item" data-i="'+i+'" tabindex="-1">'
            + esc(p.Persona)
            + (loc ? ' <span class="ops-ac-loc">('+esc(loc)+')</span>' : '')
            + (p.Active===0    ? ' <span class="ops-ac-badge">Inactive</span>' : '')
            + (p.Suspended     ? ' <span class="ops-ac-badge ops-ac-banned">Banned</span>' : '')
            + '</div>';
        }).join('');
      }
      position(); dd.classList.add('ops-ac-open'); active = -1;
    }
    function pick(i){
      var p = items[i]; if (!p) return;
      input.value = p.Persona; close();
      if (opts.onSelect) opts.onSelect(p);
    }
    function search(term){
      var url = uir + 'SearchAjax/players'
        + '&parkId='    + (opts.parkId    || 0)
        + '&kingdomId=' + (opts.kingdomId || 0)
        + (opts.restrictTo ? '&restrictTo=' + encodeURIComponent(opts.restrictTo) : '')
        + (opts.includeInactive  ? '&include_inactive=1'  : '')
        + (opts.includeSuspended ? '&include_suspended=1' : '')
        + '&limit=' + (opts.limit || 15)
        + '&q=' + encodeURIComponent(term);
      fetch(url).then(function(r){ return r.json(); }).then(render)
        .catch(function(e){ if (e.name!=='AbortError') console.warn('[playersearch]', e); });
    }
    input.addEventListener('input', function(){
      clearTimeout(timer);
      var t = input.value.trim();
      if (t.length < MINLEN){ close(); return; }
      timer = setTimeout(function(){ search(t); }, DEBOUNCE);
    });
    input.addEventListener('keydown', function(e){
      if (!dd.classList.contains('ops-ac-open')) return;
      var n = dd.querySelectorAll('.ops-ac-item').length;
      if (e.key==='ArrowDown'){ active=Math.min(active+1,n-1); paint(); e.preventDefault(); }
      else if (e.key==='ArrowUp'){ active=Math.max(active-1,0); paint(); e.preventDefault(); }
      else if (e.key==='Enter' && active>=0){ pick(active); e.preventDefault(); }
      else if (e.key==='Escape'){ close(); }
    });
    function paint(){
      dd.querySelectorAll('.ops-ac-item').forEach(function(el,i){
        el.classList.toggle('ops-ac-active', i===active);
      });
    }
    dd.addEventListener('mousedown', function(e){
      var it = e.target.closest('.ops-ac-item'); if (!it) return;
      e.preventDefault(); pick(parseInt(it.dataset.i,10));
    });
    document.addEventListener('click', function(e){
      if (e.target!==input && !dd.contains(e.target)) close();
    });
    window.addEventListener('scroll', function(){ if (dd.classList.contains('ops-ac-open')) position(); }, true);
    return { close: close, destroy: function(){ dd.remove(); input._opsAttached=false; } };
  }
  return { attach: attach };
})();
