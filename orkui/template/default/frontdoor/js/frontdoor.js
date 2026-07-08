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

    var i = 0,
      ms = parseInt(car.getAttribute('data-autoplay') || '4500', 10),
      t = null,
      hovering = false,
      // Under reduced-motion we start paused (no autoplay) but still let the
      // user press Play to opt in.
      userPaused = !!prefersReduced;
    if (isNaN(ms) || ms < 100) ms = 4500;

    function go(n) {
      slides[i].classList.remove('is-active');
      if (dots[i]) { dots[i].classList.remove('on'); dots[i].removeAttribute('aria-current'); }
      i = (n + slides.length) % slides.length;
      slides[i].classList.add('is-active');
      if (dots[i]) { dots[i].classList.add('on'); dots[i].setAttribute('aria-current', 'true'); }
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
      d.addEventListener('click', function () { go(idx); restart(); });
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
