(function(){
	// Module-level guard: only wire once per page
	if (window.__wvSigWired) return;
	window.__wvSigWired = true;

	function initWidget(root) {
		const typeInput = root.querySelector('.wv-sig-type');
		const dataInput = root.querySelector('.wv-sig-data');
		const canvas = root.querySelector('.wv-sig-canvas');
		const ctx = canvas.getContext('2d');
		const tabDraw = root.querySelector('.wv-sig-tab[data-mode="draw"]');
		const tabType = root.querySelector('.wv-sig-tab[data-mode="type"]');
		const paneDraw = root.querySelector('.wv-sig-pane-draw');
		const paneType = root.querySelector('.wv-sig-pane-type');
		const typed = root.querySelector('.wv-sig-typed');
		const preview = root.querySelector('.wv-sig-typed-preview');
		let strokes = [];
		let current = null;

		function setMode(mode) {
			typeInput.value = mode;
			if (mode === 'draw') { tabDraw.classList.add('wv-sig-tab-active'); tabType.classList.remove('wv-sig-tab-active'); paneDraw.style.display = ''; paneType.style.display = 'none'; }
			else { tabType.classList.add('wv-sig-tab-active'); tabDraw.classList.remove('wv-sig-tab-active'); paneDraw.style.display = 'none'; paneType.style.display = ''; }
			syncData();
		}
		tabDraw.addEventListener('click', () => setMode('draw'));
		tabType.addEventListener('click', () => setMode('type'));

		function syncData() {
			if (typeInput.value === 'drawn') dataInput.value = JSON.stringify(strokes);
			else dataInput.value = typed.value.trim();
		}

		function canvasPoint(e) {
			const rect = canvas.getBoundingClientRect();
			const clientX = (e.touches && e.touches[0]) ? e.touches[0].clientX : e.clientX;
			const clientY = (e.touches && e.touches[0]) ? e.touches[0].clientY : e.clientY;
			return { x: (clientX - rect.left) / rect.width, y: (clientY - rect.top) / rect.height };
		}
		function redraw() {
			ctx.clearRect(0, 0, canvas.width, canvas.height);
			ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.lineWidth = 2; ctx.strokeStyle = '#111';
			strokes.forEach(s => {
				if (!s.length) return;
				ctx.beginPath();
				ctx.moveTo(s[0].x * canvas.width, s[0].y * canvas.height);
				for (let i = 1; i < s.length; i++) ctx.lineTo(s[i].x * canvas.width, s[i].y * canvas.height);
				ctx.stroke();
			});
		}
		function start(e) { e.preventDefault(); current = [canvasPoint(e)]; strokes.push(current); }
		function move(e)  { if (!current) return; e.preventDefault(); current.push(canvasPoint(e)); redraw(); }
		function end()     { current = null; syncData(); }

		canvas.addEventListener('pointerdown', start);
		canvas.addEventListener('pointermove', move);
		window.addEventListener('pointerup', end);

		root.querySelector('.wv-sig-clear').addEventListener('click', () => { strokes = []; redraw(); syncData(); });
		root.querySelector('.wv-sig-undo').addEventListener('click',  () => { strokes.pop(); redraw(); syncData(); });

		typed.addEventListener('input', () => { preview.textContent = typed.value; syncData(); });

		// High-DPI canvas
		const dpr = window.devicePixelRatio || 1;
		const w = canvas.clientWidth, h = canvas.clientHeight;
		canvas.width  = w * dpr;
		canvas.height = h * dpr;
		ctx.scale(dpr, dpr);

		setMode('draw');
	}

	function wireAll() {
		document.querySelectorAll('.wv-sig-widget').forEach(initWidget);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', wireAll);
	} else {
		wireAll();
	}

	// Helper: render a read-only signature (used in review/print)
	window.wvRenderSignature = function(containerEl, type, data, w, h) {
		if (type === 'typed') {
			containerEl.innerHTML = '';
			const div = document.createElement('div');
			div.style.cssText = 'font-family: Homemade Apple, Caveat, Brush Script MT, cursive; font-size: 32px;';
			div.textContent = data;
			containerEl.appendChild(div);
			return;
		}
		let strokes; try { strokes = JSON.parse(data); } catch (_) { strokes = []; }
		const canvas = document.createElement('canvas');
		canvas.width = w || 600; canvas.height = h || 180;
		canvas.style.cssText = 'width: 100%; max-width: ' + (w||600) + 'px; height: auto;';
		const ctx = canvas.getContext('2d');
		ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.lineWidth = 2; ctx.strokeStyle = '#111';
		strokes.forEach(s => {
			if (!s.length) return;
			ctx.beginPath();
			ctx.moveTo(s[0].x * canvas.width, s[0].y * canvas.height);
			for (let i = 1; i < s.length; i++) ctx.lineTo(s[i].x * canvas.width, s[i].y * canvas.height);
			ctx.stroke();
		});
		containerEl.innerHTML = '';
		containerEl.appendChild(canvas);
	};
})();
