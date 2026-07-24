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
      hovering = false,
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
      if (!userPaused && !hovering) { t = setInterval(function () { go(i + 1); }, ms); }
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
    car.addEventListener('mouseenter', function () { hovering = true; stop(); });
    car.addEventListener('mouseleave', function () { hovering = false; restart(); });
    car.addEventListener('focusin', function () { hovering = true; stop(); });
    car.addEventListener('focusout', function (e) {
      if (!car.contains(e.relatedTarget)) { hovering = false; restart(); }
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
