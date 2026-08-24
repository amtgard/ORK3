(function () {
  // ---- Hero carousel: auto-advance with a11y controls ----------------------
  // WCAG 2.2.2 (pausable), 2.3.3 (prefers-reduced-motion), 4.1.2 (labelled dots).
  var prefersReduced = window.matchMedia
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  document.querySelectorAll('.fd-carousel').forEach(function (car) {
    var slides = car.querySelectorAll('.fd-slide');
    var dots = car.querySelectorAll('.fd-dot');
    var toggle = car.querySelector('.fd-carousel-toggle');
    if (slides.length < 2) return;

    // Expose the carousel as a labelled region so assistive tech announces it
    // as a "carousel" and users can navigate to it. Respect any role/label the
    // template already set.
    if (!car.hasAttribute('role')) { car.setAttribute('role', 'region'); }
    car.setAttribute('aria-roledescription', 'carousel');
    if (!car.getAttribute('aria-label') && !car.getAttribute('aria-labelledby')) {
      car.setAttribute('aria-label', 'Featured highlights');
    }

    // Visually-hidden polite live region for slide-change announcements. Only
    // user-initiated changes update it (see go(n, true)); the auto-advance
    // timer stays silent so it doesn't nag screen-reader users.
    var live = document.createElement('div');
    live.className = 'fd-carousel-live';
    live.setAttribute('aria-live', 'polite');
    live.setAttribute('aria-atomic', 'true');
    live.style.cssText = 'position:absolute;width:1px;height:1px;padding:0;'
      + 'margin:-1px;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);'
      + 'white-space:nowrap;border:0;';
    car.appendChild(live);

    var i = 0,
      ms = parseInt(car.getAttribute('data-autoplay') || '4500', 10),
      t = null,
      pointerIn = false,
      focusIn = false,
      // Under reduced-motion we start paused (no autoplay) but still let the
      // user press Play to opt in.
      userPaused = !!prefersReduced;
    if (isNaN(ms) || ms < 100) ms = 4500;

    // Take the outgoing slide out of the tab order / SR output, and restore the
    // incoming one. inert (where supported) also removes pointer + focus; the
    // aria-hidden mirror covers assistive tech regardless.
    function hideSlide(el) {
      el.setAttribute('aria-hidden', 'true');
      el.setAttribute('inert', '');
    }
    function showSlide(el) {
      el.removeAttribute('aria-hidden');
      el.removeAttribute('inert');
    }
    // Initial state: only the active slide is exposed.
    slides.forEach(function (s, idx) { if (idx === i) { showSlide(s); } else { hideSlide(s); } });

    function go(n, announce) {
      slides[i].classList.remove('is-active');
      hideSlide(slides[i]);
      if (dots[i]) { dots[i].classList.remove('on'); dots[i].removeAttribute('aria-current'); }
      i = (n + slides.length) % slides.length;
      slides[i].classList.add('is-active');
      showSlide(slides[i]);
      if (dots[i]) { dots[i].classList.add('on'); dots[i].setAttribute('aria-current', 'true'); }
      // Announce only on user-initiated changes, never on the autoplay timer.
      if (announce) { live.textContent = 'Slide ' + (i + 1) + ' of ' + slides.length; }
    }
    function stop() { if (t) { clearInterval(t); t = null; } }
    function restart() {
      stop();
      if (!userPaused && !pointerIn && !focusIn) { t = setInterval(function () { go(i + 1); }, ms); }
    }
    function syncToggle() {
      if (!toggle) return;
      var icon = toggle.querySelector('i');
      if (userPaused) {
        toggle.setAttribute('aria-label', 'Play slideshow');
        toggle.setAttribute('aria-pressed', 'true');
        if (icon) { icon.className = 'fas fa-play'; }
      } else {
        toggle.setAttribute('aria-label', 'Pause slideshow');
        toggle.setAttribute('aria-pressed', 'false');
        if (icon) { icon.className = 'fas fa-pause'; }
      }
    }

    dots.forEach(function (d, idx) {
      d.addEventListener('click', function () { go(idx, true); restart(); });
    });

    // Pause auto-advance while the pointer or keyboard focus is inside the
    // carousel (transient — does not flip the user's explicit pause state).
    // The two sources are tracked separately so one ending never resumes
    // autoplay while the other is still inside.
    car.addEventListener('mouseenter', function () { pointerIn = true; stop(); });
    car.addEventListener('mouseleave', function () { pointerIn = false; restart(); });
    // Only keyboard focus counts: a mouse click on a dot/toggle also fires
    // focusin, and that must not leave a sticky pause after the pointer leaves.
    car.addEventListener('focusin', function (e) {
      var el = e.target, kb = true;
      try { kb = !el.matches || el.matches(':focus-visible'); } catch (err) { kb = true; }
      if (!kb) { return; }
      focusIn = true; stop();
    });
    car.addEventListener('focusout', function (e) {
      if (!car.contains(e.relatedTarget)) { focusIn = false; restart(); }
    });

    if (toggle) {
      toggle.addEventListener('click', function () {
        userPaused = !userPaused;
        syncToggle();
        restart();
      });
    }

    // Touch-swipe: horizontal drag past a threshold advances/rewinds a slide.
    // Only act when the gesture is clearly horizontal so vertical page scroll
    // isn't hijacked. Passive listeners — we never preventDefault.
    var swipeX = null, swipeY = null;
    car.addEventListener('touchstart', function (e) {
      var tt = e.changedTouches && e.changedTouches[0];
      if (!tt) { return; }
      swipeX = tt.clientX;
      swipeY = tt.clientY;
    }, { passive: true });
    car.addEventListener('touchend', function (e) {
      if (swipeX === null) { return; }
      var tt = e.changedTouches && e.changedTouches[0];
      if (!tt) { swipeX = swipeY = null; return; }
      var dx = tt.clientX - swipeX,
        dy = tt.clientY - swipeY;
      swipeX = swipeY = null;
      if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
        go(dx < 0 ? i + 1 : i - 1, true);
        restart();
      }
    }, { passive: true });

    syncToggle();
    restart();
  });

  // ---- Mobile nav toggle ---------------------------------------------------
  var nav = document.querySelector('.fd-nav');
  var toggle = document.querySelector('.fd-nav-toggle');
  if (nav && toggle) {
    toggle.setAttribute('aria-expanded', 'false');
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('fd-nav-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // ---- Marketing-nav submenus: reflect open state for assistive tech -------
  // CSS reveals the dropdown on :hover / :focus-within; mirror that state onto
  // aria-expanded so screen-reader users know the submenu opened.
  document.querySelectorAll('.fd-navitem').forEach(function (item) {
    var trigger = item.querySelector('a[aria-haspopup="true"]');
    if (!trigger) return;
    function set(open) { trigger.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    item.addEventListener('mouseenter', function () { set(true); });
    item.addEventListener('mouseleave', function () {
      if (!item.contains(document.activeElement)) { set(false); }
    });
    item.addEventListener('focusin', function () { set(true); });
    item.addEventListener('focusout', function (e) {
      if (!item.contains(e.relatedTarget)) { set(false); }
    });
  });
})();

// ---- staff_roster: shared contact-card modal ------------------------------
// Moved verbatim out of frontdoor/blocks/staff_roster.tpl (it contained no PHP)
// so it is downloaded once and cacheable instead of being inlined per render.
// The partial still emits the single shared #fdRosterModal markup + its CSS;
// this behaviour is fully class/attribute-delegated off document, so it does
// not care how many staff_roster blocks are on the page (or whether any are).
// Keeps its own DOMContentLoaded shim: frontdoor.js has none and the IIFE above
// runs at parse time, whereas this needs #fdRosterModal to exist in the DOM.
(function () {
    if (window.__fdRosterModalInit) { return; }
    window.__fdRosterModalInit = true;
    function ready(fn) { if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
    ready(function () {
        var modal = document.getElementById('fdRosterModal');
        if (!modal) { return; }
        var cardEl  = modal.querySelector('.fd-rmodal-card');
        var avatar  = document.getElementById('fdRModalAvatar');
        var elName  = document.getElementById('fdRModalName');
        var elSec   = document.getElementById('fdRModalSecondary');
        var elRole  = document.getElementById('fdRModalRole');
        var elBio   = document.getElementById('fdRModalBio');
        var elProfile = document.getElementById('fdRModalProfile');
        var lastTrigger = null;
        var inertNodes = [];   // {el, hadAriaHidden, prevAriaHidden, hadInert}

        function setField(el, val) { if (val) { el.textContent = val; el.hidden = false; } else { el.textContent = ''; el.hidden = true; } }

        // Make everything outside the modal inert + aria-hidden so SR/keyboard
        // users can't Tab or scroll past the dialog. Walk the siblings of the
        // modal at every level from its parent up to <body> (mirrors the gallery
        // lightbox inert pattern).
        function setBackgroundInert(on) {
            if (on) {
                inertNodes = [];
                var node = modal;
                while (node && node.parentNode && node.parentNode.nodeType === 1) {
                    var parent = node.parentNode;
                    var kids = parent.children;
                    for (var k = 0; k < kids.length; k++) {
                        var sib = kids[k];
                        if (sib === node) { continue; }
                        inertNodes.push({
                            el: sib,
                            hadAriaHidden: sib.hasAttribute('aria-hidden'),
                            prevAriaHidden: sib.getAttribute('aria-hidden'),
                            hadInert: sib.hasAttribute('inert')
                        });
                        sib.setAttribute('aria-hidden', 'true');
                        sib.setAttribute('inert', '');
                    }
                    if (parent === document.body || parent.tagName === 'BODY') { break; }
                    node = parent;
                }
            } else {
                inertNodes.forEach(function (r) {
                    if (r.hadAriaHidden) { r.el.setAttribute('aria-hidden', r.prevAriaHidden); }
                    else { r.el.removeAttribute('aria-hidden'); }
                    if (!r.hadInert) { r.el.removeAttribute('inert'); }
                });
                inertNodes = [];
            }
        }

        function open(trigger) {
            var d = trigger.dataset;
            if (d.fdImg) {
                avatar.textContent = '';
                var im = document.createElement('img');
                im.src = d.fdImg; im.alt = '';
                avatar.appendChild(im);
            } else {
                avatar.textContent = d.fdInitials || '?';
            }
            elName.textContent = d.fdName || '';
            setField(elSec, d.fdSecondary || '');
            setField(elRole, d.fdRole || '');
            setField(elBio, d.fdBio || '');
            if (elProfile) {
                if (d.fdLink) { elProfile.setAttribute('href', d.fdLink); elProfile.hidden = false; }
                else { elProfile.removeAttribute('href'); elProfile.hidden = true; }
            }
            lastTrigger = trigger;
            modal.hidden = false;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            setBackgroundInert(true);
            document.body.style.overflow = 'hidden';
            cardEl.focus();
        }
        function close() {
            modal.classList.remove('is-open');
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            setBackgroundInert(false);
            document.body.style.overflow = '';
            if (lastTrigger && typeof lastTrigger.focus === 'function') { lastTrigger.focus(); }
            lastTrigger = null;
        }

        document.addEventListener('click', function (e) {
            var trigger = e.target.closest ? e.target.closest('.fd-roster-card-modal') : null;
            if (trigger) { e.preventDefault(); open(trigger); return; }
            if (e.target.closest && e.target.closest('[data-fd-close]')) { close(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('is-open')) { close(); return; }
            var trigger = (e.target.closest) ? e.target.closest('.fd-roster-card-modal') : null;
            if (trigger && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(trigger); }
        });
        // Minimal focus trap: keep Tab within the dialog. The focusable set is
        // recomputed on each Tab (the close button, plus the profile link when
        // fdLink is set), returning focus to the dialog card when it is empty.
        modal.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab' || !modal.classList.contains('is-open')) { return; }
            var focusables = modal.querySelectorAll('button, [href], [tabindex]:not([tabindex="-1"])');
            if (!focusables.length) { e.preventDefault(); cardEl.focus(); return; }
            var first = focusables[0], last = focusables[focusables.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        });
    });
})();
