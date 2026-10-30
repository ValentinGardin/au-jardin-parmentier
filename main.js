// Navigation, onglets des cartes, apparitions au défilement et barre mobile (sans dépendance)
(function () {
  'use strict';

  var nav = document.getElementById('nav');
  var burger = nav.querySelector('.burger');
  var barre = document.querySelector('.barre');
  var hero = document.getElementById('accueil');

  // Menu mobile
  function fermer() { nav.classList.remove('ouvert'); burger.setAttribute('aria-expanded', 'false'); burger.setAttribute('aria-label', 'Ouvrir le menu'); }
  burger.addEventListener('click', function () {
    var o = nav.classList.toggle('ouvert');
    burger.setAttribute('aria-expanded', String(o));
    burger.setAttribute('aria-label', o ? 'Fermer le menu' : 'Ouvrir le menu');
  });
  nav.querySelectorAll('.menu a').forEach(function (a) { a.addEventListener('click', fermer); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fermer(); });

  // Nav marquée et barre mobile visibles une fois le haut de page dépassé
  function onScroll() {
    var y = window.scrollY;
    nav.classList.toggle('plein', y > 10);
    if (barre) barre.classList.toggle('vue', y > hero.offsetHeight * 0.5);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  // Onglets des cartes traiteur (clavier : flèches, Début, Fin)
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.onglets [role="tab"]'));
  function choisir(t, focus) {
    tabs.forEach(function (b) {
      var on = b === t;
      b.setAttribute('aria-selected', String(on));
      b.tabIndex = on ? 0 : -1;
      document.getElementById(b.getAttribute('aria-controls')).hidden = !on;
    });
    if (focus) t.focus();
    t.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { choisir(t); });
    t.addEventListener('keydown', function (e) {
      var n = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[e.key];
      if (n === undefined) return;
      e.preventDefault();
      choisir(tabs[(n + tabs.length) % tabs.length], true);
    });
  });

  // Statistiques : clics utiles (appel, itinéraire, e-mail, Facebook, avis, réservation d'événement, carte consultée)
  function suivre(e, x) {
    var d = new URLSearchParams({ e: e, x: x || '' });
    try { if (navigator.sendBeacon && navigator.sendBeacon('track.php', d)) return; } catch (err) {}
    try { fetch('track.php', { method: 'POST', body: d, keepalive: true }); } catch (err) {}
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]'); if (!a) return;
    var h = a.getAttribute('href');
    if (a.dataset.evt) suivre('evt', a.dataset.evt);
    else if (h.indexOf('tel:') === 0) suivre('tel');
    else if (h.indexOf('mailto:') === 0) suivre('mail');
    else if (/google\.[a-z]+\/maps/.test(h)) suivre('itin');
    else if (/facebook\.com/.test(h)) suivre('fb');
    else if (/share\.google/.test(h)) suivre('avis');
  });
  tabs.forEach(function (t) { t.addEventListener('click', function () { suivre('carte', t.textContent.trim()); }); });

  if (!('IntersectionObserver' in window)) {
    document.querySelectorAll('.reveal').forEach(function (el) { el.classList.add('vu'); });
    return;
  }

  // Apparition des blocs
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (e.isIntersecting) { e.target.classList.add('vu'); io.unobserve(e.target); }
    });
  }, { rootMargin: '0px 0px -6% 0px', threshold: 0.1 });
  document.querySelectorAll('.reveal').forEach(function (el) { io.observe(el); });

  // Lien de menu actif selon la section affichée
  var liens = {};
  nav.querySelectorAll('.menu a[href^="#"]').forEach(function (a) { liens[a.getAttribute('href').slice(1)] = a; });
  var spy = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      var a = liens[e.target.id];
      if (a) a.classList.toggle('actif', e.isIntersecting);
    });
  }, { rootMargin: '-45% 0px -50% 0px' });
  Object.keys(liens).forEach(function (id) { var s = document.getElementById(id); if (s) spy.observe(s); });
})();
