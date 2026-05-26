/* OrkPlayerSearch — the one playersearch component. Custom dropdown, never jQuery UI.
   Usage:
     OrkPlayerSearch.attach(inputEl, {
       parkId, kingdomId, restrictTo, includeInactive, includeSuspended, limit,
       excludeKingdomId, excludeParkId,
       onSelect: function(player){...},   // player = normalized row from SearchAjax/players
       onClear:  function(){...},         // fired whenever the input is edited — clear any stored
                                          // selection (e.g. the hidden MundaneId) so a stale id
                                          // can't be submitted after the user edits the text
       uir: window.UIR,                   // optional; defaults to global UIR
       excludeIds: [] | function(){...},  // MundaneIds to hide (array or fn evaluated per search)
       preload: [{MundaneId,Persona,...}] // shown on focus when input is empty
     });
   Returns: { close, destroy, setOpts }
   OrkPlayerSearch.reattach(inputEl, newOpts) — setOpts on existing or attach fresh.
*/
window.OrkPlayerSearch = (function () {
  var DEBOUNCE = 220, MINLEN = 2;
  var instances = [];   // module-level registry → ONE set of global listeners, no per-attach leak
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

  // Shared global listeners (registered once for the whole module, not per attach()).
  document.addEventListener('click', function(e){
    for (var i = 0; i < instances.length; i++){
      var inst = instances[i];
      if (e.target !== inst.input && !inst.dd.contains(e.target)) inst.close();
    }
  });
  window.addEventListener('scroll', function(){
    for (var i = 0; i < instances.length; i++){
      var inst = instances[i];
      if (inst.dd.classList.contains('ops-ac-open')) inst.position();
    }
  }, true);

  function attach(input, opts) {
    opts = opts || {};
    if (!input || input._opsAttached) return input._opsHandle;
    input._opsAttached = true;
    var dd = document.createElement('div');
    dd.className = 'ops-ac-results';
    document.body.appendChild(dd);
    var timer, items = [], active = -1, ctrl = null;

    function close(){ dd.classList.remove('ops-ac-open'); active = -1; }
    function position(){
      var r = input.getBoundingClientRect();
      dd.style.position = 'fixed';
      dd.style.left = r.left + 'px';
      dd.style.top  = (r.bottom + 2) + 'px';
      dd.style.width = r.width + 'px';
    }
    function render(data){
      var ex = typeof opts.excludeIds === 'function'
        ? (opts.excludeIds() || [])
        : (opts.excludeIds || []);
      if (ex.length) {
        data = (data || []).filter(function(p){ return ex.indexOf(p.MundaneId) === -1; });
      }
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
    function paint(){
      dd.querySelectorAll('.ops-ac-item').forEach(function(el,i){
        el.classList.toggle('ops-ac-active', i===active);
      });
    }
    function pick(i){
      var p = items[i]; if (!p) return;
      input.value = p.Persona; close();
      if (opts.onSelect) opts.onSelect(p);
    }
    function search(term){
      var uir = opts.uir || window.UIR || '';   // read live from opts (supports reattach)
      var url = uir + 'SearchAjax/players'
        + '&parkId='    + (opts.parkId    || 0)
        + '&kingdomId=' + (opts.kingdomId || 0)
        + (opts.restrictTo       ? '&restrictTo='       + encodeURIComponent(opts.restrictTo)       : '')
        + (opts.includeInactive  ? '&include_inactive=1'  : '')
        + (opts.includeSuspended ? '&include_suspended=1' : '')
        + (opts.excludeKingdomId ? '&excludeKingdomId=' + encodeURIComponent(opts.excludeKingdomId) : '')
        + (opts.excludeParkId    ? '&excludeParkId='    + encodeURIComponent(opts.excludeParkId)    : '')
        + '&limit=' + (opts.limit || 15)
        + '&q=' + encodeURIComponent(term);
      // Abort any in-flight request so a slow earlier response can't overwrite a newer one.
      if (ctrl) ctrl.abort();
      var hasAC = (typeof AbortController !== 'undefined');
      ctrl = hasAC ? new AbortController() : null;
      fetch(url, ctrl ? { signal: ctrl.signal } : undefined)
        .then(function(r){ return r.json(); }).then(render)
        .catch(function(e){ if (!e || e.name !== 'AbortError') console.warn('[playersearch]', e); });
    }

    var inst = { input: input, dd: dd, close: close, position: position };
    instances.push(inst);

    input.addEventListener('input', function(){
      clearTimeout(timer);
      if (opts.onClear) opts.onClear();   // any edit invalidates a prior selection
      var t = input.value.trim();
      if (t.length < MINLEN){ close(); return; }
      timer = setTimeout(function(){ search(t); }, DEBOUNCE);
    });
    input.addEventListener('keydown', function(e){
      if (!dd.classList.contains('ops-ac-open')) return;
      var n = dd.querySelectorAll('.ops-ac-item').length;
      if (e.key==='ArrowDown'){ active=Math.min(active+1,n-1); paint(); e.preventDefault(); }
      else if (e.key==='ArrowUp'){ if (active>0){ active--; paint(); } e.preventDefault(); }
      else if (e.key==='Enter' && active>=0){ pick(active); e.preventDefault(); }
      else if (e.key==='Escape'){ close(); }
    });
    dd.addEventListener('mousedown', function(e){
      var it = e.target.closest('.ops-ac-item'); if (!it) return;
      e.preventDefault(); pick(parseInt(it.dataset.i,10));
    });
    input.addEventListener('focus', function(){
      if (input.value.trim() === '' && opts.preload && opts.preload.length) {
        render(opts.preload.slice());
      }
    });

    function setOpts(newOpts) { for (var k in newOpts) { opts[k] = newOpts[k]; } }

    var handle = {
      close: close,
      destroy: function(){
        if (ctrl) ctrl.abort();
        dd.remove();
        var idx = instances.indexOf(inst); if (idx >= 0) instances.splice(idx, 1);
        input._opsAttached = false; delete input._opsHandle;
      },
      setOpts: setOpts
    };
    input._opsHandle = handle;
    return handle;
  }

  function reattach(input, newOpts) {
    if (input && input._opsHandle) {
      input._opsHandle.setOpts(newOpts || {});
    } else {
      attach(input, newOpts);
    }
  }

  return { attach: attach, reattach: reattach };
})();
