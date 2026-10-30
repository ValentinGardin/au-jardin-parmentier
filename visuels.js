// Visuels du menu de la semaine, dessinés dans le navigateur (aucun logiciel nécessaire) :
// A4 (impression), publication 1080×1350, story 1080×1920, et story vidéo de 15 s.
// Tout est mis en page sur une largeur logique de 1080 px puis agrandi pour l'A4 (2480 px, 300 dpi).
window.Visuels = (function () {
  'use strict';

  var VIOLET = '#700954', ORANGE = '#d9641e', ENCRE = '#1d171b', GRIS = '#5f575d', CREME = '#f6e3cf';
  var FORMATS = {
    a4:    { h: 1527.6, sortie: 2480, theme: 'clair', haut: 90,  bas: 80,  nom: 'a4' },
    post:  { h: 1350, sortie: 1080, theme: 'clair', haut: 70,  bas: 60,  nom: 'publication' },
    story: { h: 1920, sortie: 1080, theme: 'violet', haut: 210, bas: 250, nom: 'story', taille: 1.18 }
  };
  var DUREE = 15;
  var MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  var imgs = {}, pret = null;

  function charger() {
    if (pret) return pret;
    function img(n, src) {
      return new Promise(function (ok) { var i = new Image(); i.onload = function () { imgs[n] = i; ok(); }; i.onerror = function () { ok(); }; i.src = src; });
    }
    var polices = document.fonts ? Promise.all(['800 60px "Baloo 2"', '700 60px "Baloo 2"', '600 60px "Baloo 2"', '400 30px Inter', '500 30px Inter', '600 30px Inter', '700 30px Inter', 'italic 700 30px Inter']
      .map(function (f) { return document.fonts.load(f).catch(function () {}); })) : Promise.resolve();
    pret = Promise.all([img('symV', 'img/logo/symbole-violet.svg'), img('symB', 'img/logo/symbole-blanc.svg'), polices]);
    return pret;
  }

  // ---------- Textes ----------
  function dateIso(s) { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || ''); return m ? new Date(+m[1], m[2] - 1, +m[3]) : null; }
  function periode(du, au) {
    var a = dateIso(du), b = dateIso(au), j = function (d) { return d.getDate() === 1 ? '1er' : d.getDate(); };
    if (!a) return '';
    if (!b || b < a) return 'à partir du ' + j(a) + ' ' + MOIS[a.getMonth()];
    return 'semaine du ' + j(a) + (a.getMonth() === b.getMonth() ? '' : ' ' + MOIS[a.getMonth()]) + ' au ' + j(b) + ' ' + MOIS[b.getMonth()] + ' ' + b.getFullYear();
  }
  function couper(ctx, txt, police, largeur) {
    ctx.font = police;
    var mots = String(txt || '').split(/\s+/).filter(Boolean), out = [], l = '';
    mots.forEach(function (m) {
      var essai = l ? l + ' ' + m : m;
      if (ctx.measureText(essai).width > largeur && l) { out.push(l); l = m; } else l = essai;
    });
    if (l) out.push(l);
    return out;
  }
  function ajuster(ctx, txt, poids, famille, taille, largeur) {
    ctx.font = poids + ' ' + taille + 'px ' + famille;
    var w = ctx.measureText(txt).width;
    return w > largeur ? Math.floor(taille * largeur / w) : taille;
  }

  // ---------- Mise en page : liste d'éléments à dessiner, chacun avec son moment d'apparition ----------
  function miseEnPage(ctx, fmt, d) {
    var F = FORMATS[fmt], W = 1080, H = F.h, P = 80, violet = F.theme === 'violet';
    var C = violet
      ? { fond: VIOLET, texte: '#fff', jour: CREME, doux: 'rgba(255,255,255,.78)', trait: 'rgba(255,255,255,.28)', prix: '#fff', special: '#f4c08f', sym: 'symB' }
      : { fond: '#fffaf5', texte: ENCRE, jour: VIOLET, doux: GRIS, trait: VIOLET, prix: VIOLET, special: ORANGE, sym: 'symV' };
    var conges = d.conges && d.conges.actif ? d.conges : null;   // semaine fermée : un message remplace le menu
    var jours = conges ? [] : d.jours.filter(function (j) { return j.nom || j.ferme; });
    var sp = !conges && d.special && (d.special.nom || (d.special.lignes || []).filter(Boolean).length) ? d.special : null;
    var mots = String(d.titre || 'Inspirations de la semaine').trim().split(/\s+/);
    var t1 = mots.shift().toUpperCase(), t2 = mots.join(' ').toUpperCase();

    function construire(f, air) {
      var els = [], y = 0, t = 0;
      function el(h, at, anim, dessin) { els.push({ y: y, h: h, at: at, anim: anim, dessin: dessin }); y += h; }
      // En-tête
      var hs = Math.round(96 * f);
      el(hs + 26 * f, 0.15, 'pop', function (c, y0) { var i = imgs[C.sym]; if (i) c.drawImage(i, W / 2 - hs * 0.336, y0, hs * 0.672, hs); });
      var tt = ajuster(ctx, t1, 800, '"Baloo 2"', Math.round(150 * f), W - 2 * P);
      el(tt * 0.92, 0.45, 'monte', function (c, y0) { c.font = '800 ' + tt + 'px "Baloo 2"'; c.fillStyle = violet ? '#fff' : VIOLET; c.textAlign = 'center'; c.textBaseline = 'top'; c.fillText(t1, W / 2, y0 - tt * 0.12); });
      if (t2) {
        var ts = ajuster(ctx, t2, 600, '"Baloo 2"', Math.round(64 * f), W - 2 * P);
        el(ts * 1.15, 0.7, 'monte', function (c, y0) { c.font = '600 ' + ts + 'px "Baloo 2"'; c.fillStyle = violet ? '#fff' : VIOLET; c.textAlign = 'center'; c.textBaseline = 'top'; c.fillText(t2, W / 2, y0); });
      }
      var per = periode(d.du, d.au), tp = Math.round(30 * f);
      y += 14 * f;
      el(tp * 1.5, 0.95, 'fondu', function (c, y0) {
        c.font = '500 ' + tp + 'px Inter'; c.fillStyle = C.doux; c.textAlign = 'center'; c.textBaseline = 'top';
        c.fillText((per ? per + '  ·  ' : '') + 'Au Jardin Parmentier', W / 2, y0);
      });
      y += 34 * f;
      // Jours
      var colJ = Math.round(285 * f), colP = Math.round(170 * f), xPlat = P + colJ, lPlat = W - 2 * P - colJ - colP;
      var pad = Math.round(20 * f + air);
      t = 1.5;
      if (conges) {
        // Semaine fermée : grand titre et message dans un encadré
        var ft = Math.round(70 * f), fm = '500 ' + Math.round(34 * f) + 'px Inter', lw = W - 2 * P - 100;
        var lt = couper(ctx, conges.titre || 'Fermeture pour congés', '800 ' + ft + 'px "Baloo 2"', lw);
        var lm = []; String(conges.message || '').split(/\n/).forEach(function (para) { lm = lm.concat(couper(ctx, para, fm, lw)); });
        var hC = 90 * f + lt.length * ft * 1.02 + (lm.length ? 24 * f + lm.length * 34 * f * 1.45 : 0) + 30 * f;
        el(hC, t, 'pop', function (c, y0) {
          c.fillStyle = violet ? 'rgba(255,255,255,.1)' : '#f6ecf2'; arrondi(c, P, y0, W - 2 * P, hC, 36); c.fill();
          var yy = y0 + 60 * f; c.textAlign = 'center'; c.textBaseline = 'top';
          c.font = '800 ' + ft + 'px "Baloo 2"'; c.fillStyle = violet ? '#fff' : VIOLET;
          lt.forEach(function (l) { c.fillText(l, W / 2, yy); yy += ft * 1.02; });
          yy += 24 * f; c.font = fm; c.fillStyle = violet ? 'rgba(255,255,255,.88)' : GRIS;
          lm.forEach(function (l) { c.fillText(l, W / 2, yy); yy += 34 * f * 1.45; });
        });
        t += 1;
      }
      jours.forEach(function (j, i) {
        var fp = '700 ' + Math.round(31 * f) + 'px Inter', fd = '400 ' + Math.round(26 * f) + 'px Inter';
        var t1 = j.ferme ? (j.motif || 'Fermé') : j.nom, t2 = j.ferme ? j.message : j.desc;
        var lp = couper(ctx, t1, fp, lPlat), ld = couper(ctx, t2, fd, lPlat);
        var hT = lp.length * 31 * f * 1.25 + ld.length * 26 * f * 1.35, hJ = 50 * f;
        var h = Math.max(hT, hJ) + 2 * pad;
        el(h, t + i * 0.55, 'glisse', function (c, y0) {
          c.fillStyle = C.trait; c.fillRect(P, y0, W - 2 * P, violet ? 2 : 4 * f);
          var mid = y0 + h / 2;
          c.textBaseline = 'middle'; c.textAlign = 'left';
          c.font = '800 ' + Math.round(50 * f) + 'px "Baloo 2"'; c.fillStyle = j.ferme ? C.doux : C.jour;
          c.fillText(String(j.jour).toUpperCase(), P + 4, mid + 4 * f);
          var yy = mid - hT / 2;
          c.textBaseline = 'top'; c.fillStyle = j.ferme ? C.jour : C.texte; c.font = fp;
          lp.forEach(function (l) { c.fillText(l, xPlat, yy); yy += 31 * f * 1.25; });
          c.font = fd; c.fillStyle = C.doux;
          ld.forEach(function (l) { c.fillText(l, xPlat, yy + 2); yy += 26 * f * 1.35; });
          if (j.ferme) {
            c.font = '700 ' + Math.round(22 * f) + 'px Inter'; var bw = c.measureText('FERMÉ').width + 34 * f, bh = 44 * f;
            c.strokeStyle = C.doux; c.lineWidth = 2; arrondi(c, W - P - bw, mid - bh / 2, bw, bh, bh / 2); c.stroke();
            c.fillStyle = C.doux; c.textAlign = 'center'; c.textBaseline = 'middle'; c.fillText('FERMÉ', W - P - bw / 2, mid + 1);
          } else if (j.prix) { c.textAlign = 'right'; c.textBaseline = 'middle'; c.font = '700 ' + Math.round(42 * f) + 'px "Baloo 2"'; c.fillStyle = C.prix; c.fillText(j.prix, W - P, mid + 3 * f); }
        });
      });
      t += jours.length * 0.55;
      if (jours.length) el(violet ? 2 : 4 * f, t - 0.3, 'fondu', function (c, y0) { c.fillStyle = C.trait; c.fillRect(P, y0, W - 2 * P, violet ? 2 : 4 * f); });
      // Offre spéciale
      if (sp) {
        y += 34 * f + air;
        var fl = '600 ' + Math.round(27 * f) + 'px Inter', lignes = [];
        (sp.lignes || []).filter(Boolean).forEach(function (l) { lignes = lignes.concat(couper(ctx, l, fl, W - 2 * P - 80)); });
        var tq = sp.titre ? ajuster(ctx, sp.titre.toUpperCase(), 800, '"Baloo 2"', Math.round(48 * f), W - 2 * P - 60) : 0;
        var nom = (sp.nom || '') + (sp.prix ? '   ' + sp.prix : '');
        var fn = 'italic 700 ' + Math.round(30 * f) + 'px Inter', ln = couper(ctx, nom, fn, W - 2 * P - 80);
        var hS = (tq ? tq * 1.1 : 0) + ln.length * 30 * f * 1.35 + 10 * f + lignes.length * 27 * f * 1.5 + (violet ? 70 * f : 10 * f);
        el(hS, t + 0.2, 'pop', function (c, y0) {
          var yy = y0 + (violet ? 35 * f : 0);
          if (violet) { c.strokeStyle = 'rgba(255,255,255,.45)'; c.lineWidth = 3; arrondi(c, P, y0, W - 2 * P, hS, 28); c.stroke(); }
          c.textAlign = 'center'; c.textBaseline = 'top';
          if (tq) { c.font = '800 ' + tq + 'px "Baloo 2"'; c.fillStyle = C.special; c.fillText(sp.titre.toUpperCase(), W / 2, yy); yy += tq * 1.1; }
          c.font = fn; c.fillStyle = C.special;
          ln.forEach(function (l) { c.fillText(l, W / 2, yy); yy += 30 * f * 1.35; });
          yy += 10 * f; c.font = fl; c.fillStyle = violet ? '#fff' : '#3a3338';
          lignes.forEach(function (l) { c.fillText(l, W / 2, yy); yy += 27 * f * 1.5; });
        });
        t += 0.9;
      }
      // Pied : réservation
      y += 40 * f + air;
      var fr = Math.round(30 * f);
      el(fr * 2.6, t + 0.4, violet ? 'pulse' : 'fondu', function (c, y0) {
        c.textAlign = 'center'; c.textBaseline = 'middle';
        var txt = conges ? 'À très bientôt !  ·  09 50 17 39 62' : 'Réservations au 09 50 17 39 62';
        c.font = '700 ' + fr + 'px Inter';
        var w = c.measureText(txt).width + 70 * f, hh = fr * 2.2;
        c.fillStyle = violet ? CREME : VIOLET; arrondi(c, W / 2 - w / 2, y0, w, hh, hh / 2); c.fill();
        c.fillStyle = violet ? VIOLET : '#fff'; c.fillText(txt, W / 2, y0 + hh / 2 + 1);
      });
      el(fr * 1.4, t + 0.6, 'fondu', function (c, y0) {
        c.font = '500 ' + Math.round(24 * f) + 'px Inter'; c.fillStyle = C.doux; c.textAlign = 'center'; c.textBaseline = 'top';
        c.fillText('9 place Parmentier – 80500 Montdidier', W / 2, y0 + 6);
      });
      return { els: els, total: y, fin: t + 1 };
    }

    // Réduit les tailles tant que tout ne tient pas, puis répartit l'espace restant
    var dispo = H - F.haut - F.bas, f = F.taille || 1, L = construire(f, 0);
    while (L.total > dispo && f > 0.5) { f -= 0.03; L = construire(f, 0); }
    var reste = dispo - L.total;
    if (reste > 0) {
      var air = Math.min(reste / (jours.length * 2 + (sp ? 2 : 0) + 2), violet ? 34 : 18);
      L = construire(f, air);
      reste = dispo - L.total;
    }
    L.decal = F.haut + Math.max(0, reste) / 2;
    L.C = C; L.fmt = fmt; L.H = H;
    return L;
  }

  function arrondi(c, x, y, w, h, r) {
    c.beginPath(); c.moveTo(x + r, y); c.arcTo(x + w, y, x + w, y + h, r); c.arcTo(x + w, y + h, x, y + h, r);
    c.arcTo(x, y + h, x, y, r); c.arcTo(x, y, x + w, y, r); c.closePath();
  }
  function douce(x) { x = Math.max(0, Math.min(1, x)); return 1 - Math.pow(1 - x, 3); }

  // Dessine l'image à l'instant t (en secondes). t très grand = image fixe complète.
  function peindre(c, L, t) {
    var W = 1080, H = L.H, C = L.C;
    c.save();
    c.fillStyle = C.fond; c.fillRect(0, 0, W, H);
    if (L.fmt === 'story') {
      var g = c.createRadialGradient(W * 0.85, H * 0.1, 50, W * 0.85, H * 0.1, H * 0.8);
      g.addColorStop(0, 'rgba(255,255,255,.16)'); g.addColorStop(1, 'rgba(255,255,255,0)');
      c.fillStyle = g; c.fillRect(0, 0, W, H);
    }
    // Grand symbole en filigrane, qui se balance doucement dans la vidéo
    var i = imgs[C.sym];
    if (i) {
      c.save(); c.globalAlpha = L.fmt === 'story' ? 0.07 : 0.045;
      c.translate(W - 120, H - 160); c.rotate(-0.15 + Math.sin(Math.min(t, 60) * 0.6) * 0.06);
      c.drawImage(i, -230, -340, 460, 685); c.restore();
    }
    L.els.forEach(function (e) {
      var p = douce((t - e.at) / 0.7);
      if (p <= 0) return;
      var y0 = L.decal + e.y;
      c.save();
      c.globalAlpha = p;
      if (e.anim === 'monte') c.translate(0, (1 - p) * 40);
      else if (e.anim === 'glisse') c.translate((1 - p) * 120, 0);
      else if (e.anim === 'pop' || e.anim === 'pulse') {
        var s = 0.85 + 0.15 * p;
        if (e.anim === 'pulse' && t > e.at + 1 && t < 60) s *= 1 + 0.025 * Math.sin((t - e.at) * 4);
        c.translate(W / 2, y0 + e.h / 2); c.scale(s, s); c.translate(-W / 2, -(y0 + e.h / 2));
      }
      e.dessin(c, y0);
      c.restore();
    });
    c.restore();
  }

  function canvasPour(fmt, echelle) {
    var F = FORMATS[fmt], cv = document.createElement('canvas');
    cv.width = Math.round(1080 * echelle); cv.height = Math.round(F.h * echelle);
    var c = cv.getContext('2d'); c.scale(echelle, echelle);
    return { cv: cv, c: c };
  }
  function nomFichier(d, fmt, ext) {
    return 'menu-semaine' + (d.du ? '-' + d.du : '') + '-' + FORMATS[fmt].nom + '.' + ext;
  }
  function telecharger(blob, nom) {
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = nom;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
  }

  // Aperçu dans un petit canvas existant
  function apercu(cv, fmt, d, t) {
    return charger().then(function () {
      var F = FORMATS[fmt], e = cv.width / 1080, c = cv.getContext('2d');
      cv.height = Math.round(F.h * e);
      c.setTransform(e, 0, 0, e, 0, 0);
      var L = miseEnPage(c, fmt, d);
      peindre(c, L, t == null ? 99 : t);
      return L;
    });
  }
  // Animation de l'aperçu (sans enregistrement)
  function jouer(cv, d) {
    return charger().then(function () {
      var e = cv.width / 1080, c = cv.getContext('2d'), L = miseEnPage(c, 'story', d), t0 = performance.now();
      return new Promise(function (ok) {
        (function tick() {
          var t = (performance.now() - t0) / 1000;
          c.setTransform(e, 0, 0, e, 0, 0); peindre(c, L, t);
          if (t < DUREE) requestAnimationFrame(tick); else ok();
        })();
      });
    });
  }

  function image(fmt, d) {
    return charger().then(function () {
      var F = FORMATS[fmt], k = canvasPour(fmt, F.sortie / 1080);
      peindre(k.c, miseEnPage(k.c, fmt, d), 99);
      return new Promise(function (ok) { k.cv.toBlob(function (b) { telecharger(b, nomFichier(d, fmt, 'png')); ok(); }, 'image/png'); });
    });
  }

  function formatVideo() {
    if (!window.MediaRecorder) return null;
    return ['video/mp4;codecs=avc1.42E01E', 'video/mp4;codecs=avc1', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm']
      .filter(function (m) { return MediaRecorder.isTypeSupported(m); })[0] || null;
  }

  // Story vidéo : l'animation est jouée en temps réel (15 s) et enregistrée
  function video(d, progres) {
    var mime = formatVideo();
    if (!mime) return Promise.reject(new Error('Votre navigateur ne sait pas créer de vidéo. Utilisez Chrome, Edge ou Safari à jour.'));
    return charger().then(function () {
      var k = canvasPour('story', 1), L = miseEnPage(k.c, 'story', d);
      peindre(k.c, L, 0);
      var flux = k.cv.captureStream(30), rec = new MediaRecorder(flux, { mimeType: mime, videoBitsPerSecond: 8000000 }), morceaux = [];
      rec.ondataavailable = function (e) { if (e.data.size) morceaux.push(e.data); };
      return new Promise(function (ok, ko) {
        rec.onerror = function (e) { ko(e.error || new Error('Erreur pendant la création de la vidéo.')); };
        rec.onstop = function () {
          var ext = mime.indexOf('mp4') >= 0 ? 'mp4' : 'webm';
          telecharger(new Blob(morceaux, { type: mime.split(';')[0] }), nomFichier(d, 'story', ext));
          ok(ext);
        };
        rec.start(250);
        var t0 = performance.now();
        // setTimeout plutôt que requestAnimationFrame : continue même si la fenêtre n'est pas au premier plan
        (function tick() {
          var t = (performance.now() - t0) / 1000;
          peindre(k.c, L, Math.min(t, DUREE));
          if (progres) progres(Math.min(1, t / DUREE));
          if (t < DUREE) setTimeout(tick, 1000 / 30); else setTimeout(function () { rec.stop(); }, 150);
        })();
      });
    });
  }

  return { apercu: apercu, jouer: jouer, image: image, video: video, formatVideo: formatVideo, DUREE: DUREE };
})();
