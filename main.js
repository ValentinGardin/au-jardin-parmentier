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
    else if (/instagram\.com/.test(h)) suivre('insta');
    else if (/share\.google/.test(h)) suivre('avis');
  });
  tabs.forEach(function (t) { t.addEventListener('click', function () { suivre('carte', t.textContent.trim()); }); });

  // Cookies : la carte Google Maps n'est chargée qu'avec l'accord du visiteur (choix gardé 6 mois)
  function lireChoix() {
    try { var v = JSON.parse(localStorage.getItem('jp-cookies') || 'null'); return v && Date.now() - v.t < 182 * 864e5 ? v.c : null; } catch (err) { return null; }
  }
  var bandeau = document.getElementById('cookies');
  function appliquer(c) {
    document.querySelectorAll('iframe[data-src]').forEach(function (f) {
      var attente = f.parentNode.querySelector('.plan-attente');
      if (c === 'oui') {
        f.hidden = false; if (attente) attente.hidden = true;
        // la carte (lourde) ne se charge qu'à l'approche de la section Contact
        if (!f.src) {
          if (!('IntersectionObserver' in window)) { f.src = f.dataset.src; return; }
          var o = new IntersectionObserver(function (es) { if (es[0].isIntersecting) { f.src = f.dataset.src; o.disconnect(); } }, { rootMargin: '400px 0px' });
          o.observe(f);
        }
      }
      else { f.removeAttribute('src'); f.hidden = true; if (attente) attente.hidden = false; }
    });
  }
  function choisir_(c) {
    try { localStorage.setItem('jp-cookies', JSON.stringify({ c: c, t: Date.now() })); } catch (err) {}
    bandeau.hidden = true; document.body.classList.remove('avec-cookies'); appliquer(c);
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-cookies]'); if (!b) return;
    var a = b.dataset.cookies;
    if (a === 'gerer') { bandeau.hidden = false; document.body.classList.add('avec-cookies'); bandeau.querySelector('.btn-violet').focus(); }
    else choisir_(a === 'accepter' ? 'oui' : 'non');
  });
  var choix = lireChoix();
  appliquer(choix);
  if (!choix && bandeau) { bandeau.hidden = false; document.body.classList.add('avec-cookies'); }

  // Fenêtre d'accueil de l'événement : une fois tous les 3 jours par visiteur
  var pop = document.getElementById('pop');
  if (pop) {
    var cle = 'jp-pop-' + pop.dataset.cle, vu = 0, avant = null;
    try { vu = +localStorage.getItem(cle) || 0; } catch (err) {}
    var ouvrirPop = function () {
      avant = document.activeElement;
      pop.hidden = false; document.body.classList.add('pop-ouvert');
      requestAnimationFrame(function () { pop.classList.add('visible'); });
      pop.querySelector('.pop-fermer').focus();
      try { localStorage.setItem(cle, String(Date.now())); } catch (err) {}
    };
    var fermerPop = function () {
      pop.classList.remove('visible'); document.body.classList.remove('pop-ouvert');
      setTimeout(function () { pop.hidden = true; }, 250);
      if (avant && avant.focus) avant.focus();
    };
    pop.addEventListener('click', function (e) { if (e.target === pop || e.target.closest('[data-pop-fermer]')) fermerPop(); });
    document.addEventListener('keydown', function (e) {
      if (pop.hidden) return;
      if (e.key === 'Escape') fermerPop();
      if (e.key === 'Tab') {   // garde le focus dans la fenêtre
        var f = pop.querySelectorAll('a[href],button'), p = f[0], d = f[f.length - 1];
        if (e.shiftKey && document.activeElement === p) { e.preventDefault(); d.focus(); }
        else if (!e.shiftKey && document.activeElement === d) { e.preventDefault(); p.focus(); }
      }
    });
    // Ouverture au premier défilement (ou après 6 s sans action) : la page s'affiche d'abord entièrement
    if (Date.now() - vu > 3 * 864e5 && location.hash === '') {
      var minuterie, ouvert = false;
      var declencher = function () {
        if (ouvert) return; ouvert = true;
        clearTimeout(minuterie); window.removeEventListener('scroll', auDefilement);
        setTimeout(ouvrirPop, 400);
      };
      var auDefilement = function () { if (window.scrollY > 150) declencher(); };
      window.addEventListener('scroll', auDefilement, { passive: true });
      minuterie = setTimeout(declencher, 6000);
    }
  }

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
