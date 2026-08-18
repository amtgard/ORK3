
	// Second/third args optional — pass a URL + label to render a "In the
	// meantime, …" link below the message. Anchor is built with textContent
	// + attribute setters, so any caller-supplied strings stay XSS-safe.
	function navInfoDialog(msg, linkHref, linkLabel) {
		document.getElementById('nav-info-msg').textContent = msg;
		var linkBox = document.getElementById('nav-info-link');
		linkBox.textContent = '';
		if (linkHref && linkLabel) {
			var a = document.createElement('a');
			a.href = linkHref;
			a.textContent = linkLabel;
			a.style.color = '#4299e1';
			a.style.textDecoration = 'underline';
			linkBox.appendChild(a);
		}
		var el = document.getElementById('nav-info-overlay');
		el.style.display = 'flex';
		el.onclick = function(e) { if (e.target === el) el.style.display = 'none'; };
	}
	