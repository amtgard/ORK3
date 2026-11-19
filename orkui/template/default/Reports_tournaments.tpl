<?php /* Tournament Report — rp- shell (header / context / stats) + tabbed detail views */ ?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__.'/style/reports.css')?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>revised-frontend/style/ork-datatables.css?v=<?=filemtime(DIR_TEMPLATE.'revised-frontend/style/ork-datatables.css')?>">
<?php
	$T          = $ProgramStats['Totals'] ?? [];
	$scopeType  = $ScopeType;                       // 'kingdom' | 'park' | ''
	$scopeName  = $ScopeName ?? '';
	$scopeIcon  = $ScopeIcon ?? 'fa-globe';
	$scopeLink  = $ScopeLink ?? '';
	$scopeNoun  = $scopeType === 'park' ? 'park' : ($scopeType === 'kingdom' ? 'kingdom' : 'scope');
	$dateFrom   = $DateFrom;
	$dateTo     = $DateTo;
	$fmtDate    = function($d){ return $d ? date('M j, Y', strtotime($d)) : ''; };
	$periodLbl  = '';
	if ($dateFrom && $dateTo)      $periodLbl = $fmtDate($dateFrom) . ' – ' . $fmtDate($dateTo);
	elseif ($dateFrom)             $periodLbl = 'Since ' . $fmtDate($dateFrom);
	elseif ($dateTo)               $periodLbl = 'Through ' . $fmtDate($dateTo);
	$scopeParam = $scopeType === 'park' ? 'ParkId' : 'KingdomId';
	// Base URL for the lazy per-tab section loads; carries the exact scope of this page
	// (AllTime clears the dates, otherwise the resolved from/to) so a fetched section
	// renders against the identical window as the first paint.
	$sectionBase = UIR . 'Reports/tournament_section&' . $scopeParam . '=' . (int)$ScopeId;
	if (!empty($IsAllTime)) {
		$sectionBase .= '&AllTime=1';
	} else {
		if ($dateFrom !== '') $sectionBase .= '&DateFrom=' . rawurlencode($dateFrom);
		if ($dateTo   !== '') $sectionBase .= '&DateTo='   . rawurlencode($dateTo);
	}
?>
<div class="rp-root" id="tnr-report" data-scope="<?=htmlspecialchars($scopeType)?>" data-id="<?=(int)$ScopeId?>">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-trophy rp-header-icon"></i>
				<h1 class="rp-header-title">Tournament Report</h1>
<?php if ($periodLbl): ?>
				<span style="display:inline-flex;align-items:center;background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);border-radius:20px;padding:3px 11px;font-size:0.78rem;font-weight:600;color:rgba(255,255,255,0.9);white-space:nowrap;"><?=htmlspecialchars($periodLbl)?></span>
<?php endif; ?>
			</div>
<?php if ($scopeName): ?>
			<div class="rp-header-scope">
				<span class="rp-scope-chip-label">Scope:</span>
<?php if ($scopeLink): ?>
				<a href="<?=$scopeLink?>" class="rp-scope-chip"><i class="fas <?=htmlspecialchars($scopeIcon)?>"></i> <?=htmlspecialchars($scopeName)?></a>
<?php else: ?>
				<span class="rp-scope-chip"><i class="fas <?=htmlspecialchars($scopeIcon)?>"></i> <?=htmlspecialchars($scopeName)?></span>
<?php endif; ?>
			</div>
<?php endif; ?>
		</div>
		<div class="rp-header-actions">
			<form class="tnr-filter" method="get" id="tnr-filter">
				<input type="hidden" name="<?=$scopeParam?>" value="<?=(int)$ScopeId?>">
				<input type="text" class="tnr-date" name="DateFrom" placeholder="From" value="<?=htmlspecialchars($dateFrom)?>">
				<input type="text" class="tnr-date" name="DateTo" placeholder="To" value="<?=htmlspecialchars($dateTo)?>">
				<button type="submit" class="rp-btn-ghost"><i class="fas fa-filter"></i> Apply</button>
<?php if ($dateFrom || $dateTo): ?>
				<a class="rp-btn-ghost" href="<?=UIR?>Reports/tournaments&<?=$scopeParam?>=<?=(int)$ScopeId?>&AllTime=1"><i class="fas fa-times"></i> All time</a>
<?php endif; ?>
			</form>
		</div>
	</div>

	<!-- Context strip -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Tournament results<?php if ($scopeNoun !== 'scope'): ?> for this <?=$scopeNoun?><?php endif; ?><?php if ($periodLbl): ?> over the selected date range<?php endif; ?>. Championships count per-bracket wins; <strong>Warrior level</strong> (0–12) reflects each fighter's Order of the Warrior ranking. Upsets are wins over opponents ranked 3+ Warrior levels higher.</span>
	</div>

	<!-- Stats row -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-tip"><span class="rp-stat-tip-icon" data-tip="Total tournaments held in this scope and date range.">?</span></div>
			<div class="rp-stat-icon"><i class="fas fa-trophy"></i></div>
			<div class="rp-stat-number"><?=(int)($T['Total']??0)?></div>
			<div class="rp-stat-label">Tournaments</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-tip"><span class="rp-stat-tip-icon" data-tip="Distinct linked players entered in an individual bracket (matches the Fighters list).">?</span></div>
			<div class="rp-stat-icon"><i class="fas fa-users"></i></div>
			<div class="rp-stat-number"><?=number_format((int)($T['UniqueParticipants']??0))?></div>
			<div class="rp-stat-label">Unique Fighters</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-tip"><span class="rp-stat-tip-icon" data-tip="Share of tournaments marked complete.">?</span></div>
			<div class="rp-stat-icon"><i class="fas fa-flag-checkered"></i></div>
			<div class="rp-stat-number"><?=(int)($T['CompletionRate']??0)?>%</div>
			<div class="rp-stat-label">Completion Rate</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-tip"><span class="rp-stat-tip-icon" data-tip="Average Order-of-the-Warrior level (0–12) of participants at time of competition.">?</span></div>
			<div class="rp-stat-icon"><i class="fas fa-khanda"></i></div>
			<div class="rp-stat-number"><?=htmlspecialchars($T['AvgWarriorLevel']??0)?></div>
			<div class="rp-stat-label">Avg Warrior Level</div>
		</div>
	</div>

	<!-- Tabs -->
	<nav class="tnr-tabs" role="tablist">
		<button class="tnr-tab tnr-active" data-tnrtab="overview"><i class="fas fa-chart-line"></i> Overview</button>
		<button class="tnr-tab" data-tnrtab="tournaments"><i class="fas fa-trophy"></i> Tournaments</button>
		<button class="tnr-tab" data-tnrtab="fighters"><i class="fas fa-khanda"></i> Fighters</button>
		<button class="tnr-tab" data-tnrtab="awards"><i class="fas fa-medal"></i> Awards</button>
<?php if ($scopeType === 'kingdom'): ?>
		<button class="tnr-tab" data-tnrtab="parks"><i class="fas fa-map-marked-alt"></i> Parks</button>
<?php endif; ?>
<?php if (!empty($HasTeamChampions)): ?>
		<button class="tnr-tab" data-tnrtab="teams"><i class="fas fa-users"></i> Team Champions</button>
<?php endif; ?>
	</nav>

	<!-- Overview: breakdowns + trend (rendered on first paint) -->
	<div class="tnr-panel tnr-active" id="tnr-tab-overview">
<?php $Section = 'overview'; include __DIR__ . '/Reports_tournaments_section.tpl'; ?>
	</div>

	<!-- Non-Overview tab bodies are lazy-loaded (Reports/tournament_section) on first
	     activation; each shell is populated once via AJAX and then cached in the DOM. -->
	<div class="tnr-panel" id="tnr-tab-tournaments" data-tnr-section="tournaments"></div>

	<div class="tnr-panel" id="tnr-tab-fighters" data-tnr-section="fighters"></div>

	<div class="tnr-panel" id="tnr-tab-awards" data-tnr-section="awards"></div>

<?php if ($scopeType === 'kingdom'): ?>
	<div class="tnr-panel" id="tnr-tab-parks" data-tnr-section="parks"></div>
<?php endif; ?>

<?php if (!empty($HasTeamChampions)): ?>
	<div class="tnr-panel" id="tnr-tab-teams" data-tnr-section="teams"></div>
<?php endif; ?>

</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<?php /* revised.js provides the shared window.orkInitDataTable used by the Kingdom/Park tournament tables. */ ?>
<script src="<?=HTTP_TEMPLATE?>revised-frontend/script/revised.js?v=<?=filemtime(DIR_TEMPLATE.'revised-frontend/script/revised.js')?>"></script>
<script>window.__tnrSectionBase = <?=json_encode($sectionBase)?>;</script>
<script>
(function(){
  var root = document.getElementById('tnr-report'); if(!root) return;

  // Leaderboard tables → shared ORK DataTables init (same as the Kingdom/Park tournament
  // tables). Runs after a lazy section's HTML is injected; order [] keeps the server ranking.
  function bindDataTables(scope){
    if(!window.jQuery || !jQuery.fn.dataTable || typeof window.orkInitDataTable!=='function') return;
    scope.querySelectorAll('table.tnr-dt').forEach(function(t){
      if(jQuery.fn.dataTable.isDataTable(t)) return;
      window.orkInitDataTable(jQuery(t), { order: [], csvName: t.getAttribute('data-csv-name') || 'Tournament Report' });
    });
  }

  // Tournaments tab: show more / less of the collective standings (rows 5-8).
  function bindShowmore(scope){
    scope.querySelectorAll('.tnr-showmore').forEach(function(btn){
      if(btn.__tnrMore) return; btn.__tnrMore=1;
      btn.addEventListener('click', function(){
        var card=btn.closest('.tnr-tcard');
        var open=card.classList.toggle('tnr-show-all');
        btn.textContent = open ? 'Show less' : 'Show more';
      });
    });
  }

  function bindPanel(scope){ bindDataTables(scope); bindShowmore(scope); }

  // Fetch a non-Overview tab body once, on first activation, then cache it in the DOM.
  function loadSection(panel){
    if(panel.dataset.tnrLoaded || panel.dataset.tnrLoading) return;
    var section = panel.dataset.tnrSection;
    if(!section || !window.__tnrSectionBase) return;
    panel.dataset.tnrLoading='1';
    panel.innerHTML='<div class="tnr-empty">Loading…</div>';
    fetch(window.__tnrSectionBase+'&Section='+encodeURIComponent(section), {credentials:'same-origin'})
      .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.text(); })
      .then(function(html){
        panel.innerHTML=html;
        panel.dataset.tnrLoaded='1';
        delete panel.dataset.tnrLoading;
        bindPanel(panel);
      })
      .catch(function(){
        delete panel.dataset.tnrLoading;
        panel.innerHTML='<div class="tnr-empty">Couldn’t load this section. <button type="button" class="rp-btn-ghost tnr-retry">Retry</button></div>';
        var rb=panel.querySelector('.tnr-retry'); if(rb) rb.addEventListener('click', function(){ loadSection(panel); });
      });
  }

  root.querySelectorAll('.tnr-tab').forEach(function(btn){
    btn.addEventListener('click', function(){
      root.querySelectorAll('.tnr-tab').forEach(t=>t.classList.remove('tnr-active'));
      root.querySelectorAll('.tnr-panel').forEach(p=>p.classList.remove('tnr-active'));
      btn.classList.add('tnr-active');
      var panel = document.getElementById('tnr-tab-'+btn.dataset.tnrtab);
      if(panel){ panel.classList.add('tnr-active'); if(panel.dataset.tnrSection) loadSection(panel); }
    });
  });

  bindPanel(root); // Overview is server-rendered on first paint.

  if(window.flatpickr){
    root.querySelectorAll('.tnr-date').forEach(function(el){
      flatpickr(el,{altInput:true,altFormat:'M j, Y',dateFormat:'Y-m-d'});
    });
  }

  (function drawTrend(){
    var data=window.__tnrTrend||[], svg=document.getElementById('tnr-trend');
    if(!svg) return;
    if(!data.length){ svg.insertAdjacentHTML('afterend','<div class="tnr-empty">No activity in range.</div>'); svg.remove(); return; }
    var W=720,H=220, ml=42, mr=14, mt=22, mb=30;
    var pw=W-ml-mr, ph=H-mt-mb, n=data.length;
    var rawMax=Math.max.apply(null,data.map(function(d){return d.Participants;}).concat([1]));
    function niceMax(v){ if(v<=5)return 5; var p=Math.pow(10,Math.floor(Math.log10(v))); var f=v/p; var nf=f<=1?1:f<=2?2:f<=5?5:10; return nf*p; }
    var maxP=niceMax(rawMax);
    var step=pw/n, gap=Math.min(14, step*0.25), bw=step-gap;
    var labelEvery=Math.ceil(n/12); // thin month labels when the range is long
    function monShort(ym){ var s=ym.split('-'); var m=['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][parseInt(s[1],10)]; return m+" '"+s[0].slice(2); }
    var g='';
    // y gridlines + value labels (0, mid, max)
    [0,0.5,1].forEach(function(f){
      var y=mt+ph-ph*f;
      g+='<line x1="'+ml+'" y1="'+y+'" x2="'+(W-mr)+'" y2="'+y+'" class="tnr-grid"/>';
      g+='<text x="'+(ml-7)+'" y="'+(y+4)+'" class="tnr-axis tnr-axis-y">'+Math.round(maxP*f)+'</text>';
    });
    data.forEach(function(d,i){
      var x=ml+i*step+gap/2;
      var bh=ph*(d.Participants/maxP), y=mt+ph-bh;
      g+='<rect x="'+x.toFixed(1)+'" y="'+y.toFixed(1)+'" width="'+bw.toFixed(1)+'" height="'+Math.max(0,bh).toFixed(1)+'" rx="2" class="tnr-bar-p"></rect>';
      if(d.Tournaments>0){
        var inside=bh>18, ty=inside?(y+14):(y-5);
        g+='<text x="'+(x+bw/2).toFixed(1)+'" y="'+ty.toFixed(1)+'" class="tnr-bar-lbl'+(inside?' tnr-bar-lbl-in':'')+'">'+d.Tournaments+'</text>';
      }
      if(i%labelEvery===0){
        g+='<text x="'+(x+bw/2).toFixed(1)+'" y="'+(mt+ph+16)+'" class="tnr-axis tnr-axis-x">'+monShort(d.Month)+'</text>';
      }
    });
    svg.innerHTML=g;
  })();
})();
</script>
