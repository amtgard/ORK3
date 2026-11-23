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
       preload: [{MundaneId,Persona,...}], // shown on focus when input is empty
       filterPlacement: 'anchored', // filter UI as a popover under the field (default: centred modal)
       filter: false,         // opt out of the kingdom/park filter icon + pill (default on; never
                              // shown for restrictTo:'park' or when the scope has no kingdoms)
       // includeInactive / includeSuspended are accepted but IGNORED — inactive is now always a
       // last-resort tier and banned visibility is decided server-side by the viewer's authority.
     });
   Returns: { close, destroy, setOpts }
   OrkPlayerSearch.reattach(inputEl, newOpts) — setOpts on existing or attach fresh (a new scope
     re-validates the filter pill and clears it when it no longer resolves).
   OrkPlayerSearch.filterField(inputEl, {uir, scope, onChange(filter|null, info), placement})
     → {get, set, setScope, destroy} — the filter UI alone, for searches that render their own
     results (header Universal Search). scope uses the attach() scope keys.
*/
window.OrkPlayerSearch = (function () {
  var DEBOUNCE = 220, MINLEN = 2, uidSeq = 0;
  var EXCLUDE_CAP = 200;  // max excludeIds sent server-side; the full set is filtered client-side
  var instances = [];   // module-level registry → ONE set of global listeners, no per-attach leak
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function list(v){
    var a = (typeof v === 'function') ? (v() || []) : (v || []);
    return a.filter(function(x){ return x != null && x !== ''; });
  }
  function csv(v){ return list(v).join(','); }

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

  /* ================= Scope filter: icon + pill + kingdom/park modal =================
     One implementation for every player search. attach() wires it in unless opts.filter===false;
     the header Universal Search uses OrkPlayerSearch.filterField() directly.
     filter = {kingdomId, kingdomName, kingdomAbbr, parkId, parkName, parkAbbr} (parkId 0 = whole kingdom).
     The server ANDs it with the field's hard scope, so it can only ever narrow. */
  // "KCG:AR ", "KCG: ", "KCG:* " at the very start, closed by whitespace. A bare "KCG " is NOT
  // converted, so ordinary short names ("Al ") are never eaten.
  var FILTER_PREFIX_RE = /^([A-Za-z0-9]{2,3}):([A-Za-z0-9]{2,3}|\*)?\s/;
  var filterFields = [];      // registry → one set of layout listeners for all fields
  var kingdomCache = {};      // scope key → Promise<[{id,name,abbr}]>, page lifetime
  var parkCache = {};         // scope key + kingdom → Promise<[{id,name,abbr,kingdomId?,kingdomAbbr?}]>

  function scopeFrom(o){
    o = o || {};
    return { kingdomId: o.kingdomId || 0, parkId: o.parkId || 0, restrictTo: o.restrictTo || '',
      restrictKingdomIds: list(o.restrictKingdomIds), excludeKingdomId: o.excludeKingdomId || 0,
      excludeParkId: o.excludeParkId || 0 };
  }
  function scopeQS(s){
    var rk = csv(s.restrictKingdomIds);
    return '&kingdomId=' + (parseInt(s.kingdomId, 10) || 0) + '&parkId=' + (parseInt(s.parkId, 10) || 0)
      + (s.restrictTo       ? '&restrictTo='         + encodeURIComponent(s.restrictTo) : '')
      + (rk                 ? '&restrictKingdomIds=' + encodeURIComponent(rk)           : '')
      + (s.excludeKingdomId ? '&excludeKingdomId='   + (parseInt(s.excludeKingdomId, 10) || 0) : '')
      + (s.excludeParkId    ? '&excludeParkId='      + (parseInt(s.excludeParkId, 10) || 0)    : '');
  }
  function getJSON(url, signal){
    return fetch(url, signal ? { signal: signal, credentials: 'same-origin' } : { credentials: 'same-origin' })
      .then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
  }
  function cached(store, key, url, pick){
    if (!store[key]) {
      store[key] = getJSON(url).then(function(d){ return (d && d[pick]) || []; });
      store[key].catch(function(){ delete store[key]; });   // a failed load may be retried
    }
    return store[key];
  }
  function loadKingdoms(uir, scope){
    var k = scopeQS(scope);
    return cached(kingdomCache, k, uir + 'SearchAjax/filteroptions' + k, 'kingdoms');
  }
  function loadParks(uir, scope, kid){
    var k = scopeQS(scope) + '&forKingdom=' + (parseInt(kid, 10) || 0);
    return cached(parkCache, k, uir + 'SearchAjax/filteroptions' + k, 'parks');
  }
  function filterLabel(f){
    var k = f.kingdomAbbr || f.kingdomName || '';
    return f.parkId ? k + ':' + (f.parkAbbr || f.parkName || '') : k;
  }
  function filterTip(f){
    return 'Filtering to ' + (f.kingdomName || f.kingdomAbbr || '')
      + (f.parkId ? ' - ' + (f.parkName || f.parkAbbr || '') : '');
  }
  function normFilter(m){
    if (!m || !(parseInt(m.kingdomId, 10) > 0)) return null;
    return { kingdomId: parseInt(m.kingdomId, 10), kingdomName: m.kingdomName || '', kingdomAbbr: m.kingdomAbbr || '',
      parkId: parseInt(m.parkId, 10) || 0, parkName: m.parkId ? (m.parkName || '') : '',
      parkAbbr: m.parkId ? (m.parkAbbr || '') : '' };
  }

  // Layout sync. Each overlay hangs off a zero-size in-flow anchor placed right after its input,
  // so it moves with the input on its own; syncs only re-measure SIZE (and the anchor→input
  // offset). Triggers: ResizeObserver on each input, window resize, transitions on an input or its
  // ancestors (the header search fades visibility without resizing), and — only for the rare
  // out-of-flow fallback anchor (grid / space-* flex parents) — scroll.
  var syncQueued = false, syncAbsOnly = true;
  function queueSync(absOnly, reprobe){
    if (!absOnly) syncAbsOnly = false;
    // Per field: a hidden/collapsed field keeps the flag until it is next shown and re-measured.
    if (reprobe) filterFields.forEach(function(f){ f.needsReprobe = true; });
    if (syncQueued) return;
    syncQueued = true;
    (window.requestAnimationFrame || setTimeout)(function(){
      var only = syncAbsOnly;
      syncQueued = false; syncAbsOnly = true;
      filterFields.forEach(function(f){ if (!only || f.absAnchor()) f.sync(); });
    });
  }
  var ro = (typeof ResizeObserver !== 'undefined') ? new ResizeObserver(function(){ queueSync(false); }) : null;
  window.addEventListener('resize', function(){ if (filterFields.length) queueSync(false, true); });
  window.addEventListener('scroll', function(){ if (filterFields.length) queueSync(true); }, true);
  document.addEventListener('transitionend', function(e){
    var t = e.target;
    if (!t || !t.contains) return;
    filterFields.forEach(function(f){ if (t.contains(f.input)) f.sync(); });
  }, true);
  function whenIdle(fn){
    if (window.requestIdleCallback) window.requestIdleCallback(fn, { timeout: 1500 });
    else setTimeout(fn, 200);
  }

  // ---- The one shared modal (built lazily, appended to body) ----
  var modal = null, modalField = null, modalSeq = 0;
  function buildModal(){
    var root = document.createElement('div');
    root.className = 'ops-filter-modal';
    root.hidden = true;
    root.innerHTML =
        '<div class="ops-filter-backdrop"></div>'
      + '<div class="ops-filter-dialog" role="dialog" aria-modal="true" aria-labelledby="ops-filter-title">'
      +   '<div class="ops-filter-title" id="ops-filter-title"><i class="fas fa-filter" aria-hidden="true"></i> Filter by kingdom or park</div>'
      +   '<label class="ops-filter-lbl" for="ops-filter-k">Kingdom</label>'
      +   '<select class="ops-filter-sel" id="ops-filter-k"><option value="">All kingdoms</option></select>'
      +   '<label class="ops-filter-lbl" for="ops-filter-p">Park</label>'
      +   '<select class="ops-filter-sel" id="ops-filter-p" disabled><option value="">All parks</option></select>'
      +   '<div class="ops-filter-msg" aria-live="polite"></div>'
      +   '<div class="ops-filter-foot">'
      +     '<button type="button" class="ops-filter-b ops-filter-b-clear">Clear</button>'
      +     '<span class="ops-filter-spacer"></span>'
      +     '<button type="button" class="ops-filter-b ops-filter-b-cancel">Cancel</button>'
      +     '<button type="button" class="ops-filter-b ops-filter-b-apply">Apply</button>'
      +   '</div>'
      + '</div>';
    document.body.appendChild(root);
    var m = { root: root, dialog: root.querySelector('.ops-filter-dialog'),
      kSel: root.querySelector('#ops-filter-k'), pSel: root.querySelector('#ops-filter-p'),
      msg: root.querySelector('.ops-filter-msg'), apply: root.querySelector('.ops-filter-b-apply'),
      kingdoms: [], parks: [], kLoaded: false, pLoaded: false };
    // Keep the modal's events from reaching host-page "outside click"/Escape handlers
    // (host modals, the header search collapse, the dropdown close listener).
    ['click', 'mousedown', 'pointerdown', 'touchstart', 'keydown', 'focusin'].forEach(function(ev){
      root.addEventListener(ev, function(e){ e.stopPropagation(); });
    });
    root.querySelector('.ops-filter-backdrop').addEventListener('click', function(){ closeModal(); });
    root.querySelector('.ops-filter-b-cancel').addEventListener('click', function(){ closeModal(); });
    root.querySelector('.ops-filter-b-clear').addEventListener('click', function(){
      var f = modalField; closeModal(); if (f) f.apply(null);
    });
    root.querySelector('.ops-filter-b-apply').addEventListener('click', function(){
      if (!applyReady()) return;   // a list it needs is still loading (or failed): never downgrade the pill
      var f = modalField, kid = parseInt(m.kSel.value, 10) || 0, pid = parseInt(m.pSel.value, 10) || 0;
      var k = null, p = null, i;
      for (i = 0; i < m.kingdoms.length; i++) if (parseInt(m.kingdoms[i].id, 10) === kid) k = m.kingdoms[i];
      for (i = 0; i < m.parks.length; i++) if (parseInt(m.parks[i].id, 10) === pid) p = m.parks[i];
      closeModal();
      if (!f) return;
      f.apply(k ? { kingdomId: kid, kingdomName: k.name, kingdomAbbr: k.abbr,
        parkId: p ? pid : 0, parkName: p ? p.name : '', parkAbbr: p ? p.abbr : '' } : null);
    });
    m.kSel.addEventListener('change', function(){ fillParks(parseInt(m.kSel.value, 10) || 0, 0); });
    root.addEventListener('keydown', function(e){
      if (e.key === 'Escape') { e.preventDefault(); closeModal(); return; }
      if (e.key !== 'Tab') return;
      // Focus trap: cycle through the dialog's enabled controls.
      var els = Array.prototype.filter.call(m.dialog.querySelectorAll('select, button'),
        function(el){ return !el.disabled; });
      if (!els.length) return;
      var first = els[0], last = els[els.length - 1];
      if (e.shiftKey && document.activeElement === first) { last.focus(); e.preventDefault(); }
      else if (!e.shiftKey && document.activeElement === last) { first.focus(); e.preventDefault(); }
      else if (els.indexOf(document.activeElement) < 0) { first.focus(); e.preventDefault(); }
    });
    return m;
  }
  function setOptions(sel, placeholder, rows, labelFn){
    sel.innerHTML = '';
    var o = document.createElement('option'); o.value = ''; o.textContent = placeholder; sel.appendChild(o);
    rows.forEach(function(r){
      var op = document.createElement('option');
      op.value = String(r.id); op.textContent = labelFn(r);   // textContent: names arrive unescaped
      sel.appendChild(op);
    });
  }
  // Apply needs the kingdom list, and the park list too once a kingdom is chosen.
  function applyReady(){
    var m = modal;
    return !!m && m.kLoaded && (!m.kSel.value || m.pLoaded);
  }
  function updateApply(){ if (modal) modal.apply.disabled = !applyReady(); }
  function fillParks(kid, presetPid){
    var m = modal, f = modalField, seq = ++modalSeq;
    m.parks = []; m.pLoaded = false;
    m.msg.textContent = '';
    setOptions(m.pSel, 'All parks', [], null);
    m.pSel.disabled = true;
    updateApply();
    if (!kid || !f) return;
    loadParks(f.uir(), f.scope(), kid).then(function(parks){
      if (seq !== modalSeq || modalField !== f) return;
      m.parks = parks; m.pLoaded = true;
      // Principality parks follow the kingdom's own; tag them with their principality.
      setOptions(m.pSel, 'All parks', parks, function(p){
        var pk = parseInt(p.kingdomId, 10) || 0;
        return p.name + (pk && pk !== kid && p.kingdomAbbr ? ' (' + p.kingdomAbbr + ')' : '');
      });
      m.pSel.disabled = false;
      if (presetPid) m.pSel.value = String(presetPid);
      if (m.pSel.value !== String(presetPid || '')) m.pSel.value = '';
      updateApply();
      positionModal();
    }).catch(function(){
      if (seq === modalSeq) m.msg.textContent = 'Could not load parks — try again';
    });
  }
  function openModal(field){
    if (!modal) modal = buildModal();
    var m = modal, seq = ++modalSeq, cur = field.get();
    modalField = field;
    m.msg.textContent = 'Loading kingdoms…';
    m.kingdoms = []; m.parks = []; m.kLoaded = false; m.pLoaded = false;
    setOptions(m.kSel, 'All kingdoms', [], null); m.kSel.disabled = true;
    setOptions(m.pSel, 'All parks', [], null);    m.pSel.disabled = true;
    updateApply();
    var anchored = field.placement() === 'anchored';
    m.root.classList.toggle('ops-filter-anchored', anchored);
    m.dialog.style.left = m.dialog.style.top = m.dialog.style.width = '';
    m.root.hidden = false;
    if (anchored) positionModal();
    m.root.querySelector('.ops-filter-b-cancel').focus();
    loadKingdoms(field.uir(), field.scope()).then(function(ks){
      if (seq !== modalSeq || modalField !== field) return;
      if (!ks.length) { closeModal(); field.markEmpty(); return; }
      m.kingdoms = ks; m.kLoaded = true;
      m.msg.textContent = '';
      setOptions(m.kSel, 'All kingdoms', ks, function(k){ return k.name; });
      m.kSel.disabled = false;
      if (cur) m.kSel.value = String(cur.kingdomId);
      if (cur && m.kSel.value === String(cur.kingdomId)) fillParks(cur.kingdomId, cur.parkId);
      else m.kSel.value = '';
      updateApply();
      positionModal();
      m.kSel.focus();
    }).catch(function(){
      if (seq === modalSeq) m.msg.textContent = 'Could not load kingdoms — try again';
    });
  }
  // placement:'anchored' — a popover just below the input (left-aligned, 6px gap), clamped to the
  // viewport and flipped above when there's no room below; ≤480px it goes full width (16px gutters).
  function positionModal(){
    if (!modal || modal.root.hidden || !modalField || modalField.placement() !== 'anchored') return;
    var d = modal.dialog, r = modalField.input.getBoundingClientRect();
    var vw = document.documentElement.clientWidth || window.innerWidth, vh = window.innerHeight;
    var GAP = 6, EDGE = vw <= 480 ? 16 : 8;
    var w = vw <= 480 ? vw - 32 : Math.min(360, vw - EDGE * 2);
    d.style.width = w + 'px';
    var left = vw <= 480 ? 16 : Math.max(EDGE, Math.min(r.left, vw - EDGE - w));
    var h = d.offsetHeight, top = r.bottom + GAP;
    if (top + h > vh - EDGE && r.top - GAP - h >= EDGE) top = r.top - GAP - h;   // flip above
    top = Math.max(EDGE, Math.min(top, vh - EDGE - h));
    d.style.left = left + 'px';
    d.style.top = top + 'px';
  }
  window.addEventListener('scroll', function(){ if (modal && !modal.root.hidden) positionModal(); }, true);
  window.addEventListener('resize', function(){ if (modal && !modal.root.hidden) positionModal(); });
  function closeModal(){
    if (!modal || modal.root.hidden) return;
    var f = modalField;
    modal.root.hidden = true;
    modalField = null; modalSeq++;
    if (f) f.focusInput();
  }

  /* filterField(input, {uir, scope, onChange(filter|null, info)}) → {get, set, setScope, destroy}
     info.textChanged is true when the visible text changed (pill broken back into text / prefix
     absorbed), so a host can drop a stale selection. */
  function filterField(input, cfg){
    if (!input || !input.parentNode) return null;
    if (input._opsFilter) { input._opsFilter.configure(cfg || {}); return input._opsFilter; }
    cfg = cfg || {};
    var scope = scopeFrom(cfg.scope), current = null, emptyScope = false, checkedKey = null;
    var rTimer = null, rCtrl = null, rvSeq = 0, destroyed = false, prefetchQueued = false;

    // Zero-size anchor immediately AFTER the input (tab order: input → pill × → filter icon); the
    // overlay is positioned from it at a measured offset (≈ -input width inline, -height in block).
    var anchor = document.createElement('span');
    anchor.className = 'ops-filter-anchor';
    var root = document.createElement('span');
    root.className = 'ops-filter';
    var pill = document.createElement('span');
    pill.className = 'ops-filter-pill';
    pill.hidden = true;
    var pillTxt = document.createElement('span');
    pillTxt.className = 'ops-filter-pill-label';
    var pillX = document.createElement('button');
    pillX.type = 'button'; pillX.className = 'ops-filter-pill-x';
    pillX.setAttribute('aria-label', 'Remove filter');
    pillX.textContent = '×';
    pill.appendChild(pillTxt); pill.appendChild(pillX);
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'ops-filter-btn';
    btn.setAttribute('aria-label', 'Filter by kingdom or park');
    btn.setAttribute('data-tip', 'Filter by kingdom or park');
    btn.setAttribute('aria-haspopup', 'dialog');
    btn.innerHTML = '<i class="fas fa-filter" aria-hidden="true"></i>';
    root.appendChild(pill); root.appendChild(btn);
    anchor.appendChild(root);
    input.parentNode.insertBefore(anchor, input.nextSibling);

    // How the anchor joins the input's formatting context without moving anything:
    //  inline  – zero-size inline-block (input is inline-level; the default)
    //  block   – zero-height block (input is block-level)
    //  flex    – zero-size flex item, same order, a negative margin cancelling the one gap it adds
    //  abs     – out of flow (grid parents, space-* justification, floated/positioned inputs),
    //            where any in-flow item would shift the layout; re-synced on scroll as well.
    var mode = null;
    function setImp(prop, val){ anchor.style.setProperty(prop, val, 'important'); }
    function placeAnchor(){
      var p = input.parentElement, pcs = p ? getComputedStyle(p) : null, ics = getComputedStyle(input);
      var m = 'inline';
      if (ics.float !== 'none' || ics.position === 'absolute' || ics.position === 'fixed') m = 'abs';
      else if (pcs && /grid/.test(pcs.display)) m = 'abs';
      else if (pcs && /flex/.test(pcs.display)) m = /space-|stretch/.test(pcs.justifyContent) ? 'abs' : 'flex';
      else if (!/^inline/.test(ics.display)) m = 'block';
      anchor.removeAttribute('style');
      ['margin', 'padding', 'border'].forEach(function(k){ setImp(k, '0'); });
      setImp('width', '0'); setImp('height', '0'); setImp('overflow', 'visible');
      setImp('position', m === 'abs' ? 'absolute' : 'relative');
      setImp('display', m === 'inline' ? 'inline-block' : 'block');
      if (m === 'inline') setImp('vertical-align', 'top');
      if (m === 'flex') {
        setImp('flex', 'none'); setImp('align-self', 'flex-start'); setImp('order', ics.order);
        var dir = pcs.flexDirection, col = /column/.test(dir);
        var gap = parseFloat(col ? pcs.rowGap : pcs.columnGap) || 0;
        // The anchor follows the input, so the gap it adds sits on its leading side.
        if (gap) setImp('margin-' + (col ? (/reverse/.test(dir) ? 'bottom' : 'top') : (/reverse/.test(dir) ? 'right' : 'left')), (-gap) + 'px');
      }
      mode = m;
    }

    // Padding: keep the host's own padding and add room for the overlays; content-box inputs get a
    // compensating width so the field's outer size never changes. All restored on destroy.
    var orig = { pl: input.style.paddingLeft, pr: input.style.paddingRight, w: input.style.width };
    var cs0 = getComputedStyle(input);
    var baseL = parseFloat(cs0.paddingLeft) || 0, baseR = parseFloat(cs0.paddingRight) || 0;
    var contentBox = cs0.boxSizing === 'content-box';
    var specW = null, autoW = null, applied = 0;
    function probeSpecWidth(){
      // The specified width ("100%", "auto", "12em") is only reported for an unrendered element.
      // Probe a hidden clone (same classes/attributes/position) so the real input is never hidden
      // or blurred. Re-run on window resize so media-query widths are picked up.
      var c = input.cloneNode(false);
      c.style.width = orig.w; c.style.setProperty('display', 'none', 'important');
      c.removeAttribute('autofocus');
      input.parentNode.insertBefore(c, input);
      var w = getComputedStyle(c).width;
      c.remove();
      if (w !== specW) { specW = w; autoW = null; applied = -1; }
    }
    if (contentBox) probeSpecWidth();

    function enabled(){ return scope.restrictTo !== 'park' && !emptyScope; }
    function shown(){ return input.isConnected && input.getClientRects().length > 0; }
    function restorePadding(){
      input.style.paddingLeft = orig.pl; input.style.paddingRight = orig.pr; input.style.width = orig.w;
      applied = 0;
    }
    function applyPadding(){   // only called while the overlay is rendered (offsetWidths are real)
      var addL = current ? pill.offsetWidth + 6 : 0;
      var addR = btn.offsetWidth + 2;
      input.style.paddingLeft  = addL ? (baseL + addL) + 'px' : orig.pl;
      input.style.paddingRight = (baseR + addR) + 'px';
      if (!contentBox) return;
      var delta = addL + addR;
      if (delta === applied) return;
      if (specW && specW !== 'auto') {
        input.style.width = 'calc(' + specW + ' - ' + delta + 'px)';
      } else {
        // width:auto is intrinsic (size attr), so freeze that content width minus the added padding.
        if (autoW === null) { input.style.width = orig.w; autoW = parseFloat(getComputedStyle(input).width) || 0; }
        input.style.width = Math.max(autoW - delta, 20) + 'px';
      }
      applied = delta;
    }
    var field;
    function hideOverlay(){ root.style.display = 'none'; }
    function sync(){
      if (destroyed) return;
      if (!enabled()) { hideOverlay(); restorePadding(); return; }
      if (!shown()) { hideOverlay(); return; }
      // A visibility:hidden input (e.g. the collapsed header search) gets no overlay and no extra
      // padding, which would otherwise hold a collapsed max-width:0 field open.
      if (getComputedStyle(input).visibility === 'hidden') { hideOverlay(); restorePadding(); return; }
      if (field.needsReprobe || mode === null) {
        var rp = field.needsReprobe;
        field.needsReprobe = false;   // cleared only once the field is actually re-measured
        placeAnchor();
        if (contentBox && rp) probeSpecWidth();
      }
      if (!prefetchQueued) { prefetchQueued = true; whenIdle(checkScope); }
      root.style.display = '';
      btn.classList.toggle('ops-filter-on', !!current);
      applyPadding();
      // Offset from the anchor (≈ -input width inline/row, -height block) — fixed while both move together.
      var ra = anchor.getBoundingClientRect(), ri = input.getBoundingClientRect();
      root.style.left = (ri.left - ra.left) + 'px';
      root.style.top  = (ri.top - ra.top) + 'px';
      root.style.width  = input.offsetWidth + 'px';
      root.style.height = input.offsetHeight + 'px';
    }
    function render(){
      pill.hidden = !current;
      if (current) {
        pillTxt.textContent = filterLabel(current);
        pill.setAttribute('data-tip', filterTip(current));
      } else {
        pill.removeAttribute('data-tip');
      }
      sync();
    }
    function set(f, info){
      var next = enabled() ? normFilter(f) : null;
      var same = (!next && !current) || (next && current && next.kingdomId === current.kingdomId && next.parkId === current.parkId);
      current = next;
      render();
      if (!same && cfg.onChange) cfg.onChange(current ? Object.assign({}, current) : null, info || {});
    }
    // Does this scope offer any kingdoms? Prefetched at idle once the field is first visible (one
    // shared, cached request per distinct scope), so empty-scope icons vanish before they're used.
    function checkScope(){
      if (destroyed || scope.restrictTo === 'park') return;
      var key = scopeQS(scope);
      if (checkedKey === key) return;
      checkedKey = key;
      loadKingdoms(field.uir(), scope).then(function(ks){
        if (!destroyed && checkedKey === key && !ks.length) field.markEmpty();
      }).catch(function(){ checkedKey = null; });
    }
    function abortResolve(){ clearTimeout(rTimer); if (rCtrl) { rCtrl.abort(); rCtrl = null; } }
    function resolve(prefix, sc){
      if (rCtrl) rCtrl.abort();
      rCtrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
      var mine = rCtrl;
      return getJSON(field.uir() + 'SearchAjax/filterresolve&prefix=' + encodeURIComponent(prefix) + scopeQS(sc),
        mine ? mine.signal : undefined).then(function(d){
          if (rCtrl === mine) rCtrl = null;
          return normFilter(d && d.match);
        });
    }
    // New scope: re-check the pill BY ID against the scope's (cached) kingdom/park lists — labels
    // can fall back to names, ids can't. Independent of typed-prefix resolution (own sequence).
    function revalidate(){
      var was = current, seq = ++rvSeq, sc = scope, uir = field.uir();
      function byId(rows, id){ for (var i = 0; i < rows.length; i++) if (parseInt(rows[i].id, 10) === id) return rows[i]; return null; }
      loadKingdoms(uir, sc).then(function(ks){
        var k = byId(ks, was.kingdomId);
        if (!k) return null;
        if (!was.parkId) return { k: k, p: null };
        return loadParks(uir, sc, was.kingdomId).then(function(ps){
          var p = byId(ps, was.parkId);
          return p ? { k: k, p: p } : null;
        });
      }).then(function(r){
        if (destroyed || seq !== rvSeq || current !== was) return;
        set(r ? { kingdomId: was.kingdomId, kingdomName: r.k.name, kingdomAbbr: r.k.abbr,
          parkId: r.p ? was.parkId : 0, parkName: r.p ? r.p.name : '', parkAbbr: r.p ? r.p.abbr : '' } : null);
      }).catch(function(){});   // network failure: keep the pill (the server ANDs it with scope anyway)
    }
    function onInput(){
      abortResolve();
      var m = FILTER_PREFIX_RE.exec(input.value);
      if (!m || !enabled()) return;
      var token = m[0];
      rTimer = setTimeout(function(){
        resolve(m[1] + ':' + (m[2] || ''), scope).then(function(f){
          if (!f || destroyed || input.value.indexOf(token) !== 0) return;   // unknown/out of scope: leave text
          var caret = input.selectionStart == null ? input.value.length : input.selectionStart;
          var rest = input.value.slice(token.length).replace(/^\s+/, '');
          var removed = input.value.length - rest.length;
          input.value = rest;
          var pos = Math.max(0, caret - removed);
          try { input.setSelectionRange(pos, pos); } catch (e) {}
          set(f, { textChanged: true });
        }).catch(function(){});
      }, 120);
    }
    function onKeydown(e){
      if (e.key !== 'Backspace' || !current || input.selectionStart !== 0 || input.selectionEnd !== 0) return;
      e.preventDefault();
      // Break the pill back into editable text: "KCG:AR" + whatever was typed, caret after the label.
      // No separator, so it only re-pills when the user types the closing space again.
      var label = filterLabel(current);
      input.value = label + input.value;
      try { input.setSelectionRange(label.length, label.length); } catch (err) {}
      set(null, { textChanged: true });
    }
    function onFocus(){ checkScope(); sync(); }
    function onBtn(e){ e.preventDefault(); checkScope(); openModal(field); }
    function onX(e){ e.preventDefault(); e.stopPropagation(); set(null); input.focus(); }

    input.addEventListener('input', onInput);
    input.addEventListener('keydown', onKeydown);
    input.addEventListener('focus', onFocus);
    input.addEventListener('mouseenter', sync);
    btn.addEventListener('click', onBtn);
    pillX.addEventListener('click', onX);
    if (ro) ro.observe(input);

    field = {
      input: input,
      sync: sync,
      needsReprobe: false,
      absAnchor: function(){ return mode === 'abs'; },
      placement: function(){ return cfg.placement === 'anchored' ? 'anchored' : 'modal'; },
      uir: function(){ return cfg.uir || window.UIR || ''; },
      scope: function(){ return scope; },
      focusInput: function(){ try { input.focus(); } catch (e) {} },
      get: function(){ return current; },
      apply: function(f){ set(f); },     // from the modal (Apply / Clear)
      markEmpty: function(){ emptyScope = true; if (current) set(null); else sync(); },
      configure: function(c){ if (c.uir) cfg.uir = c.uir; if ('placement' in c) cfg.placement = c.placement; /* null/'' → default modal */ if (c.onChange) cfg.onChange = c.onChange; if (c.scope) handle.setScope(c.scope); }
    };
    var handle = {
      get: function(){ return current ? Object.assign({}, current) : null; },
      set: function(f){ set(f); },
      // New hard scope: re-validate the pill against it (clears it when it no longer fits).
      setScope: function(s){
        var next = scopeFrom(s);
        if (scopeQS(next) === scopeQS(scope)) return;
        scope = next; emptyScope = false; checkedKey = null; prefetchQueued = false;
        if (modalField === field) closeModal();
        if (!current) { sync(); return; }
        if (!enabled()) { set(null); return; }
        revalidate();
        sync();
      },
      destroy: function(){
        if (destroyed) return;
        destroyed = true;
        abortResolve(); rvSeq++;
        if (modalField === field) closeModal();
        input.removeEventListener('input', onInput);
        input.removeEventListener('keydown', onKeydown);
        input.removeEventListener('focus', onFocus);
        input.removeEventListener('mouseenter', sync);
        if (ro) ro.unobserve(input);
        restorePadding();
        anchor.remove();
        var i = filterFields.indexOf(field); if (i >= 0) filterFields.splice(i, 1);
        delete input._opsFilter;
      }
    };
    field.handle = handle;
    handle.configure = field.configure;
    filterFields.push(field);
    input._opsFilter = handle;
    sync();
    return handle;
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
    var served = 0;          // rows the server has returned for lastTerm (offset for Load more)

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
      // Wider than a narrow input so the detail line (kingdom · park · real name · username)
      // fits on one line: at least the input's width, ideally DD_WIDTH, never wider than the
      // viewport minus an 8px gutter each side. Shift left when it would run off the right.
      var DD_WIDTH = 440, GUTTER = 8;
      var vw = document.documentElement.clientWidth || window.innerWidth;
      var width = Math.min(Math.max(r.width, DD_WIDTH), vw - GUTTER * 2);
      var left = Math.min(r.left, vw - GUTTER - width);
      dd.style.left = Math.max(GUTTER, left) + 'px';
      dd.style.width = width + 'px';
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
    // Detail line: "KCG · Angler's Rift", plus "· Real Name · username" for officers (the
    // server only sends GivenName/Surname/UserName to officers; restricted names arrive blank).
    function detailLine(p){
      var parts = [];
      if (p.KAbbr) parts.push(p.KAbbr);
      if (p.ParkName) parts.push(p.ParkName);
      var real = ((p.GivenName||'') + ' ' + (p.Surname||'')).trim();
      if (real) parts.push(real);
      if (p.UserName) parts.push(p.UserName);
      return parts.map(esc).join(' · ');
    }
    function rowHtml(p, i){
      var detail = detailLine(p);
      var cls = 'ops-ac-item'
        + (p.Banned || p.PenaltyBox || p.Suspended ? ' ops-ac-row-banned' : '')
        + (p.Active === 0 && !p.Banned && !p.PenaltyBox && !p.Suspended ? ' ops-ac-row-inactive' : '');
      // Typo-tolerant "close matches" come after every exact match; label where they start.
      var head = (p.Fuzzy && !(i > 0 && items[i-1] && items[i-1].Fuzzy))
        ? '<div class="ops-ac-section" aria-hidden="true">Close matches</div>' : '';
      return head + '<div class="'+cls+'" id="'+ddId+'-opt-'+i+'" role="option" data-i="'+i+'" tabindex="-1">'
        + '<span class="ops-ac-name">' + esc(p.Persona) + badges(p) + '</span>'
        + (detail ? '<span class="ops-ac-loc">'+detail+'</span>' : '')
        + '</div>';
    }
    function advUrl(){
      // Carry the current term + surface scope into the Advanced Search page (opens in a new tab).
      // UIR ends in "?Route=" so params join with & (per the &q= rule). Build from live opts.
      // An active filter pill wins over the ring centre: it is what the user narrowed to.
      var uir = opts.uir || window.UIR || '', f = currentFilter();
      var pid = f ? f.parkId : opts.parkId, kid = f ? f.kingdomId : opts.kingdomId;
      return uir + 'Search/advanced&q=' + encodeURIComponent(lastTerm || input.value.trim())
        + (pid ? '&parkId='    + encodeURIComponent(pid) : '')
        + (kid ? '&kingdomId=' + encodeURIComponent(kid) : '');
    }
    function render(){
      // An empty page can still have more results behind it (rows filtered client-side
      // by a long excludeIds list), so the footer is rendered in both cases.
      var html = items.length
        ? items.map(rowHtml).join('')
        : '<div class="ops-ac-empty">No players found</div>';
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
    function buildUrl(term, offset, ex){
      var uir = opts.uir || window.UIR || '';
      var rk = csv(opts.restrictKingdomIds), f = currentFilter();
      return uir + 'SearchAjax/players'
        + '&parkId='    + (opts.parkId    || 0)
        + '&kingdomId=' + (opts.kingdomId || 0)
        + (opts.restrictTo       ? '&restrictTo='       + encodeURIComponent(opts.restrictTo)       : '')
        + (rk                    ? '&restrictKingdomIds='+ encodeURIComponent(rk)                    : '')
        + (opts.excludeKingdomId ? '&excludeKingdomId=' + encodeURIComponent(opts.excludeKingdomId) : '')
        + (opts.excludeParkId    ? '&excludeParkId='    + encodeURIComponent(opts.excludeParkId)    : '')
        + (ex                    ? '&excludeIds='       + encodeURIComponent(ex)                    : '')
        + (opts.bannedScope      ? '&bannedScope='      + encodeURIComponent(opts.bannedScope)      : '')
        + (f ? '&filterKingdomId=' + f.kingdomId + '&filterParkId=' + (f.parkId || 0) : '')
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
      // Long exclusion lists (e.g. big attendance rosters) would blow the GET line: send only
      // the first EXCLUDE_CAP server-side and drop the rest from the returned rows client-side.
      var exAll = list(opts.excludeIds);
      var exSet = null;
      if (exAll.length > EXCLUDE_CAP) {
        exSet = {};
        exAll.forEach(function(id){ exSet[String(id)] = 1; });
      }
      fetch(buildUrl(term, offset, exAll.slice(0, EXCLUDE_CAP).join(',')), ctrl ? { signal: ctrl.signal } : undefined)
        .then(function(r){
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(function(data){
          loading = false; ctrl = null;
          // Tolerate both the envelope {rows,hasMore} and a bare array (defensive).
          var rows = Array.isArray(data) ? data : (data && data.rows) || [];
          hasMore  = Array.isArray(data) ? false : !!(data && data.hasMore);
          served   = (append ? served : 0) + rows.length;
          if (exSet) rows = rows.filter(function(p){ return !exSet[String(p.MundaneId)]; });
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
    function loadMore(){ if (!hasMore || loading) return; fetchPage(lastTerm, served, true); }

    var inst = { input: input, dd: dd, close: close, position: position };
    instances.push(inst);

    function onInput(){
      clearTimeout(timer);
      abortInflight();                    // every edit cancels the stale request
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
      // Keep focus in the input for ANY mousedown in the dropdown (incl. the Advanced Search
      // link), so blur-close doesn't hide it before the click lands.
      e.preventDefault();
      var it = e.target.closest('[role=option]'); if (!it) return;
      pick(parseInt(it.dataset.i,10));
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

    // ---- Scope filter (pill + icon + modal) ----
    var filt = null;
    function currentFilter(){ return filt ? filt.get() : null; }
    function onFilterChange(f, info){
      if (info && info.textChanged && opts.onClear) opts.onClear();
      clearTimeout(timer);
      abortInflight();
      // Deferred a tick, like refresh(): the click that changed the pill must settle first.
      setTimeout(function(){
        var t = input.value.trim();
        // Only re-search where the user is: a scope change (reattach) clearing the pill on an
        // unfocused field must not pop its dropdown open.
        if (t.length >= MINLEN && document.activeElement === input) { items = []; active = -1; search(t); }
        else { close(); }
      }, 0);
    }
    function syncFilter(){
      var want = opts.filter !== false && opts.restrictTo !== 'park';
      if (want && !filt) {
        filt = filterField(input, { uir: opts.uir, scope: scopeFrom(opts), onChange: onFilterChange,
          placement: opts.filterPlacement });
      } else if (!want && filt) {
        var had = !!filt.get();
        filt.destroy(); filt = null;
        if (had) onFilterChange(null, {});
      } else if (filt) {
        filt.configure({ uir: opts.uir, scope: scopeFrom(opts), placement: opts.filterPlacement });   // new scope → pill re-validated
      }
    }
    syncFilter();

    function setOpts(newOpts) { for (var k in newOpts) { opts[k] = newOpts[k]; } syncFilter(); }

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
        if (filt) { filt.destroy(); filt = null; }
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

  return { attach: attach, reattach: reattach, filterField: filterField };
})();
