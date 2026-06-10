/* OrkPlayerSearch — the one playersearch component. Custom dropdown, never jQuery UI.

   Every surface passes its CONTEXT; the server (SearchAjax/players → SearchService::RankedPlayers)
   ranks/scopes/gates accordingly. The component never decides policy — it just forwards context.

   Usage:
     OrkPlayerSearch.attach(inputEl, {
       // --- ring centre (proximity ranking: park 0 → kingdom 1 → elsewhere 2) ---
       parkId, kingdomId,
       // --- hard scope (the surface's operational constraint) ---
       restrictTo,            // 'park' | 'kingdom' — limit active/inactive results to the centre
       restrictKingdomIds,    // [ids] — limit to a kingdom family/principality set
       excludeKingdomId,      // omit members of this kingdom  (e.g. "Move INTO kingdom")
       excludeParkId,         // omit members of this park     (e.g. "Move INTO park")
       excludeIds,            // [ids] | function(){...}  — omit specific players (already-added / other merge field)
       bannedScope,           // 'kingdom' | 'all' — override how wide banned players surface (server still auth-gates)
       limit,                 // page size (default 15); "Load more…" pages through the rest
       // --- callbacks ---
       onSelect: function(player){...},   // player = normalized row from SearchAjax/players
       onClear:  function(){...},         // fired on any edit — clear any stored selection (hidden MundaneId)
       uir: window.UIR,                   // optional; defaults to global UIR
       preload: [{MundaneId,Persona,...}] // shown on focus when input is empty
       // includeInactive / includeSuspended are accepted but IGNORED — inactive is now always a
       // last-resort tier and banned visibility is decided server-side by the viewer's authority.
     });
   Returns: { close, destroy, setOpts }
   OrkPlayerSearch.reattach(inputEl, newOpts) — setOpts on existing or attach fresh.
*/
window.OrkPlayerSearch = (function () {
  var DEBOUNCE = 220, MINLEN = 2, uidSeq = 0;
  var instances = [];   // module-level registry → ONE set of global listeners, no per-attach leak
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function csv(v){
    var a = (typeof v === 'function') ? (v() || []) : (v || []);
    if (!a.length) return '';
    return a.filter(function(x){ return x != null && x !== ''; }).join(',');
  }

  // Shared global listeners (registered once for the whole module, not per attach()).
  document.addEventListener('click', function(e){
    for (var i = 0; i < instances.length; i++){
      var inst = instances[i];
      if (e.target !== inst.input && !inst.dd.contains(e.target)) inst.close();
    }
  });
  function repositionOpen(){
    for (var i = 0; i < instances.length; i++){
      var inst = instances[i];
      if (inst.dd.classList.contains('ops-ac-open')) inst.position();
    }
  }
  window.addEventListener('scroll', repositionOpen, true);
  window.addEventListener('resize', repositionOpen);
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', repositionOpen);
    window.visualViewport.addEventListener('scroll', repositionOpen);
  }

  function attach(input, opts) {
    opts = opts || {};
    if (!input || input._opsAttached) {
      if (input && input._opsHandle && opts) input._opsHandle.setOpts(opts);
      return input && input._opsHandle;
    }
    input._opsAttached = true;

    var ddId = 'ops-ac-' + (++uidSeq);
    var dd = document.createElement('div');
    dd.className = 'ops-ac-results';
    dd.id = ddId;
    dd.setAttribute('role', 'listbox');
    document.body.appendChild(dd);

    // Screen-reader status line (result counts / states) — visually hidden, polite live region.
    var live = document.createElement('div');
    live.className = 'ops-ac-sr';
    live.setAttribute('aria-live', 'polite');
    document.body.appendChild(live);

    // ARIA combobox wiring on the input.
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', ddId);
    input.setAttribute('autocomplete', 'off');

    var timer = null, ctrl = null;
    var items = [];          // accumulated rows (grows with "Load more")
    var active = -1;         // highlighted index; items.length === the Load-more row when hasMore
    var hasMore = false;
    var lastTerm = '';
    var loading = false;

    function abortInflight(){ if (ctrl) { ctrl.abort(); ctrl = null; } }
    function close(){
      dd.classList.remove('ops-ac-open');
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      active = -1;
      clearTimeout(timer);
      abortInflight();
    }
    function position(){
      var r = input.getBoundingClientRect();
      dd.style.position = 'fixed';
      dd.style.left = r.left + 'px';
      dd.style.width = r.width + 'px';
      // Flip up / clamp height when there is not enough room below.
      var spaceBelow = window.innerHeight - r.bottom - 8;
      var spaceAbove = r.top - 8;
      dd.style.maxHeight = '';
      if (spaceBelow < 180 && spaceAbove > spaceBelow) {
        var h = Math.min(dd.scrollHeight, spaceAbove);
        dd.style.maxHeight = Math.min(spaceAbove, 280) + 'px';
        dd.style.top = (r.top - Math.min(dd.offsetHeight || h, spaceAbove) - 2) + 'px';
      } else {
        dd.style.maxHeight = Math.min(Math.max(spaceBelow, 120), 280) + 'px';
        dd.style.top = (r.bottom + 2) + 'px';
      }
    }
    function badges(p){
      if (p.PenaltyBox) return ' <span class="ops-ac-badge ops-ac-banned">Banned</span>';
      if (p.Suspended)  return ' <span class="ops-ac-badge ops-ac-suspended">Suspended</span>';
      if (p.Active === 0) return ' <span class="ops-ac-badge ops-ac-inactive">Inactive</span>';
      return '';
    }
    function rowHtml(p, i){
      var loc = (p.KAbbr||'') + (p.PAbbr ? ':'+p.PAbbr : '');
      var cls = 'ops-ac-item'
        + (p.Banned || p.PenaltyBox || p.Suspended ? ' ops-ac-row-banned' : '')
        + (p.Active === 0 && !p.Banned && !p.PenaltyBox && !p.Suspended ? ' ops-ac-row-inactive' : '');
      return '<div class="'+cls+'" id="'+ddId+'-opt-'+i+'" role="option" data-i="'+i+'" tabindex="-1">'
        + esc(p.Persona)
        + (loc ? ' <span class="ops-ac-loc">('+esc(loc)+')</span>' : '')
        + badges(p)
        + '</div>';
    }
    function advUrl(){
      // Carry the current term + surface scope into the Advanced Search page (opens in a new tab).
      // UIR ends in "?Route=" so params join with & (per the &q= rule). Build from live opts.
      var uir = opts.uir || window.UIR || '';
      return uir + 'Search/advanced&q=' + encodeURIComponent(lastTerm || input.value.trim())
        + (opts.parkId    ? '&parkId='    + encodeURIComponent(opts.parkId)    : '')
        + (opts.kingdomId ? '&kingdomId=' + encodeURIComponent(opts.kingdomId) : '');
    }
    function render(){
      var html = '';
      if (!items.length){
        html = '<div class="ops-ac-empty">No players found</div>';
      } else {
        html = items.map(rowHtml).join('');
        // Footer: when more results exist, split the row — "Load more…" (left) and a real
        // "…or Advanced Search" link (right) that opens the rich search page in a new tab.
        // With no more results, just offer the Advanced Search escalation full-width.
        if (hasMore) {
          html += '<div class="ops-ac-foot">'
            + '<span class="ops-ac-more" data-i="'+items.length+'" role="option" id="'+ddId+'-more" tabindex="-1">Load more…</span>'
            + '<a class="ops-ac-adv" href="'+esc(advUrl())+'" target="_blank" rel="noopener" tabindex="-1">…or Advanced Search</a>'
            + '</div>';
        } else {
          html += '<div class="ops-ac-foot">'
            + '<a class="ops-ac-adv ops-ac-adv-full" href="'+esc(advUrl())+'" target="_blank" rel="noopener" tabindex="-1">Advanced Search</a>'
            + '</div>';
        }
      }
      dd.innerHTML = html;
      position();
      dd.classList.add('ops-ac-open');
      input.setAttribute('aria-expanded', 'true');
      paint();
      live.textContent = items.length
        ? (items.length + ' player' + (items.length===1?'':'s') + (hasMore ? ', more available' : ''))
        : 'No players found';
    }
    function showState(cls, text){
      items = []; hasMore = false; active = -1;
      dd.innerHTML = '<div class="'+cls+'">'+esc(text)+'</div>';
      position(); dd.classList.add('ops-ac-open');
      input.setAttribute('aria-expanded', 'true');
      input.removeAttribute('aria-activedescendant');
      if (cls !== 'ops-ac-loading') live.textContent = text;
    }
    function paint(){
      dd.querySelectorAll('[role=option]').forEach(function(el,i){
        var on = (i===active);
        el.classList.toggle('ops-ac-active', on);
        if (on) {
          input.setAttribute('aria-activedescendant', el.id);
          el.scrollIntoView({ block:'nearest' });
        }
      });
      if (active < 0) input.removeAttribute('aria-activedescendant');
    }
    function pick(i){
      if (hasMore && i === items.length){ loadMore(); return; }
      var p = items[i]; if (!p) return;
      input.value = p.Persona; close();
      if (opts.onSelect) opts.onSelect(p);
    }
    function buildUrl(term, offset){
      var uir = opts.uir || window.UIR || '';
      var ex = csv(opts.excludeIds);
      var rk = csv(opts.restrictKingdomIds);
      return uir + 'SearchAjax/players'
        + '&parkId='    + (opts.parkId    || 0)
        + '&kingdomId=' + (opts.kingdomId || 0)
        + (opts.restrictTo       ? '&restrictTo='       + encodeURIComponent(opts.restrictTo)       : '')
        + (rk                    ? '&restrictKingdomIds='+ encodeURIComponent(rk)                    : '')
        + (opts.excludeKingdomId ? '&excludeKingdomId=' + encodeURIComponent(opts.excludeKingdomId) : '')
        + (opts.excludeParkId    ? '&excludeParkId='    + encodeURIComponent(opts.excludeParkId)    : '')
        + (ex                    ? '&excludeIds='       + encodeURIComponent(ex)                    : '')
        + (opts.bannedScope      ? '&bannedScope='      + encodeURIComponent(opts.bannedScope)      : '')
        + '&limit='  + (opts.limit || 15)
        + '&offset=' + (offset || 0)
        + '&q=' + encodeURIComponent(term);
    }
    function fetchPage(term, offset, append){
      abortInflight();
      var hasAC = (typeof AbortController !== 'undefined');
      ctrl = hasAC ? new AbortController() : null;
      loading = true;
      if (!append) showState('ops-ac-loading', 'Searching…');
      fetch(buildUrl(term, offset), ctrl ? { signal: ctrl.signal } : undefined)
        .then(function(r){
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(function(data){
          loading = false; ctrl = null;
          // Tolerate both the envelope {rows,hasMore} and a bare array (defensive).
          var rows = Array.isArray(data) ? data : (data && data.rows) || [];
          hasMore  = Array.isArray(data) ? false : !!(data && data.hasMore);
          if (append) items = items.concat(rows); else items = rows;
          render();
        })
        .catch(function(e){
          if (e && e.name === 'AbortError') return;
          loading = false; ctrl = null;
          console.warn('[playersearch]', e);
          if (!append) showState('ops-ac-error', 'Search unavailable — try again');
        });
    }
    function search(term){ lastTerm = term; fetchPage(term, 0, false); }
    function loadMore(){ if (!hasMore || loading) return; fetchPage(lastTerm, items.length, true); }

    var inst = { input: input, dd: dd, close: close, position: position };
    instances.push(inst);

    function onInput(){
      clearTimeout(timer);
      if (opts.onClear) opts.onClear();   // any edit invalidates a prior selection
      var t = input.value.trim();
      if (t.length === 0){ close(); return; }
      if (t.length < MINLEN){ showState('ops-ac-hint', 'Type at least ' + MINLEN + ' characters'); return; }
      timer = setTimeout(function(){ search(t); }, DEBOUNCE);
    }
    function onKeydown(e){
      var open = dd.classList.contains('ops-ac-open');
      if (e.key==='ArrowDown'){
        if (!open && input.value.trim().length >= MINLEN){ search(input.value.trim()); e.preventDefault(); return; }
        var n = dd.querySelectorAll('[role=option]').length;
        active = Math.min(active+1, n-1); paint(); e.preventDefault();
      } else if (e.key==='ArrowUp'){
        if (active>0){ active--; paint(); } e.preventDefault();
      } else if (e.key==='Home'){
        if (open){ active = 0; paint(); e.preventDefault(); }
      } else if (e.key==='End'){
        if (open){ active = dd.querySelectorAll('[role=option]').length - 1; paint(); e.preventDefault(); }
      } else if (e.key==='Enter'){
        if (open && active>=0){ pick(active); e.preventDefault(); }
        else if (open){ close(); }   // open with no highlight: dismiss, don't submit through it
      } else if (e.key==='Escape'){
        if (open){ close(); e.stopPropagation(); e.preventDefault(); }  // don't also close the modal
      }
    }
    function onDdMousedown(e){
      var it = e.target.closest('[role=option]'); if (!it) return;
      e.preventDefault(); pick(parseInt(it.dataset.i,10));
    }
    function onFocus(){
      if (input.value.trim() === '' && opts.preload && opts.preload.length) {
        items = opts.preload.slice(); hasMore = false; active = -1; render();
      }
    }
    function onBlur(){
      // mousedown+preventDefault on items keeps focus during selection, so blur = genuine departure.
      setTimeout(function(){ if (document.activeElement !== input) close(); }, 0);
    }

    input.addEventListener('input', onInput);
    input.addEventListener('keydown', onKeydown);
    input.addEventListener('focus', onFocus);
    input.addEventListener('blur', onBlur);
    dd.addEventListener('mousedown', onDdMousedown);

    function setOpts(newOpts) { for (var k in newOpts) { opts[k] = newOpts[k]; } }

    var handle = {
      close: close,
      // Re-run the current query with the latest opts (call after setOpts/reattach changes scope,
      // so a scope/mode toggle refreshes results without the user having to retype). Deferred to the
      // next tick so the same click that toggled scope (which bubbles to the document close-listener
      // and would abort the fetch) settles first.
      refresh: function(){
        setTimeout(function(){
          var t = input.value.trim();
          if (t.length >= MINLEN) { items = []; active = -1; search(t); } else { close(); }
        }, 0);
      },
      destroy: function(){
        close();
        input.removeEventListener('input', onInput);
        input.removeEventListener('keydown', onKeydown);
        input.removeEventListener('focus', onFocus);
        input.removeEventListener('blur', onBlur);
        dd.removeEventListener('mousedown', onDdMousedown);
        ['role','aria-autocomplete','aria-expanded','aria-controls','aria-activedescendant'].forEach(function(a){ input.removeAttribute(a); });
        dd.remove(); live.remove();
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
