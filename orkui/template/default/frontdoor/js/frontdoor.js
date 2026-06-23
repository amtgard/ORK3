(function () {
  // Hero carousel: auto-advance, clickable dots, pause on interaction.
  document.querySelectorAll('.fd-carousel').forEach(function (car) {
    var slides = car.querySelectorAll('.fd-slide');
    var dots = car.querySelectorAll('.fd-dot');
    if (slides.length < 2) return;
    var i = 0, ms = parseInt(car.getAttribute('data-autoplay') || '4500', 10), t;
    function go(n) {
      slides[i].classList.remove('is-active'); if (dots[i]) dots[i].classList.remove('on');
      i = (n + slides.length) % slides.length;
      slides[i].classList.add('is-active'); if (dots[i]) dots[i].classList.add('on');
    }
    function start() { t = setInterval(function () { go(i + 1); }, ms); }
    dots.forEach(function (d, idx) { d.addEventListener('click', function () { clearInterval(t); go(idx); start(); }); });
    start();
  });
  // Mobile nav toggle
  var nav = document.querySelector('.fd-nav');
  var toggle = document.querySelector('.fd-nav-toggle');
  if (nav && toggle) {
    toggle.addEventListener('click', function () { nav.classList.toggle('fd-nav-open'); });
  }
})();
