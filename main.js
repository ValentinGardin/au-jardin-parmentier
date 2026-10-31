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

  // Menu de la semaine : « Cette semaine » / « Semaine suivante » (quand le menu suivant est publié en avance)
  var semTabs = Array.prototype.slice.call(document.querySelectorAll('.choix-semaine [role="tab"]'));
  semTabs.forEach(function (t, i) {
    t.addEventListener('click', function () {
      semTabs.forEach(function (b) {
        var on = b === t;
        b.setAttribute('aria-selected', String(on)); b.tabIndex = on ? 0 : -1;
        var pnl = document.getElementById(b.getAttribute('aria-controls'));
        pnl.hidden = !on;
        if (on) pnl.querySelectorAll('.reveal').forEach(function (el) { el.classList.add('vu'); });
      });
    });
    t.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      var n = semTabs[(i + (e.key === 'ArrowRight' ? 1 : -1) + semTabs.length) % semTabs.length];
      n.click(); n.focus();
    });
  });

  // Lettre « menu de la semaine » : inscription sans quitter la page
  var lettre = document.querySelector('.lettre-form');
  if (lettre && window.fetch) lettre.addEventListener('submit', function (e) {
    e.preventDefault();
    var msg = lettre.querySelector('.lettre-msg');
    function erreur(t) { msg.textContent = t; msg.hidden = false; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(lettre.email.value.trim())) { lettre.email.focus(); return erreur('Indiquez une adresse e-mail valide.'); }
    if (!lettre.accord.checked) return erreur('Cochez la case pour accepter de recevoir le menu.');
    msg.hidden = true; lettre.classList.add('envoi');
    fetch(lettre.action, { method: 'POST', body: new FormData(lettre), headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) throw new Error(j.erreur);
        var p = lettre.prenom.value.trim(), ok = document.createElement('p');
        ok.className = 'lettre-ok'; ok.setAttribute('role', 'status');
        ok.textContent = 'C’est noté' + (p ? ', ' + p : '') + ' ! Vous recevrez le prochain menu lundi.';
        lettre.replaceWith(ok);
        suivre('lettre');
      })
      .catch(function (er) { lettre.classList.remove('envoi'); erreur(er.message || 'L’inscription n’a pas fonctionné, réessayez.'); });
  });

  // Réservation en ligne : 1. le plat (jour + quantités)  2. les coordonnées  3. confirmation
  var rs = document.getElementById('rs');
  if (rs) (function () {
    var D = JSON.parse(document.getElementById('rs-donnees').textContent);
    var corps = document.getElementById('rs-corps'), pied = document.getElementById('rs-pied');
    var etat = { etape: 1, date: '', plat: 1, special: 0, autres: {}, evt: null, items: {}, t: 0 }, client = { prenom: '', nom: '', tel: '', email: '', note: '', lettre: false, memo: true }, avant = null;
    try { var m = JSON.parse(localStorage.getItem('jp-client') || 'null'); if (m) { client.prenom = m.prenom || ''; client.nom = m.nom || ''; client.tel = m.tel || ''; client.email = m.email || ''; } } catch (e) {}
    function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function num(p) { var x = String(p || '').replace(',', '.').match(/\d+(\.\d+)?/); return x ? +x[0] : 0; }
    function euros(n) { return n.toFixed(2).replace('.', ',') + ' €'; }
    function jour() { return D.jours.filter(function (j) { return j.date === etat.date; })[0] || D.jours[0]; }
    // Plats des jours d'avant (réservables en ligne eux aussi), mis de côté pour le jour du retrait
    // (même semaine seulement : un plat n'attend pas plusieurs jours d'un week-end à l'autre)
    function lundi(d) { var x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() - (x.getDay() + 6) % 7); return x.toISOString().slice(0, 10); }
    function avant_() { var l = lundi(jour().date); return D.jours.filter(function (j) { return j.date < jour().date && j.date >= l; }); }
    function autres() { return avant_().filter(function (j) { return etat.autres[j.date]; }); }
    // Mode « événement » : les produits de l'événement, à récupérer le jour de l'événement
    function evt() { return etat.evt === null ? null : (D.evts || []).filter(function (e) { return e.i === etat.evt; })[0]; }
    function evtJour() { var e = evt(); return e ? (e.jours.filter(function (x) { return x.date === etat.evtDate; })[0] || e.jours[0]) : null; }
    function total() { var j = jour(), e = evt();
      if (e) return e.items.reduce(function (t, it) { return t + (it.cat ? 0 : num(it.prix) * (etat.items[it.k] || 0)); }, 0); return num(j.plat.prix) * etat.plat + (j.special ? num(j.special.prix) * etat.special : 0) + autres().reduce(function (t, a) { return t + num(a.plat.prix) * etat.autres[a.date]; }, 0); }
    function nb() { if (evt()) return Object.keys(etat.items).reduce(function (t, k) { return t + etat.items[k]; }, 0); return etat.plat + (jour().special ? etat.special : 0) + autres().reduce(function (t, a) { return t + etat.autres[a.date]; }, 0); }
    function getQ(k) { return k.indexOf('e:') === 0 ? (etat.items[k.slice(2)] || 0) : k.indexOf('a:') === 0 ? (etat.autres[k.slice(2)] || 0) : etat[k]; }
    function setQ(k, v) { if (k.indexOf('e:') === 0) etat.items[k.slice(2)] = v; else if (k.indexOf('a:') === 0) etat.autres[k.slice(2)] = v; else etat[k] = v; }
    function court(d) { var x = new Date(d + 'T12:00:00'); return { j: x.toLocaleDateString('fr-FR', { weekday: 'short' }).replace('.', ''), n: x.getDate(), m: x.toLocaleDateString('fr-FR', { month: 'short' }).replace('.', '') }; }
    function qte(nom, v, min) {
      return '<div class="rs-qte" role="group" aria-label="Quantité"><button type="button" data-rs="moins" data-q="' + nom + '" aria-label="Un de moins"' + (v <= min ? ' disabled' : '') + '>−</button><output aria-live="polite">' + v + '</output><button type="button" data-rs="plus" data-q="' + nom + '" aria-label="Un de plus"' + (v >= 20 ? ' disabled' : '') + '>+</button></div>';
    }
    function entete(n, titre) {
      document.getElementById('rs-etape').textContent = n <= 2 ? 'Étape ' + n + ' sur 2' : 'C’est réservé';
      document.getElementById('rs-titre').textContent = titre;
      document.getElementById('rs-barre').style.width = (n === 1 ? 50 : 100) + '%';
      rs.dataset.etape = n;
    }
    function etape1Evt(e) {
      entete(1, e.titre);
      var jr = evtJour();
      // plusieurs jours (ex. 24 et 25 décembre) : le client choisit son jour de retrait
      corps.innerHTML = (e.jours.length > 1 ? '<p class="rs-q">Pour quel jour ?</p><div class="rs-jours rs-jours-evt" role="radiogroup" aria-label="Jour de retrait">' + e.jours.map(function (x) {
          var c = court(x.date);
          return '<button type="button" role="radio" aria-checked="' + (x.date === jr.date) + '" data-rs="evt-jour" data-d="' + x.date + '"><span>' + c.j + '</span><b>' + c.n + '</b><small>' + c.m + '</small></button>';
        }).join('') + '</div>' : '') +
        '<p class="rs-evt-quand" style="--c:' + esc(e.couleur) + '">' + '<b>À récupérer ' + esc(jr.libelle) + '</b><span>à la boutique, 9 place Parmentier</span></p>' +
        '<p class="rs-q">Votre commande</p>' + e.items.map(function (it) {
          if (it.cat) return '<p class="rs-cat">' + esc(it.nom) + '</p>';
          var q = etat.items[it.k] || 0;
          return '<div class="rs-plat' + (q ? ' choisi' : '') + (it.epuise ? ' rs-epuise' : '') + '"><div class="rs-plat-txt">' +
            (it.epuise ? '<span class="rs-tag rs-tag-epuise">Épuisé</span>' : it.forte ? '<span class="rs-tag"><em class="rs-forte">Forte demande · réservez vite</em></span>' : '') +
            '<strong>' + esc(it.nom) + '</strong></div><div class="rs-plat-fin">' + (it.prix ? '<span class="rs-px">' + esc(it.prix) + '</span>' : '') +
            (it.epuise ? '<span class="rs-plus-dispo">Plus disponible</span>' : qte('e:' + it.k, q, 0)) + '</div></div>';
        }).join('') +
        '<p class="rs-info">Une question ? Appelez-nous au <a href="' + D.telHref + '">' + esc(D.tel) + '</a>.</p>';
      pied.innerHTML = '<div class="rs-total"><span>Total</span><b>' + (total() ? euros(total()) : '–') + '</b><small>à régler sur place</small></div>' +
        '<button type="button" class="btn btn-violet btn-lg" data-rs="suite"' + (nb() ? '' : ' disabled') + '>Continuer</button>';
    }
    function etape1() {
      if (evt()) return etape1Evt(evt());
      var j = jour();
      entete(1, 'Réserver mon plat');
      var t0 = new Date(), auj = t0.getFullYear() + '-' + ('0' + (t0.getMonth() + 1)).slice(-2) + '-' + ('0' + t0.getDate()).slice(-2);
      var semAuj = lundi(auj), semSuiv = (function () { var x = new Date(semAuj + 'T12:00:00'); x.setDate(x.getDate() + 7); return x.toISOString().slice(0, 10); })();
      // jours regroupés par semaine, avec un petit titre et un trait fin entre deux semaines
      var groupes = [];
      D.jours.forEach(function (x) { var l = lundi(x.date), g = groupes[groupes.length - 1]; if (!g || g.l !== l) groupes.push(g = { l: l, j: [] }); g.j.push(x); });
      corps.innerHTML = '<p class="rs-q">Pour quel jour ?</p><div class="rs-jours" role="radiogroup" aria-label="Jour de retrait">' + groupes.map(function (g) {
          var titre = g.l === semAuj ? 'Cette semaine' : g.l === semSuiv ? 'Semaine prochaine' : 'Semaine du ' + court(g.l).n + ' ' + court(g.l).m;
          return '<div class="rs-sem"><span class="rs-sem-t">' + titre + '</span><div class="rs-sem-j">' + g.j.map(function (x) {
            var c = court(x.date);
            return '<button type="button" role="radio" aria-checked="' + (x.date === j.date) + '" data-rs="jour" data-d="' + x.date + '"' + (x.forte ? ' class="forte" title="Forte demande"' : '') + '><span>' + c.j + '</span><b>' + c.n + '</b><small>' + c.m + '</small></button>';
          }).join('') + '</div></div>';
        }).join('') + '</div>' +
        '<p class="rs-q">Votre commande <span>pour ' + esc(j.libelle) + '</span></p>' +
        '<div class="rs-plat' + (etat.plat ? ' choisi' : '') + '"><div class="rs-plat-txt"><span class="rs-tag">Plat du jour' + (j.forte ? ' <em class="rs-forte">Forte demande · réservez vite</em>' : '') + '</span><strong>' + esc(j.plat.nom) + '</strong>' + (j.plat.desc ? '<small>' + esc(j.plat.desc) + '</small>' : '') + '</div>' +
          '<div class="rs-plat-fin">' + (j.plat.prix ? '<span class="rs-px">' + esc(j.plat.prix) + '</span>' : '') + qte('plat', etat.plat, 0) + '</div></div>' +
        (j.special ? '<div class="rs-plat rs-offre' + (etat.special ? ' choisi' : '') + '"><div class="rs-plat-txt"><span class="rs-tag">' + esc(j.special.titre || 'Offre spéciale') + '</span><strong>' + esc(j.special.nom) + '</strong>' + (j.special.lignes.length ? '<small>' + esc(j.special.lignes.join(' · ')) + '</small>' : '') + '</div>' +
          '<div class="rs-plat-fin">' + (j.special.prix ? '<span class="rs-px">' + esc(j.special.prix) + '</span>' : '') + qte('special', etat.special, 0) + '</div></div>' : '') +
        blocAutres() +
        '<p class="rs-info">Pour aujourd’hui, appelez-nous au <a href="' + D.telHref + '">' + esc(D.tel) + '</a> : on fait notre possible.</p>';
      pied.innerHTML = '<div class="rs-total"><span>Total</span><b>' + (total() ? euros(total()) : '–') + '</b><small>à régler sur place</small></div>' +
        '<button type="button" class="btn btn-violet btn-lg" data-rs="suite"' + (nb() ? '' : ' disabled') + '>Continuer</button>';
    }
    function blocAutres() {
      var l = avant_();
      if (!l.length) return '';
      if (!etat.voirAutres && !autres().length) return '<button type="button" class="rs-ajout" data-rs="autres"><span aria-hidden="true">+</span><b>Ajouter un plat d’un autre jour</b><small>' + l.map(function (a) { return esc(a.plat.nom); }).join(' · ') + '</small></button>';
      return '<p class="rs-q rs-q2">Plats des jours d’avant <span>on vous les met de côté pour ' + esc(jour().libelle) + '</span></p>' + l.map(function (a) {
        var q = etat.autres[a.date] || 0;
        return '<div class="rs-plat rs-autre' + (q ? ' choisi' : '') + '"><div class="rs-plat-txt"><span class="rs-tag">Plat du ' + esc(a.libelle) + '</span><strong>' + esc(a.plat.nom) + '</strong>' + (a.plat.desc ? '<small>' + esc(a.plat.desc) + '</small>' : '') + '</div>' +
          '<div class="rs-plat-fin">' + (a.plat.prix ? '<span class="rs-px">' + esc(a.plat.prix) + '</span>' : '') + qte('a:' + a.date, q, 0) + '</div></div>';
      }).join('');
    }
    function recap() {
      var j = jour(), l = [], e = evt();
      if (e) return e.items.filter(function (it) { return !it.cat && etat.items[it.k]; }).map(function (it) { return etat.items[it.k] + ' × ' + it.nom; });
      if (etat.plat) l.push(etat.plat + ' × ' + j.plat.nom);
      if (j.special && etat.special) l.push(etat.special + ' × ' + j.special.nom);
      autres().forEach(function (a) { l.push(etat.autres[a.date] + ' × ' + a.plat.nom + ' (plat du ' + a.jour.toLowerCase() + ')'); });
      return l;
    }
    function etape2() {
      var j = evt() ? { libelle: evtJour().libelle } : jour();
      entete(2, 'Vos coordonnées');
      corps.innerHTML = '<button type="button" class="rs-recap" data-rs="retour"><span><b>' + esc(recap().join(', ')) + '</b><small>' + esc(j.libelle) + (total() ? ' · ' + euros(total()) : '') + '</small></span><em>Modifier</em></button>' +
        '<form class="rs-form" id="rs-form" novalidate>' +
        '<div class="rs-2"><label class="rs-champ"><span>Prénom</span><input name="prenom" autocomplete="given-name" required value="' + esc(client.prenom) + '"></label>' +
        '<label class="rs-champ"><span>Nom</span><input name="nom" autocomplete="family-name" required value="' + esc(client.nom) + '"></label></div>' +
        '<label class="rs-champ"><span>Téléphone <em>conseillé, pour vous joindre en cas d’imprévu</em>' + (D.smsPret ? '' : ' <i class="bientot">Rappel par SMS : bientôt</i>') + '</span><input name="tel" type="tel" inputmode="tel" autocomplete="tel" value="' + esc(client.tel) + '" placeholder="06 12 34 56 78"></label>' +
        '<label class="rs-champ"><span>E-mail <em>facultatif</em>' + (D.mailPret ? '<em>, pour recevoir la confirmation</em>' : ' <i class="bientot">Confirmation par e-mail : bientôt</i>') + '</span><input name="email" type="email" autocomplete="email" value="' + esc(client.email) + '"></label>' +
        '<label class="rs-champ rs-note"><span>Un petit mot pour nous ? <em>facultatif</em></span><textarea name="note" rows="2" maxlength="500" placeholder="ex. Je passe vers 12h15">' + esc(client.note) + '</textarea></label>' +
        '<div class="rs-idees" aria-label="Suggestions">' + ['Je passe vers 12h', 'Je passe en fin de journée', 'Sans sauce, svp', 'Allergie : ', 'C’est pour offrir'].map(function (t) {
          return '<button type="button" data-rs="idee" data-t="' + esc(t) + '">' + esc(t.replace(/ : $/, '…')) + '</button>';
        }).join('') + '</div>' +
        '<label class="rs-case rs-case-lettre"' + (client.email ? '' : ' hidden') + '><input type="checkbox" name="lettre"' + (client.lettre ? ' checked' : '') + '><span>Recevoir aussi le menu de la semaine chaque lundi par e-mail</span></label>' +
        '<label class="rs-case"><input type="checkbox" name="memo"' + (client.memo ? ' checked' : '') + '><span>Me reconnaître la prochaine fois sur cet appareil</span></label>' +
        '<div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"><input name="site_web" tabindex="-1" autocomplete="off"></div>' +
        '<p class="rs-erreur" role="alert" hidden></p></form>';
      pied.innerHTML = '<button type="button" class="btn btn-ligne rs-retour" data-rs="retour" aria-label="Retour">←</button>' +
        '<button type="submit" form="rs-form" class="btn btn-violet btn-lg">Confirmer ma réservation</button><p class="rs-paiement">Paiement sur place, au retrait. Aucun paiement en ligne.</p>';
      var f = document.getElementById('rs-form');
      var vide = ['prenom', 'nom', 'tel'].filter(function (k) { return !f[k].value; })[0];
      setTimeout(function () { (vide ? f[vide] : pied.querySelector('.btn-violet')).focus(); }, 60);
      f.addEventListener('input', function (e) {
        if (e.target.name === 'email') f.querySelector('.rs-case-lettre').hidden = !e.target.value.trim();
        if (e.target.name === 'tel' && etat.sansTel) { etat.sansTel = false; pied.querySelector('.btn-violet').textContent = 'Confirmer ma réservation'; }
        e.target.removeAttribute('aria-invalid');
        f.querySelector('.rs-erreur').hidden = true;
      });
      f.addEventListener('submit', envoyer);
    }
    function erreur(t, champ) {
      var f = document.getElementById('rs-form'), p = f.querySelector('.rs-erreur');
      p.textContent = t; p.hidden = false;
      if (champ && f[champ]) { f[champ].setAttribute('aria-invalid', 'true'); f[champ].focus(); }
    }
    function envoyer(e) {
      e.preventDefault();
      var f = document.getElementById('rs-form');
      ['prenom', 'nom', 'tel', 'email', 'note'].forEach(function (k) { client[k] = f[k].value.trim(); });
      client.lettre = f.lettre.checked; client.memo = f.memo.checked;
      if (!client.prenom) return erreur('Indiquez votre prénom.', 'prenom');
      if (!client.nom) return erreur('Indiquez votre nom.', 'nom');
      if (client.tel && client.tel.replace(/\D/g, '').replace(/^33/, '0').length !== 10) return erreur('Ce numéro de téléphone semble incomplet (10 chiffres).', 'tel');
      // téléphone conseillé mais pas obligatoire : on le rappelle une fois, puis on laisse confirmer sans
      if (!client.tel && !etat.sansTel) {
        etat.sansTel = true;
        var bt = pied.querySelector('.btn-violet'); bt.textContent = 'Confirmer sans téléphone';
        return erreur('Sans numéro, nous ne pourrons pas vous prévenir en cas d’imprévu. Vous pouvez l’ajouter, ou confirmer sans.', 'tel');
      }
      if (client.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(client.email)) return erreur('Cette adresse e-mail ne semble pas valide.', 'email');
      var b = pied.querySelector('.btn-violet'); b.disabled = true; b.textContent = 'Envoi…';
      fetch('commander', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(evt() ? { evt: etat.evt, date: evtJour().date, items: etat.items, prenom: client.prenom, nom: client.nom, tel: client.tel, email: client.email, note: client.note, lettre: client.lettre, site_web: f.site_web.value, t: etat.t } : { date: jour().date, plat: etat.plat, special: jour().special ? etat.special : 0, autres: autres().reduce(function (o, a) { o[a.date] = etat.autres[a.date]; return o; }, {}), prenom: client.prenom, nom: client.nom, tel: client.tel, email: client.email, note: client.note, lettre: client.lettre, site_web: f.site_web.value, t: etat.t }) })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { if (j.jours) { D.jours = j.jours; } throw j; }
          try { if (client.memo) localStorage.setItem('jp-client', JSON.stringify({ prenom: client.prenom, nom: client.nom, tel: client.tel, email: client.email })); else localStorage.removeItem('jp-client'); } catch (er) {}
          suivre('resa-ok');
          etape3(j);
        })
        .catch(function (er) { b.disabled = false; b.textContent = 'Confirmer ma réservation'; erreur(er.erreur || 'Pas de connexion internet ? Réessayez, ou appelez-nous au ' + D.tel + '.', er.champ); });
    }
    function etape3(j) {
      entete(3, 'Merci ' + client.prenom + ' !');
      corps.innerHTML = '<div class="rs-ok"><div class="rs-coche" aria-hidden="true"><svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="m15 27 7 7 15-15"/></svg></div>' +
        '<p class="rs-ok-titre">C’est réservé !</p><p class="rs-num">Commande n° <b>' + j.num + '</b></p>' +
        '<p>' + esc(recap().join(', ')) + '<br>à récupérer <b>' + esc(j.quand) + '</b> à la boutique,<br>9, place Parmentier à Montdidier.</p>' +
        (j.total ? '<p class="rs-ok-px">' + euros(j.total) + ' à régler sur place</p>' : '') +
        '<p class="rs-info">' + (j.email && D.mailPret ? 'Un e-mail de confirmation vient de vous être envoyé. ' : '') + 'Un changement ? Appelez-nous au <a href="' + D.telHref + '">' + esc(D.tel) + '</a>.</p></div>';
      pied.innerHTML = '<button type="button" class="btn btn-violet btn-lg" data-rs="fermer">Parfait, à bientôt !</button>';
      pied.querySelector('button').focus();
      etat.fini = true;
    }
    function ouvrir(date, offre, iEvt) {
      if (etat.fini) { etat = { etape: 1, date: '', plat: 1, special: 0, autres: {}, evt: null, items: {}, t: 0 }; client.note = ''; }
      etat.evt = iEvt === undefined ? null : iEvt;
      if (etat.evt !== null) {
        etat.items = {};
        var e = evt(), dispo = e ? e.items.filter(function (it) { return !it.epuise && !it.cat; }) : [];
        etat.evtDate = e ? e.jours[0].date : '';
        if (dispo.length === 1) etat.items[dispo[0].k] = 1;   // un seul produit : déjà dans la commande
      }
      if (offre) { etat.plat = 0; etat.special = 1; }
      etat.date = date && D.jours.some(function (j) { return j.date === date; }) ? date : (etat.date || (D.jours[0] ? D.jours[0].date : ''));
      etat.t = Math.floor(Date.now() / 1000);
      avant = document.activeElement;
      rs.hidden = false; document.body.classList.add('rs-ouvert');
      etape1();
      setTimeout(function () { var c = corps.querySelector('[aria-checked="true"]'); if (c) c.focus(); }, 60);
      suivre('resa');
    }
    function ouvrirEvt(i) { ouvrir(null, false, i); }
    function fermer() {
      rs.hidden = true; document.body.classList.remove('rs-ouvert');
      if (avant && avant.focus) avant.focus();
    }
    document.addEventListener('click', function (e) {
      var o = e.target.closest('[data-reserver]');
      if (o) { e.preventDefault(); ouvrir(o.dataset.reserver, 'offre' in o.dataset); return; }
      var oe = e.target.closest('[data-reserver-evt]');
      if (oe) { e.preventDefault(); ouvrirEvt(+oe.dataset.reserverEvt); return; }
      var b = e.target.closest('[data-rs]'); if (!b || !rs.contains(b)) return;
      var a = b.dataset.rs;
      if (a === 'fermer') fermer();
      else if (a === 'evt-jour') { etat.evtDate = b.dataset.d; etape1(); corps.querySelector('[data-d="' + etat.evtDate + '"]').focus(); }
      else if (a === 'jour') {
        etat.date = b.dataset.d; if (!jour().special) etat.special = 0;
        Object.keys(etat.autres).forEach(function (k) { if (k >= etat.date) delete etat.autres[k]; });
        if (!nb()) etat.plat = 1; etape1(); corps.querySelector('[data-d="' + etat.date + '"]').focus(); }
      else if (a === 'plus' || a === 'moins') {
        setQ(b.dataset.q, Math.max(0, Math.min(20, getQ(b.dataset.q) + (a === 'plus' ? 1 : -1))));
        var y = corps.scrollTop; etape1(); corps.scrollTop = y;
        var n = corps.querySelector('[data-rs="' + a + '"][data-q="' + b.dataset.q + '"]'); if (n && !n.disabled) n.focus();
      }
      else if (a === 'autres') { etat.voirAutres = true; var y2 = corps.scrollTop; etape1(); corps.scrollTop = y2; var c1 = corps.querySelector('.rs-autre [data-rs="plus"]'); if (c1) c1.focus(); }
      else if (a === 'idee') {
        var ta = corps.querySelector('textarea[name=note]'), v = b.dataset.t;
        ta.value = v;   // la suggestion remplace le texte déjà écrit
        ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length);
      }
      else if (a === 'suite') etape2();
      else if (a === 'retour') { var f = document.getElementById('rs-form'); if (f) ['prenom', 'nom', 'tel', 'email', 'note'].forEach(function (k) { client[k] = f[k].value; }); etape1(); }
    });
    document.addEventListener('keydown', function (e) {
      if (rs.hidden) return;
      if (e.key === 'Escape') fermer();
      if (e.key === 'Tab') {   // le clavier reste dans la fenêtre
        var l = rs.querySelectorAll('button:not([disabled]), input:not([type=hidden]):not([tabindex="-1"]), textarea, a[href]');
        l = Array.prototype.filter.call(l, function (x) { return x.offsetParent !== null; });
        if (!l.length) return;
        if (e.shiftKey && document.activeElement === l[0]) { e.preventDefault(); l[l.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === l[l.length - 1]) { e.preventDefault(); l[0].focus(); }
      }
    });
    // Lien direct : https://…/#reserver ouvre la réservation (pratique sur les réseaux sociaux)
    if (location.hash === '#reserver') { if (D.jours.length) ouvrir(); else if (D.evts.length) ouvrirEvt(D.evts[0].i); }
  })();

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
