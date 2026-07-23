
/* Offline banner */
(function() {
  var banner = document.getElementById('ork-offline-banner');
  if (!banner) return;
  function update() { banner.style.display = navigator.onLine ? 'none' : 'block'; }
  window.addEventListener('offline', update);
  window.addEventListener('online',  update);
  update();
})();

/* Global dark mode toggle — runs on every page.
   Skips init if revised.js already defined orkInitTheme (revised-frontend pages). */
(function() {
  if (typeof orkInitTheme === 'function') {
    orkInitTheme();
    return;
  }

  function _isDark() {
    var attr = document.documentElement.getAttribute('data-theme');
    if (attr === 'dark') return true;
    if (attr === 'light') return false;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  function _setIcon(btn) {
    if (!btn) return;
    var stored = localStorage.getItem('ork_theme');
    var sunSvg  = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';
    var moonSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
    var autoSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 2a10 10 0 0 1 0 20V2z" fill="currentColor"/></svg>';
    if (stored === 'dark') {
      btn.innerHTML = moonSvg;
      btn.title = 'Dark mode (click for light)';
    } else if (stored === 'light') {
      btn.innerHTML = sunSvg;
      btn.title = 'Light mode (click for auto)';
    } else {
      btn.innerHTML = autoSvg;
      btn.title = 'Auto mode (follows OS setting, click for dark)';
    }
  }

  function _init() {
    var stored = localStorage.getItem('ork_theme');
    if (stored === 'dark') {
      document.documentElement.setAttribute('data-theme', 'dark');
    } else if (stored === 'light') {
      document.documentElement.setAttribute('data-theme', 'light');
    } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      document.documentElement.setAttribute('data-theme', 'dark');
    }

    // Live OS preference listener — only fires when user is in auto mode (no stored pref)
    if (window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
        if (localStorage.getItem('ork_theme')) return; // user has a manual pref, ignore
        if (e.matches) {
          document.documentElement.setAttribute('data-theme', 'dark');
        } else {
          document.documentElement.removeAttribute('data-theme');
        }
      });
    }

    var btn = document.getElementById('ork-theme-toggle');
    _setIcon(btn);
    if (btn) {
      btn.addEventListener('click', function() {
        var stored = localStorage.getItem('ork_theme');
        // Cycle: auto → dark → light → auto (keyed off stored pref, not data-theme)
        if (!stored) {
          document.documentElement.setAttribute('data-theme', 'dark');
          localStorage.setItem('ork_theme', 'dark');
        } else if (stored === 'dark') {
          document.documentElement.setAttribute('data-theme', 'light');
          localStorage.setItem('ork_theme', 'light');
        } else {
          // light → auto: remove preference, re-apply OS preference live
          localStorage.removeItem('ork_theme');
          if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.setAttribute('data-theme', 'dark');
          } else {
            document.documentElement.removeAttribute('data-theme');
          }
        }
        _setIcon(btn);
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _init);
  } else {
    _init();
  }
})();

/* Highcharts dark mode — setOptions for backgroundColor (tooltip handled via CSS) */
if (typeof Highcharts !== 'undefined') {
  (function() {
    var dk = document.documentElement.getAttribute('data-theme') === 'dark'
      || (!document.documentElement.getAttribute('data-theme')
          && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    if (dk) {
      Highcharts.setOptions({
        chart: { backgroundColor: 'transparent' }
      });
    }
  })();
}
