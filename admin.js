// Back-office : une rubrique à la fois, enregistrement en un clic, aperçu en direct.
// Les champs portent leur chemin dans les données (data-p="semaine.jours.0.nom") : une saisie met à jour l'état,
// les boutons (data-act) modifient la structure puis l'écran est redessiné.
(function () {
  'use strict';

  var E = JSON.parse(document.getElementById('etat').textContent);
  var site = E.site;
  var saved = clone(site);
  var app = document.getElementById('app');
  var JOURS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
  var NOMS = { evenements: 'les événements', semaine: 'le menu de la semaine', menus: 'les cartes traiteur', mots: 'les mots défilants', avis: 'les avis' };
  var onglet = lire('onglet') || 'stats';
  var PARTS = ['evenements', 'semaine', 'menus', 'mots', 'avis'];
  var carteOuverte = null;          // index de la carte en cours de modification
  var catsOuvertes = {};            // catégories dépliées : { "carte-cat": true }
  var specialOuvert = !!(site.semaine.special.nom || site.semaine.special.lignes.length || site.semaine.special.titre);

  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function lire(k) { try { return sessionStorage.getItem('jp-' + k); } catch (e) { return null; } }
  function ecrire(k, v) { try { sessionStorage.setItem('jp-' + k, v); } catch (e) {} }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ico(n) { return '<svg class="ico" aria-hidden="true"><use href="#i-' + n + '"/></svg>'; }
  function get(p) { return p.split('.').reduce(function (o, k) { return o == null ? o : o[k]; }, site); }
  function set(p, v) { var k = p.split('.'), last = k.pop(); get(k.join('.'))[last] = v; }
  function sale(part) { return JSON.stringify(site[part]) !== JSON.stringify(saved[part]); }
  function pluriel(n, s) { return n + ' ' + s + (n > 1 ? 's' : ''); }

  // ---------- Prix : « 9,5 » devient « 9.50 € » ----------
  function formatPrix(v) {
    var m = String(v).trim().match(/^(\d+)(?:[.,](\d{1,2}))?\s*(?:€|eur|euros?)?$/i);
    if (!m) return String(v).trim();
    return m[1] + '.' + ((m[2] || '') + '00').slice(0, 2) + ' €';
  }

  // ---------- Dates ----------
  function iso(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function parse(s) { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || ''); return m ? new Date(+m[1], m[2] - 1, +m[3]) : null; }
  function plus(s, n) { var d = parse(s); if (!d) return ''; d.setDate(d.getDate() + n); return iso(d); }
  var MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  function dateFr(s) { var d = parse(s); return d ? (d.getDate() === 1 ? '1er' : d.getDate()) + ' ' + MOIS[d.getMonth()] : ''; }
  function periode(du, au) {
    var a = parse(du), b = parse(au);
    if (!a) return '';
    if (!b || b < a) return 'à partir du ' + dateFr(du);
    return 'du ' + (a.getMonth() === b.getMonth() ? (a.getDate() === 1 ? '1er' : a.getDate()) : dateFr(du)) + ' au ' + dateFr(au) + ' ' + b.getFullYear();
  }
  function statutSemaine(s) {
    var t = E.aujourdhui;
    if (s.conges && s.conges.actif) return ['orange', 'Fermeture affichée à la place du menu'];
    if (!s.du) return ['gris', 'Dates à renseigner'];
    if (s.du > t) return ['bleu', 'À venir : s’affichera le ' + dateFr(s.du)];
    if (s.au && s.au < t) return ['orange', 'Semaine terminée : pensez à préparer la suivante'];
    return ['vert', 'En ligne en ce moment'];
  }

  // ---------- Modèles ----------
  var MODELES = {
    jour: function (arr) {
      var dernier = arr.length ? JOURS.indexOf(arr[arr.length - 1].jour) : 0;
      return { jour: JOURS[Math.min(6, dernier + 1)] || 'Mardi', nom: '', desc: '', prix: '', ferme: false, motif: '', message: '' };
    },
    plat: function () { return { nom: '', desc: '', prix: '' }; },
    cat: function () { return { titre: '', desc: '', prix: '', plats: [{ nom: '', desc: '', prix: '' }] }; },
    carte: function () { return { titre: 'Nouvelle carte', photo: '', intro: '', prix: '', cats: [{ titre: '', desc: '', prix: '', plats: [{ nom: '', desc: '', prix: '' }] }], note: '', encart: [] }; },
    avis: function () { return { nom: '', note: 5, texte: '' }; },
    item: function () { return { nom: '', prix: '' }; },
    evenement: function () { return { actif: false, titre: 'Nouvel événement', date: '', accroche: '', items: [{ nom: '', prix: '' }], lignes: [], cta: 'N’oubliez pas de réserver !', reservation: { active: false, date: '' }, couleur: '#700954', photo: '', popup: false, masquer_apres: true }; }
  };

  // ---------- Champs ----------
  function champ(label, p, o) {
    o = o || {};
    var v = get(p), val = o.lignes ? (v || []).join('\n') : v;
    var attrs = ' data-p="' + p + '"' + (o.lignes ? ' data-lignes' : '') + (o.prix ? ' data-prix' : '') +
      ' placeholder="' + esc(o.ph || '') + '"' + (o.max ? ' maxlength="' + o.max + '"' : '');
    var input = o.zone || o.lignes
      ? '<textarea rows="' + (o.rows || 3) + '"' + attrs + '>' + esc(val) + '</textarea>'
      : '<input type="' + (o.type || 'text') + '"' + attrs + ' value="' + esc(val) + '"' + (o.prix ? ' inputmode="decimal"' : '') + '>';
    return '<label class="champ' + (o.cls ? ' ' + o.cls : '') + '"><span>' + label + (o.opt ? ' <em>facultatif</em>' : '') + '</span>' + input +
      (o.aide ? '<small>' + o.aide + '</small>' : '') + '</label>';
  }
  function boutonsOrdre(p, i, n, o) {
    o = o || {};
    return '<div class="ordre">' +
      '<button type="button" class="ib" data-act="up" data-p="' + p + '" data-i="' + i + '"' + (i === 0 ? ' disabled' : '') + ' title="Monter" aria-label="Monter">' + ico('haut') + '</button>' +
      '<button type="button" class="ib" data-act="down" data-p="' + p + '" data-i="' + i + '"' + (i === n - 1 ? ' disabled' : '') + ' title="Descendre" aria-label="Descendre">' + ico('bas') + '</button>' +
      '<button type="button" class="ib ib-suppr" data-act="del" data-p="' + p + '" data-i="' + i + '"' + (o.quoi ? ' data-quoi="' + esc(o.quoi) + '"' : '') + ' title="Supprimer" aria-label="Supprimer">' + ico('suppr') + '</button></div>';
  }
  // Choix d'une photo existante, ou envoi d'une nouvelle photo depuis l'ordinateur ou le téléphone
  function photosChoix(p, nom, val, petites) {
    return '<div class="photos-choix' + (petites ? ' petites' : '') + '" role="radiogroup" aria-label="Photo">' +
      '<label class="photo-opt envoyer"><input type="file" accept="image/jpeg,image/png,image/webp" data-envoi="' + p + '"><span class="sans">' + ico('upload') + 'Ajouter une photo</span></label>' +
      '<label class="photo-opt"><input type="radio" name="' + nom + '" data-p="' + p + '" value=""' + (val ? '' : ' checked') + '><span class="sans">Sans photo</span></label>' +
      E.photos.map(function (ph) {
        return '<label class="photo-opt"><input type="radio" name="' + nom + '" data-p="' + p + '" value="' + esc(ph.nom) + '"' + (val === ph.nom ? ' checked' : '') + '><img src="' + esc(ph.url) + '" alt="' + esc(ph.nom) + '" loading="lazy"></label>';
      }).join('') + '</div>';
  }
  function tete(titre, aide, actions) {
    return '<div class="tete"><div><h1>' + titre + '</h1>' + (aide ? '<p class="aide">' + aide + '</p>' : '') + '</div>' + (actions ? '<div class="tete-actions">' + actions + '</div>' : '') + '</div>';
  }

  // =============== Menu de la semaine ===============
  function vueSemaine() {
    var s = site.semaine, st = statutSemaine(s), n = s.jours.length;
    var jours = s.jours.map(function (j, i) {
      var p = 'semaine.jours.' + i;
      return '<div class="jour-ligne' + (j.ferme ? ' est-ferme' : '') + '"><div class="jour-col">' +
        '<select data-p="' + p + '.jour" aria-label="Jour">' + JOURS.map(function (d) { return '<option' + (d === j.jour ? ' selected' : '') + '>' + d + '</option>'; }).join('') +
        (JOURS.indexOf(j.jour) < 0 ? '<option selected>' + esc(j.jour) + '</option>' : '') + '</select>' +
        '<label class="interrupteur petit" title="Jour férié, fermeture exceptionnelle…"><input type="checkbox" data-p="' + p + '.ferme"' + (j.ferme ? ' checked' : '') + '><span></span>Fermé</label></div>' +
        (j.ferme
          ? '<div class="jour-champs">' +
              '<input class="fort" data-p="' + p + '.motif" value="' + esc(j.motif) + '" placeholder="Titre, ex. Férié, Fermeture exceptionnelle" aria-label="Titre de la fermeture">' +
              '<input data-p="' + p + '.message" value="' + esc(j.message) + '" placeholder="Message, ex. Bon 11 novembre à tous !" aria-label="Message">' +
            '</div><span class="badge-ferme">Fermé</span>'
          : '<div class="jour-champs">' +
              '<input class="fort" data-p="' + p + '.nom" value="' + esc(j.nom) + '" placeholder="Plat, ex. Waterzooï de volaille" aria-label="Plat du ' + esc(j.jour) + '">' +
              '<input data-p="' + p + '.desc" value="' + esc(j.desc) + '" placeholder="Accompagnement, ex. légumes et riz pilaf" aria-label="Accompagnement">' +
            '</div>' +
            '<input class="prix" data-p="' + p + '.prix" data-prix value="' + esc(j.prix) + '" placeholder="9.50 €" inputmode="decimal" aria-label="Prix">') +
        boutonsOrdre('semaine.jours', i, n) + '</div>';
    }).join('');
    var sp = specialOuvert
      ? '<div class="grille-2">' + champ('Quand', 'semaine.special.titre', { ph: 'ex. Vendredi et samedi' }) + champ('Prix', 'semaine.special.prix', { ph: '19.00 €', prix: true }) + '</div>' +
        champ('Nom de l’offre', 'semaine.special.nom', { ph: 'ex. Plateau apéritif « Halloween »' }) +
        champ('Contenu', 'semaine.special.lignes', { lignes: true, rows: 4, ph: 'Une ligne par élément\nex. Tapenade aux olives noires et ses gressins', aide: 'Une ligne par élément.' })
      : '';
    return tete('Menu de la semaine', 'Les plats du jour affichés en haut du site. Le plat du jour est mis en avant automatiquement.',
        '<button type="button" class="btn btn-discret" data-act="semaine-suivante">' + ico('suivant') + 'Préparer la semaine suivante</button>' +
        '<button type="button" class="btn btn-ligne" data-act="aller" data-cible="visuels">' + ico('imprim') + 'Visuels et affiche</button>') +
      '<div class="deux-cols"><div class="col-form">' +
        '<section class="bloc"><div class="bloc-tete"><h2>1. La semaine</h2><span class="statut statut-' + st[0] + '" id="statut-sem">' + st[1] + '</span></div>' +
          '<div class="grille-3">' + champ('Du', 'semaine.du', { type: 'date' }) + champ('Au', 'semaine.au', { type: 'date' }) + champ('Titre', 'semaine.titre', { ph: 'Inspirations de la semaine' }) + '</div></section>' +
        '<section class="bloc bloc-conges' + (s.conges.actif ? ' actif' : '') + '"><div class="bloc-tete"><h2>Fermeture de la semaine <em>congés, travaux…</em></h2>' +
          '<label class="interrupteur"><input type="checkbox" data-p="semaine.conges.actif"' + (s.conges.actif ? ' checked' : '') + '><span></span>' + (s.conges.actif ? 'Activée' : 'Désactivée') + '</label></div>' +
          (s.conges.actif
            ? '<p class="alerte-info">Le menu est remplacé sur le site par ce message. Les plats restent enregistrés pour plus tard.</p>' +
              champ('Titre', 'semaine.conges.titre', { ph: 'Fermeture pour congés' }) +
              champ('Message', 'semaine.conges.message', { zone: true, rows: 3, ph: 'ex. Nous sommes en congés du 24 décembre au 4 janvier. Réouverture le mardi 5 janvier, à très vite !' })
            : '<p class="aide">À activer si la boutique est fermée toute la semaine : un titre et un message remplacent le menu sur le site et sur les visuels. Pour un seul jour (férié…), utilisez plutôt le bouton « Fermé » du jour concerné.</p>') + '</section>' +
        '<div class="' + (s.conges.actif ? 'attenue' : '') + '">' +
        '<section class="bloc"><div class="bloc-tete"><h2>2. Les plats du jour</h2><span class="compte">' + pluriel(n, 'jour') + '</span></div>' +
          '<div class="jours-liste">' + (jours || '<p class="vide">Aucun jour. Ajoutez le premier plat.</p>') + '</div>' +
          '<button type="button" class="btn-ajout" data-act="add" data-p="semaine.jours" data-tpl="jour">' + ico('plus') + 'Ajouter un jour</button></section>' +
        '<section class="bloc"><div class="bloc-tete"><h2>3. Offre spéciale <em>facultatif</em></h2>' +
          '<label class="interrupteur"><input type="checkbox" data-act="special"' + (specialOuvert ? ' checked' : '') + '><span></span>' + (specialOuvert ? 'Affichée' : 'Masquée') + '</label></div>' +
          (specialOuvert ? sp : '<p class="aide">Un plateau ou une formule pour quelques jours (ex. plateau apéritif du week-end). Elle apparaît dans un encadré à côté des plats.</p>') + '</section>' +
        '<section class="bloc">' + champ('Petite note sous le menu', 'semaine.note', { opt: true, ph: 'ex. Commandes la veille avant 18 h' }) + '</section>' +
        '</div>' +
        vueVisuels() +
      '</div><aside class="col-apercu"><p class="apercu-titre">Aperçu de l’affiche</p><div class="affiche-mini" id="apercu">' + apercu() + '</div></aside></div>';
  }
  // ---------- Visuels à télécharger (dessinés par visuels.js) ----------
  function donneesVisuels() {
    var s = site.semaine;
    return { titre: s.titre, du: s.du, au: s.au, jours: s.jours, special: specialOuvert ? s.special : null, conges: s.conges.actif ? s.conges : null };
  }
  function vueVisuels() {
    var fv = window.Visuels && Visuels.formatVideo(), mp4 = fv && fv.indexOf('mp4') >= 0;
    function carte(fmt, titre, sous, boutons) {
      return '<div class="visuel"><div class="visuel-cadre"><canvas class="v-apercu" data-format="' + fmt + '" width="270" height="340" aria-hidden="true"></canvas></div>' +
        '<strong>' + titre + '</strong><small>' + sous + '</small><div class="visuel-actions">' + boutons + '</div></div>';
    }
    return '<section class="bloc" id="visuels"><div class="bloc-tete"><h2>4. Visuels à télécharger</h2><span class="compte">Créés automatiquement à partir du menu ci-dessus</span></div>' +
      '<div class="visuels-grille">' +
        carte('a4', 'Affiche A4', 'Pour la vitrine et le comptoir', '<button type="button" class="btn btn-violet" data-act="visuel" data-format="a4">Télécharger (PNG)</button><button type="button" class="btn btn-discret" data-act="imprimer">' + ico('imprim') + 'Imprimer / PDF</button>') +
        carte('post', 'Publication', '1080 × 1350 · Instagram, Facebook', '<button type="button" class="btn btn-violet" data-act="visuel" data-format="post">Télécharger (PNG)</button>') +
        carte('story', 'Story', '1080 × 1920 · Instagram, Facebook, WhatsApp', '<button type="button" class="btn btn-violet" data-act="visuel" data-format="story">Télécharger (PNG)</button>') +
        carte('story', 'Story vidéo 15 s', '1080 × 1920 · ' + (fv ? (mp4 ? 'vidéo MP4' : 'vidéo WebM') : 'non disponible sur ce navigateur'),
          '<button type="button" class="btn btn-violet" data-act="video"' + (fv ? '' : ' disabled') + '>Créer la vidéo</button><button type="button" class="btn btn-discret" data-act="video-apercu">▶ Aperçu</button>').replace('class="visuel"', 'class="visuel visuel-video"') +
      '</div>' +
      (fv && !mp4 ? '<p class="aide petit">Ce navigateur crée la vidéo au format WebM, qu’Instagram n’accepte pas toujours. Pour un MP4, utilisez Chrome ou Edge à jour, ou Safari.</p>' : '') +
      '<p class="aide petit">Les visuels reprennent ce qui est affiché à l’écran, même avant d’enregistrer. Pendant la création de la vidéo (15 secondes), gardez cette page ouverte.</p></section>';
  }
  var apercusT;
  function dessinerApercus() {
    if (!window.Visuels) return;
    app.querySelectorAll('canvas.v-apercu').forEach(function (cv) { Visuels.apercu(cv, cv.dataset.format, donneesVisuels()); });
  }
  function apercu() {
    var s = site.semaine, sp = s.special;
    var tete_ = '<div class="af-tete"><b>' + esc(s.titre || 'Inspirations de la semaine') + '</b><small>' + esc(periode(s.du, s.au) ? 'semaine ' + periode(s.du, s.au) : '') + '</small></div>';
    if (s.conges.actif) return tete_ + '<div class="af-conges"><strong>' + esc(s.conges.titre || 'Fermeture pour congés') + '</strong><span>' + esc(s.conges.message) + '</span></div>';
    return tete_ +
      s.jours.map(function (j) {
        if (j.ferme) return '<div class="af-jour af-ferme"><b>' + esc(j.jour) + '</b><span><strong>' + esc(j.motif || 'Fermé') + '</strong>' + esc(j.message) + '</span><em>Fermé</em></div>';
        return '<div class="af-jour"><b>' + esc(j.jour) + '</b><span><strong>' + (esc(j.nom) || '<i>Plat à saisir</i>') + '</strong>' + esc(j.desc) + '</span><em>' + esc(j.prix) + '</em></div>';
      }).join('') +
      (specialOuvert && (sp.nom || sp.lignes.join('')) ? '<div class="af-special"><b>' + esc(sp.titre) + '</b><strong>' + esc(sp.nom) + (sp.prix ? ' · ' + esc(sp.prix) : '') + '</strong>' +
        sp.lignes.filter(Boolean).map(function (l) { return '<span>' + esc(l) + '</span>'; }).join('') + '</div>' : '');
  }

  // =============== Cartes traiteur ===============
  function vueCartes() {
    if (carteOuverte !== null && site.menus[carteOuverte]) return vueCarte(carteOuverte);
    carteOuverte = null;
    var n = site.menus.length;
    var liste = site.menus.map(function (c, i) {
      var nb = c.cats.reduce(function (t, k) { return t + k.plats.length; }, 0);
      var ph = E.photos.filter(function (x) { return x.nom === c.photo; })[0];
      return '<article class="carte-item">' +
        '<button type="button" class="carte-ouvrir" data-act="ouvrir" data-i="' + i + '">' +
          (ph ? '<img src="' + esc(ph.url) + '" alt="">' : '<span class="carte-vignette">' + esc((c.titre || '?').charAt(0)) + '</span>') +
          '<span class="carte-item-txt"><strong>' + esc(c.titre) + '</strong><small>' + pluriel(c.cats.length, 'catégorie') + ' · ' + pluriel(nb, 'plat') + (c.prix ? ' · ' + esc(c.prix) : '') + '</small></span>' +
          '<span class="carte-modifier">' + ico('crayon') + 'Modifier</span></button>' +
        '<div class="carte-item-actions"><button type="button" class="ib" data-act="imprimer-carte" data-i="' + i + '" title="Imprimer ou télécharger en PDF" aria-label="Imprimer la carte ' + esc(c.titre) + '">' + ico('imprim') + '</button>' +
        '<button type="button" class="ib" data-act="dup" data-i="' + i + '" title="Dupliquer" aria-label="Dupliquer">' + ico('copie') + '</button>' +
        boutonsOrdre('menus', i, n, { quoi: 'la carte « ' + c.titre + ' » et tous ses plats' }) + '</div></article>';
    }).join('');
    return tete('Cartes traiteur', 'Chaque carte devient un onglet sur le site, dans cet ordre. Cliquez sur une carte pour modifier ses plats.',
        '<button type="button" class="btn btn-ligne" data-act="imprimer-carte" data-i="tout">' + ico('imprim') + 'Imprimer toutes les cartes</button>' +
        '<button type="button" class="btn btn-violet" data-act="add" data-p="menus" data-tpl="carte">' + ico('plus') + 'Nouvelle carte</button>') +
      '<div class="cartes-liste">' + (liste || '<p class="vide">Aucune carte pour le moment.</p>') + '</div>';
  }
  function vueCarte(ci) {
    var c = site.menus[ci], base = 'menus.' + ci, nk = c.cats.length;
    var photos = photosChoix(base + '.photo', 'photo', c.photo);
    var cats = c.cats.map(function (k, i) {
      var p = base + '.cats.' + i, cle = ci + '-' + i, np = k.plats.length;
      var plats = k.plats.map(function (pl, j) {
        var pp = p + '.plats.' + j;
        return '<div class="plat-ligne">' +
          '<input class="fort" data-p="' + pp + '.nom" value="' + esc(pl.nom) + '" placeholder="Nom du plat" aria-label="Nom du plat">' +
          '<input data-p="' + pp + '.desc" value="' + esc(pl.desc) + '" placeholder="Description, accompagnement…" aria-label="Description">' +
          '<input class="prix" data-p="' + pp + '.prix" data-prix data-dernier="' + (j === np - 1 ? p + '.plats' : '') + '" value="' + esc(pl.prix) + '" placeholder="Prix" inputmode="decimal" aria-label="Prix">' +
          boutonsOrdre(p + '.plats', j, np) + '</div>';
      }).join('');
      return '<details class="cat-bloc" data-cle="' + cle + '"' + (catsOuvertes[cle] ? ' open' : '') + '>' +
        '<summary><span class="cat-resume"><strong>' + (esc(k.titre) || '<i>Catégorie sans nom</i>') + '</strong><small>' + pluriel(np, 'plat') + (k.prix ? ' · ' + esc(k.prix) : '') + '</small></span>' +
          '<span class="cat-ouvrir">' + ico('bas') + '</span></summary>' +
        '<div class="cat-corps">' +
          '<div class="cat-champs">' + champ('Nom de la catégorie', p + '.titre', { ph: 'ex. Bœuf, Entrées, Formule « Terre »…' }) +
            champ('Prix de la catégorie', p + '.prix', { opt: true, ph: 'ex. 20.90 € / pers.', prix: true }) +
            champ('Précision', p + '.desc', { opt: true, ph: 'ex. Pour 6 personnes', cls: 'large' }) +
            boutonsOrdre(base + '.cats', i, nk, { quoi: 'la catégorie « ' + (k.titre || 'sans nom') + ' » et ses ' + pluriel(np, 'plat') }) + '</div>' +
          '<div class="plats-entete" aria-hidden="true"><span>Plat</span><span>Description</span><span>Prix</span></div>' +
          '<div class="plats-liste">' + plats + '</div>' +
          '<div class="cat-pied"><button type="button" class="btn-ajout" data-act="add" data-p="' + p + '.plats" data-tpl="plat">' + ico('plus') + 'Ajouter un plat</button>' +
          '<button type="button" class="btn-ajout discret" data-act="coller" data-p="' + p + '.plats">' + ico('coller') + 'Coller une liste depuis Word</button></div>' +
        '</div></details>';
    }).join('');
    return '<button type="button" class="retour" data-act="retour">' + ico('retour') + 'Toutes les cartes</button>' +
      tete(esc(c.titre || 'Carte sans nom'), 'Les modifications apparaissent sur le site après « Enregistrer ».',
        '<button type="button" class="btn btn-ligne" data-act="imprimer-carte" data-i="' + ci + '">' + ico('imprim') + 'Imprimer / PDF</button>') +
      '<section class="bloc"><div class="bloc-tete"><h2>Présentation</h2></div>' +
        '<div class="grille-2">' + champ('Nom de la carte', base + '.titre', { ph: 'ex. Plats cuisinés' }) + champ('Prix unique', base + '.prix', { opt: true, ph: 'ex. 17.90 € par personne' }) + '</div>' +
        champ('Phrase d’introduction', base + '.intro', { zone: true, rows: 2, opt: true, ph: 'ex. Présentation sur plateaux, avec sauces d’accompagnement.' }) +
        champ('Conditions de commande', base + '.note', { opt: true, ph: 'ex. Commande de 10 personnes minimum à 50 personnes maximum.' }) +
        champ('Informations encadrées', base + '.encart', { lignes: true, rows: 2, opt: true, ph: 'ex. Inclus : couverts, serviette, pain et beurre', aide: 'Une information par ligne, affichée dans un encadré sous les plats.' }) +
        '<div class="champ"><span>Photo</span>' + photos + '<small>Pour ajouter une photo, déposez-la dans le dossier img/photos/ du site.</small></div></section>' +
      '<section class="bloc"><div class="bloc-tete"><h2>Catégories et plats</h2>' +
        '<span class="bloc-outils"><button type="button" class="lien" data-act="tout-ouvrir">Tout déplier</button><button type="button" class="lien" data-act="tout-fermer">Tout replier</button></span></div>' +
        '<div class="cats-liste">' + (cats || '<p class="vide">Aucune catégorie.</p>') + '</div>' +
        '<button type="button" class="btn-ajout" data-act="add" data-p="' + base + '.cats" data-tpl="cat">' + ico('plus') + 'Ajouter une catégorie</button></section>';
  }

  // =============== Mots défilants ===============
  function vueMots() {
    return tete('Mots défilants', 'Les mots qui défilent dans le bandeau violet, juste sous le haut de la page.') +
      '<section class="bloc">' + champ('Un mot ou une expression par ligne', 'mots', { lignes: true, rows: 9, ph: 'épicerie fine\ntraiteur\nfromagerie' }) +
      '<p class="apercu-titre">Aperçu</p><div class="mots-apercu" id="mots-apercu">' + motsApercu() + '</div></section>';
  }
  function motsApercu() { return site.mots.filter(function (m) { return m.trim(); }).map(function (m) { return '<span>' + esc(m) + '</span>'; }).join(''); }

  // =============== Avis ===============
  function vueAvis() {
    var n = site.avis.length;
    var liste = site.avis.map(function (a, i) {
      var p = 'avis.' + i;
      return '<div class="avis-ligne"><div class="grille-avis">' + champ('Prénom', p + '.nom', { ph: 'ex. Marie' }) +
        '<label class="champ"><span>Note</span><select data-p="' + p + '.note" data-nombre>' + [5, 4, 3, 2, 1, 0].map(function (v) {
          return '<option value="' + v + '"' + (+a.note === v ? ' selected' : '') + '>' + (v ? '★★★★★'.slice(0, v) + ' (' + v + '/5)' : 'Sans note') + '</option>';
        }).join('') + '</select></label>' + boutonsOrdre('avis', i, n) + '</div>' +
        champ('Avis', p + '.texte', { zone: true, rows: 2, ph: 'Le texte de l’avis' }) + '</div>';
    }).join('');
    return tete('Avis', 'Quelques avis choisis, affichés au-dessus du bouton Google. Copiez-les depuis Google ou Facebook.',
        '<button type="button" class="btn btn-violet" data-act="add" data-p="avis" data-tpl="avis">' + ico('plus') + 'Ajouter un avis</button>') +
      '<section class="bloc">' + (liste || '<p class="vide">Aucun avis : seul le bouton « Lire et laisser un avis Google » est affiché sur le site.</p>') + '</section>';
  }


  // =============== Événements ===============
  var THEMES = [['Violet', '#700954'], ['Bordeaux', '#5e1426'], ['Sapin (Noël)', '#1c4634'], ['Printemps (Pâques)', '#4f7a2b'], ['Noir et or', '#2a2118'], ['Rouge', '#b3261e'], ['Bleu nuit', '#1d3557'], ['Orange', '#c2571a'], ['Rose', '#f4c6d4'], ['Crème', '#f2e6d3']];
  function statutEvt(e) {
    var t = E.aujourdhui;
    if (!e.actif) return ['gris', 'Masqué sur le site'];
    if (e.masquer_apres && e.date && e.date < t) return ['gris', 'Date passée : masqué automatiquement'];
    if (e.reservation.active && e.reservation.date && e.reservation.date < t) return ['orange', 'En ligne · réservations closes'];
    return ['vert', 'En ligne sur le site'];
  }
  function vueEvenements() {
    var liste = site.evenements.map(function (e, i) {
      var p = 'evenements.' + i, st = statutEvt(e), ni = e.items.length;
      var items = e.items.map(function (it, j) {
        return '<div class="item-ligne"><input class="fort" data-p="' + p + '.items.' + j + '.nom" value="' + esc(it.nom) + '" placeholder="ex. Plateau de charcuterie et fromage pour deux" aria-label="Produit">' +
          '<input class="prix" data-p="' + p + '.items.' + j + '.prix" data-prix value="' + esc(it.prix) + '" placeholder="17.00 €" inputmode="decimal" aria-label="Prix">' +
          boutonsOrdre(p + '.items', j, ni) + '</div>';
      }).join('');
      var themes = THEMES.map(function (t) {
        return '<button type="button" class="pastille-couleur' + (e.couleur === t[1] ? ' choisie' : '') + '" data-act="couleur" data-p="' + p + '.couleur" data-c="' + t[1] + '" aria-pressed="' + (e.couleur === t[1]) + '"><span style="background:' + t[1] + '"></span>' + t[0] + '</button>';
      }).join('') + '<label class="pastille-couleur perso' + (THEMES.some(function (t) { return t[1] === e.couleur; }) ? '' : ' choisie') + '" title="Choisir n’importe quelle couleur"><input type="color" data-p="' + p + '.couleur" value="' + esc(e.couleur) + '"><span class="arc"></span>Autre couleur…</label>';
      var photos = photosChoix(p + '.photo', 'photo-' + i, e.photo, true);
      return '<section class="bloc evt-bloc" style="border-top-color:' + esc(e.couleur) + '">' +
        '<div class="evt-bloc-tete"><label class="interrupteur grand"><input type="checkbox" data-p="' + p + '.actif"' + (e.actif ? ' checked' : '') + '><span></span><b>' + (e.actif ? 'Activé' : 'Désactivé') + '</b></label>' +
          '<span class="statut statut-' + st[0] + '">' + st[1] + '</span>' +
          '<div class="evt-bloc-actions"><button type="button" class="ib" data-act="dup-evt" data-i="' + i + '" title="Dupliquer (ex. pour l’an prochain)" aria-label="Dupliquer">' + ico('copie') + '</button>' +
          '<button type="button" class="ib ib-suppr" data-act="del" data-p="evenements" data-i="' + i + '" data-quoi="l’événement « ' + esc(e.titre) + ' »" title="Supprimer" aria-label="Supprimer">' + ico('suppr') + '</button></div></div>' +
        '<div class="grille-evt">' + champ('Nom de l’événement', p + '.titre', { ph: 'ex. Beaujolais nouveau, Menu de Noël…' }) + champ('Date de l’événement', p + '.date', { type: 'date' }) + '</div>' +
        champ('Petit texte d’accroche', p + '.accroche', { zone: true, rows: 2, opt: true, ph: 'ex. On fête l’arrivée du Beaujolais nouveau !' }) +
        '<div class="champ"><span>Ce que vous proposez</span><div class="items-liste">' + items + '</div>' +
          '<button type="button" class="btn-ajout" data-act="add" data-p="' + p + '.items" data-tpl="item">' + ico('plus') + 'Ajouter un produit ou un plat</button></div>' +
        champ('Phrases mises en avant', p + '.lignes', { lignes: true, rows: 2, opt: true, ph: '« Sans oublier nos huîtres de Normandie »', aide: 'Une phrase par ligne, affichée en couleur sous les produits.' }) +
        champ('Phrase au-dessus du bouton Réserver', p + '.cta', { opt: true, ph: 'N’oubliez pas de réserver !' }) +
        '<div class="resa-bloc"><label class="interrupteur"><input type="checkbox" data-p="' + p + '.reservation.active"' + (e.reservation.active ? ' checked' : '') + '><span></span>Date limite de réservation</label>' +
          (e.reservation.active ? champ('Réservations acceptées jusqu’au (inclus)', p + '.reservation.date', { type: 'date', aide: 'Après cette date, le bouton Réserver est remplacé par « Réservations closes ».' }) : '<p class="aide">Désactivée : le bouton « Réserver » reste affiché jusqu’à l’événement.</p>') + '</div>' +
        '<div class="champ"><span>Couleur du bandeau</span><div class="couleurs">' + themes + '</div><small>Choisissez une suggestion ou « Autre couleur… ». Le texte passe automatiquement en blanc ou en noir pour rester lisible.</small></div>' +
        '<div class="champ"><span>Photo <em>facultatif</em></span>' + photos + '</div>' +
        '<label class="case"><input type="checkbox" data-p="' + p + '.popup"' + (e.popup ? ' checked' : '') + '> Afficher en fenêtre (pop-up) à l’arrivée des visiteurs sur le site</label>' +
        '<label class="case"><input type="checkbox" data-p="' + p + '.masquer_apres"' + (e.masquer_apres ? ' checked' : '') + '> Retirer automatiquement du site le lendemain de l’événement</label>' +
        '</section>';
    }).join('');
    return tete('Événements et menus spéciaux', 'Beaujolais nouveau, Noël, Pâques… Un événement activé apparaît en grand en haut du site, avec une annonce dans le haut de page.',
        '<a class="btn btn-discret" href="./#evenements" target="_blank" rel="noopener">' + ico('oeil') + 'Voir sur le site</a>' +
        '<button type="button" class="btn btn-violet" data-act="add" data-p="evenements" data-tpl="evenement">' + ico('plus') + 'Nouvel événement</button>') +
      (liste || '<p class="vide">Aucun événement. Créez-en un pour Noël, Pâques, le Beaujolais nouveau…</p>');
  }

  // =============== Tableau de bord ===============
  var periodeStats = +(lire('periodeStats') || 30), cache = {};
  var PERIODES = [[7, '7 jours'], [30, '30 jours'], [90, '3 mois'], [365, '12 mois']];
  var SOURCES = { recherche: 'Recherche Google, Bing…', direct: 'Accès direct (adresse, favori)', facebook: 'Facebook, Instagram', maps: 'Google Maps', autre: 'Autres sites' };
  var APPAREILS = { mobile: 'Téléphone', ordinateur: 'Ordinateur', tablette: 'Tablette' };
  var JCOURTS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
  function nf(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
  function evol(a, b) {
    if (!b) return a ? '<span class="evol neutre">nouveau</span>' : '';
    var v = Math.round((a - b) / b * 100);
    return '<span class="evol ' + (v > 0 ? 'hausse' : v < 0 ? 'baisse' : 'neutre') + '" title="par rapport à la période précédente">' + (v > 0 ? '↑ +' : v < 0 ? '↓ ' : '') + v + ' %</span>';
  }
  function vueStats() {
    var chips = PERIODES.map(function (p) { return '<button type="button" data-act="periodeStats" data-i="' + p[0] + '" aria-pressed="' + (p[0] === periodeStats) + '">' + p[1] + '</button>'; }).join('');
    var d = cache[periodeStats];
    if (!d) charger(periodeStats);
    return tete('Tableau de bord', 'La fréquentation de votre site et ce que font vos visiteurs. Mesure anonyme, sans cookie.', '<div class="periodes" role="group" aria-label="Période">' + chips + '</div>') +
      '<div id="stats">' + (d ? stats(d) : '<p class="chargement">Chargement des statistiques…</p>') + '</div>';
  }
  function charger(n) {
    fetch(E.api + '?api=stats&jours=' + n, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (j.erreur) throw new Error(j.erreur);
      cache[n] = j;
      if (onglet === 'stats' && periodeStats === n) { var z = document.getElementById('stats'); if (z) z.innerHTML = stats(j); }
    }).catch(function (e) { var z = document.getElementById('stats'); if (z) z.innerHTML = '<p class="vide">' + esc(e.message) + '</p>'; });
  }
  function stats(d) {
    var T = d.total, A = d.avant;
    var appels = T.tel + T.evt, appelsAvant = A.tel + A.evt;
    if (!d.premier) return '<div class="vide grand"><strong>Les statistiques démarrent dès la mise en ligne.</strong><br>Revenez d’ici quelques jours : vous verrez ici vos visiteurs, les appels et les itinéraires demandés depuis le site.</div>';
    var tuiles = [
      ['Visiteurs', T.visiteurs, A.visiteurs, nf(T.vues) + ' pages vues'],
      ['Appels depuis le site', appels, appelsAvant, 'clics sur le numéro'],
      ['Itinéraires demandés', T.itin, A.itin, 'ouverture de Google Maps'],
      ['E-mails', T.mail, A.mail, 'clics sur l’adresse e-mail']
    ].map(function (t) { return '<div class="tuile"><p>' + t[0] + '</p><strong>' + nf(t[1]) + '</strong><small>' + evol(t[1], t[2]) + ' ' + t[3] + '</small></div>'; }).join('');
    return '<div class="tuiles">' + tuiles + '</div>' +
      '<div class="bloc"><div class="bloc-tete"><h2>Visiteurs ' + (periodeStats <= 30 ? 'par jour' : periodeStats <= 90 ? 'par semaine' : 'par mois') + '</h2><span class="compte">Survolez une barre pour le détail</span></div>' + histo(d.jours) + '</div>' +
      '<div class="a-retenir bloc"><h2>À retenir</h2><ul>' + conseils(d).map(function (c) { return '<li>' + c + '</li>'; }).join('') + '</ul></div>' +
      '<div class="stats-grille">' +
        barres('D’où viennent vos visiteurs', d.sources, SOURCES) +
        barres('Sur quel appareil', d.appareils, APPAREILS) +
        barres('Cartes traiteur consultées', d.cartes, null, 'Nombre de fois où un onglet a été ouvert.') +
        barres('Réservations d’événements', d.evenements, null, 'Clics sur « Réserver » (appel téléphonique).') +
        colonnes('Jours de visite', d.semaine, JCOURTS) +
        colonnes('Heures de visite', d.heures, d.heures.map(function (x, h) { return h % 3 ? '' : h + 'h'; }), d.heures.map(function (x, h) { return h + ' h – ' + (h + 1) + ' h'; })) +
        barres('Clics vers vos réseaux', (function () { var r = {}; if (T.insta) r.Instagram = T.insta; if (T.fb) r.Facebook = T.fb; if (T.avis) r['Avis Google'] = T.avis; return r; })(), null, 'Visiteurs partis voir vos pages depuis le site.') +
        (Object.keys(d.sites).length ? barres('Autres sites qui vous envoient des visiteurs', d.sites) : '') +
      '</div>' +
      '<p class="stats-pied">Statistiques enregistrées depuis le ' + dateFr(d.premier) + ' ' + d.premier.slice(0, 4) + '. Mesure anonyme et sans cookie : aucune adresse IP n’est conservée, les robots et vos propres visites (quand vous êtes connecté ici) ne sont pas comptés. Les données de plus de 13 mois sont effacées automatiquement.</p>';
  }
  function conseils(d) {
    var T = d.total, out = [], tot = 0, k;
    for (k in d.appareils) tot += d.appareils[k];
    var mob = tot ? Math.round((d.appareils.mobile || 0) / tot * 100) : 0;
    if (tot) out.push('<b>' + mob + ' %</b> de vos visiteurs sont sur téléphone' + (mob >= 50 ? ' : le bouton « Appeler » en bas de l’écran est votre meilleur vendeur.' : '.'));
    var actions = T.tel + T.evt + T.itin + T.mail;
    if (T.visiteurs) out.push('<b>' + Math.round(actions / T.visiteurs * 100) + ' %</b> des visites se terminent par un appel, un itinéraire ou un e-mail (' + nf(actions) + ' actions).');
    var j = d.semaine.indexOf(Math.max.apply(null, d.semaine)), h = d.heures.indexOf(Math.max.apply(null, d.heures));
    if (T.visiteurs) out.push('Le jour le plus fréquenté est le <b>' + JOURS[j].toLowerCase() + '</b>, surtout vers <b>' + h + ' h</b> : le bon moment pour mettre à jour le menu de la semaine la veille.');
    var src = Object.keys(d.sources)[0];
    if (src) out.push('Première source de visiteurs : <b>' + (SOURCES[src] || src) + '</b>' + (src === 'facebook' ? ' : continuez à publier vos menus sur Facebook.' : src === 'recherche' ? ' : pensez à garder votre fiche Google à jour (horaires, photos).' : '.'));
    var c = Object.keys(d.cartes)[0];
    if (c) out.push('La carte traiteur la plus consultée : <b>' + esc(c) + '</b>.');
    return out.length ? out : ['Pas encore assez de visites sur cette période pour en tirer des conclusions.'];
  }
  // Histogramme vertical en SVG (une seule série, regroupée par semaine ou par mois sur les longues périodes)
  function histo(jours) {
    var g = [], cle = periodeStats <= 30 ? function (x) { return x.d; } : periodeStats <= 90 ? function (x) { var t = parse(x.d), l = (t.getDay() + 6) % 7; t.setDate(t.getDate() - l); return iso(t); } : function (x) { return x.d.slice(0, 7); };
    jours.forEach(function (x) { var k = cle(x), last = g[g.length - 1]; if (!last || last.k !== k) g.push(last = { k: k, v: 0, a: 0, d: x.d }); last.v += x.visiteurs; last.a += x.actions; });
    var W = 800, H = 220, pg = 34, pb = 26, max = Math.max(4, Math.max.apply(null, g.map(function (x) { return x.v; })));
    var pas = Math.pow(10, Math.floor(Math.log10(max))), top = Math.ceil(max / pas) * pas; if (top / pas > 5) { pas *= 2; top = Math.ceil(max / pas) * pas; }
    var bw = (W - pg) / g.length, out = '';
    for (var y = 0; y <= top; y += pas) { var yy = H - pb - (y / top) * (H - pb - 10); out += '<line x1="' + pg + '" x2="' + W + '" y1="' + yy + '" y2="' + yy + '" class="grille"/><text x="' + (pg - 8) + '" y="' + (yy + 4) + '" class="axe" text-anchor="end">' + y + '</text>'; }
    var lbl = Math.ceil(g.length / 10);
    g.forEach(function (x, i) {
      var h = (x.v / top) * (H - pb - 10), bx = pg + i * bw + 1, w = Math.max(2, bw - 2), by = H - pb - h, r = Math.min(4, w / 2, h);
      var nom = periodeStats <= 30 ? dateFr(x.k) : periodeStats <= 90 ? 'semaine du ' + dateFr(x.k) : MOIS[+x.k.slice(5) - 1] + ' ' + x.k.slice(0, 4);
      var tip = esc(nom) + '|' + x.v + ' visiteur' + (x.v > 1 ? 's' : '') + '|' + x.a + ' action' + (x.a > 1 ? 's' : '') + ' (appel, itinéraire, e-mail)';
      out += '<g class="b" data-tip="' + tip + '"><rect class="hit" x="' + (pg + i * bw) + '" y="0" width="' + bw + '" height="' + (H - pb) + '"/>' +
        (h > 0 ? '<path class="barre" d="M' + bx + ',' + (H - pb) + 'V' + (by + r) + 'q0,-' + r + ' ' + r + ',-' + r + 'h' + (w - 2 * r) + 'q' + r + ',0 ' + r + ',' + r + 'V' + (H - pb) + 'z"/>' : '') + '</g>';
      if (i % lbl === 0) out += '<text x="' + (pg + i * bw + bw / 2) + '" y="' + (H - 6) + '" class="axe" text-anchor="middle">' + (periodeStats <= 90 ? (parse(x.k).getDate() + '/' + (parse(x.k).getMonth() + 1)) : MOIS[+x.k.slice(5) - 1].slice(0, 4) + '.') + '</text>';
    });
    out += '<line x1="' + pg + '" x2="' + W + '" y1="' + (H - pb) + '" y2="' + (H - pb) + '" class="base"/>';
    return '<div class="graph"><svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Visiteurs sur la période">' + out + '</svg><div class="infobulle" hidden></div></div>';
  }
  function barres(titre, obj, noms, aide) {
    var k = Object.keys(obj || {}), tot = k.reduce(function (t, x) { return t + obj[x]; }, 0), max = k.length ? obj[k[0]] : 0;
    return '<div class="bloc"><h2>' + titre + '</h2>' + (aide ? '<p class="aide petit">' + aide + '</p>' : '') +
      (k.length ? '<ul class="hbarres">' + k.map(function (x) {
        return '<li><span class="hb-nom">' + esc(noms && noms[x] || x) + '</span><span class="hb-val">' + nf(obj[x]) + ' <small>' + Math.round(obj[x] / tot * 100) + ' %</small></span><span class="hb-piste"><span style="width:' + (obj[x] / max * 100) + '%"></span></span></li>';
      }).join('') + '</ul>' : '<p class="aide petit">Pas encore de données sur cette période.</p>') + '</div>';
  }
  function colonnes(titre, vals, labels, tips) {
    var max = Math.max.apply(null, vals) || 1;
    return '<div class="bloc"><h2>' + titre + '</h2><div class="colonnes" style="--n:' + vals.length + '">' + vals.map(function (v, i) {
      return '<div class="col" data-tip="' + esc((tips ? tips[i] : labels[i]) + '|' + v + ' visiteur' + (v > 1 ? 's' : '')) + '"><span class="col-b' + (v === max && v ? ' max' : '') + '" style="height:' + (v / max * 100) + '%"></span><small>' + labels[i] + '</small></div>';
    }).join('') + '</div></div>';
  }
  // Infobulle commune aux graphiques
  app.addEventListener('mousemove', function (e) {
    var b = e.target.closest('[data-tip]'), g = e.target.closest('.graph, .colonnes');
    document.querySelectorAll('.infobulle-flottante').forEach(function (x) { if (!b) x.remove(); });
    if (!b || !g) return;
    var tip = document.querySelector('.infobulle-flottante') || document.body.appendChild(Object.assign(document.createElement('div'), { className: 'infobulle-flottante' }));
    var p = b.dataset.tip.split('|');
    tip.innerHTML = '<b>' + p[0] + '</b>' + p.slice(1).map(function (x) { return '<span>' + x + '</span>'; }).join('');
    var x = Math.min(e.clientX + 14, window.innerWidth - tip.offsetWidth - 8);
    tip.style.left = x + 'px'; tip.style.top = (e.clientY - tip.offsetHeight - 12) + 'px';
    document.querySelectorAll('.b.on, .col.on').forEach(function (z) { z.classList.remove('on'); }); b.classList.add('on');
  });
  app.addEventListener('mouseleave', function () { document.querySelectorAll('.infobulle-flottante').forEach(function (x) { x.remove(); }); }, true);

  function envoyerPhoto(input) {
    var f = input.files[0]; if (!f) return;
    var lab = input.closest('.envoyer'); lab.classList.add('envoi');
    lab.querySelector('.sans').textContent = 'Envoi…';
    var fd = new FormData(); fd.append('photo', f);
    fetch(E.api + '?api=photo', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': E.csrf }, body: fd })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) throw new Error(j.erreur || 'Envoi impossible.');
        E.photos.unshift({ nom: j.nom, url: j.url });
        set(input.dataset.envoi, j.nom); render(true);
        toast('Photo ajoutée. Pensez à enregistrer.');
      })
      .catch(function (e) { toast(e.message, true); render(true); });
  }

  // =============== Utilisateurs / Mon compte ===============
  function api(action, data) {
    return fetch(E.api + '?api=' + action, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': E.csrf }, body: JSON.stringify(data) })
      .then(function (r) { return r.json().catch(function () { return { erreur: 'Réponse inattendue du serveur.' }; }); })
      .then(function (j) { if (!j.ok) throw new Error(j.erreur || 'Erreur.'); return j; });
  }
  function dateHeure(s) { if (!s) return 'jamais'; var d = s.slice(0, 10); return dateFr(d) + ' ' + d.slice(0, 4) + (s.length > 10 ? ' à ' + s.slice(11, 16).replace(':', 'h') : ''); }
  function vueComptes() {
    var admin = E.moi.role === 'admin';
    var liste = admin ? '<section class="bloc"><div class="bloc-tete"><h2>Utilisateurs</h2><span class="compte">' + pluriel(E.comptes.length, 'compte') + '</span></div><div class="comptes-liste">' +
      E.comptes.map(function (u) {
        return '<div class="compte-ligne"><span class="avatar grand">' + esc((u.nom || u.identifiant).charAt(0).toUpperCase()) + '</span>' +
          '<div class="compte-txt"><strong>' + esc(u.nom || u.identifiant) + (u.id === E.moi.id ? ' <em>(vous)</em>' : '') + '</strong><small>' + esc(u.identifiant) + ' · ' + esc(u.email) + '</small></div>' +
          '<span class="statut statut-' + (u.role === 'admin' ? 'violet' : 'gris') + '">' + esc(E.roles[u.role]) + '</span>' +
          '<small class="compte-der">Dernière connexion : ' + dateHeure(u.derniere) + '</small>' +
          '<div class="compte-actions"><button type="button" class="btn btn-discret" data-act="compte-modif" data-id="' + u.id + '">' + ico('crayon') + 'Modifier</button>' +
          (u.id !== E.moi.id ? '<button type="button" class="ib ib-suppr" data-act="compte-suppr" data-id="' + u.id + '" title="Supprimer" aria-label="Supprimer ' + esc(u.identifiant) + '">' + ico('suppr') + '</button>' : '') + '</div></div>';
      }).join('') + '</div></section>' : '';
    return tete(admin ? 'Utilisateurs' : 'Mon compte', admin ? 'Les personnes qui peuvent se connecter au back-office, avec leur identifiant ou leur adresse e-mail. Les éditeurs gèrent le contenu ; seuls les administrateurs gèrent les comptes.' : 'Votre compte et votre mot de passe.',
        admin ? '<button type="button" class="btn btn-violet" data-act="compte-modif">' + ico('plus') + 'Nouvel utilisateur</button>' : '') + liste +
      '<section class="bloc mon-mdp"><div class="bloc-tete"><h2>Changer mon mot de passe</h2><span class="compte">' + esc(E.moi.identifiant) + ' · ' + esc(E.moi.email) + '</span></div>' +
        '<div class="grille-3"><label class="champ"><span>Mot de passe actuel</span><input type="password" id="mdp-actuel" autocomplete="current-password"></label>' +
        '<label class="champ"><span>Nouveau mot de passe</span><input type="password" id="mdp-nouveau" autocomplete="new-password" minlength="10"></label>' +
        '<label class="champ"><span>Confirmation</span><input type="password" id="mdp-conf" autocomplete="new-password"></label></div>' +
        '<p class="aide petit">10 caractères minimum, avec au moins une lettre et un chiffre.</p>' +
        '<button type="button" class="btn btn-violet" data-act="mdp">Changer le mot de passe</button></section>';
  }
  function formCompte(u) {
    var nouveau = !u; u = u || { identifiant: '', email: '', nom: '', role: 'editeur' };
    var d = document.createElement('div');
    d.className = 'modale-fond';
    d.innerHTML = '<form class="modale" role="dialog" aria-modal="true" aria-labelledby="ft"><h2 id="ft">' + (nouveau ? 'Nouvel utilisateur' : 'Modifier ' + esc(u.nom || u.identifiant)) + '</h2>' +
      '<div class="grille-2"><label class="champ"><span>Prénom ou nom</span><input name="nom" value="' + esc(u.nom) + '" placeholder="ex. Angel"></label>' +
      '<label class="champ"><span>Identifiant</span><input name="identifiant" required value="' + esc(u.identifiant) + '" placeholder="ex. angel" autocomplete="off"></label></div>' +
      '<label class="champ"><span>Adresse e-mail</span><input type="email" name="email" required value="' + esc(u.email) + '" autocomplete="off"></label>' +
      '<label class="champ"><span>Rôle</span><select name="role"' + (u.id === E.moi.id ? ' disabled' : '') + '>' + Object.keys(E.roles).map(function (r) { return '<option value="' + r + '"' + (u.role === r ? ' selected' : '') + '>' + E.roles[r] + (r === 'admin' ? ' : tout, y compris les comptes' : ' : contenu du site uniquement') + '</option>'; }).join('') + '</select></label>' +
      '<label class="champ"><span>' + (nouveau ? 'Mot de passe' : 'Nouveau mot de passe <em>laisser vide pour ne pas changer</em>') + '</span><span class="mdp-zone"><input name="mdp" type="text" autocomplete="new-password"' + (nouveau ? ' required' : '') + '><button type="button" class="voir-mdp" data-gen>Générer</button></span><small>10 caractères minimum, avec au moins une lettre et un chiffre. Communiquez-le à la personne, elle pourra le changer dans « Mon compte ».</small></label>' +
      '<p class="alerte" hidden></p><div class="modale-actions"><button type="button" class="btn btn-discret" data-r="0">Annuler</button><button class="btn btn-violet">' + (nouveau ? 'Créer le compte' : 'Enregistrer') + '</button></div></form>';
    document.body.appendChild(d);
    var f = d.querySelector('form'), al = d.querySelector('.alerte');
    f.querySelector('input').focus();
    function fin() { d.remove(); }
    d.addEventListener('click', function (e) {
      if (e.target === d || e.target.closest('[data-r="0"]')) return fin();
      if (e.target.closest('[data-gen]')) {
        var a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ', n = '23456789', s = '', r = new Uint32Array(12); crypto.getRandomValues(r);
        for (var i = 0; i < 12; i++) s += (i % 4 === 3 ? n : a).charAt(r[i] % (i % 4 === 3 ? n : a).length);
        f.mdp.value = s;
      }
    });
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      api('compte', { id: u.id || '', nom: f.nom.value, identifiant: f.identifiant.value, email: f.email.value, role: f.role.disabled ? u.role : f.role.value, mdp: f.mdp.value })
        .then(function (j) { E.comptes = j.comptes; fin(); render(true); toast(nouveau ? 'Compte créé.' : 'Compte modifié.'); })
        .catch(function (er) { al.textContent = er.message; al.hidden = false; });
    });
  }

  // =============== Affichage ===============
  var VUES = { comptes: vueComptes, stats: vueStats, evenements: vueEvenements, semaine: vueSemaine, menus: vueCartes, mots: vueMots, avis: vueAvis };
  function render(garderScroll) {
    var y = window.scrollY;
    document.querySelectorAll('.onglets [data-onglet]').forEach(function (b) {
      b.setAttribute('aria-selected', String(b.dataset.onglet === onglet));
      b.classList.toggle('modifie', sale(b.dataset.onglet));
    });
    app.innerHTML = '<div class="panneau" data-panneau="' + onglet + '">' + VUES[onglet]() + '</div>';
    if (garderScroll) window.scrollTo(0, y); else window.scrollTo(0, 0);
    barre();
    if (onglet === 'semaine') dessinerApercus();
  }
  var bar = document.getElementById('enreg'), barTxt = document.getElementById('enreg-txt');
  function barre() {
    bar.hidden = !sale(onglet);
    barTxt.textContent = 'Modifications non enregistrées sur ' + NOMS[onglet];
    document.querySelectorAll('.onglets [data-onglet]').forEach(function (b) { b.classList.toggle('modifie', sale(b.dataset.onglet)); });
  }
  function apresSaisie() {
    barre();
    if (onglet === 'semaine') { var a = document.getElementById('apercu'); if (a) a.innerHTML = apercu(); clearTimeout(apercusT); apercusT = setTimeout(dessinerApercus, 450); }
    if (onglet === 'mots') { var m = document.getElementById('mots-apercu'); if (m) m.innerHTML = motsApercu(); }
  }
  var toastT;
  function toast(msg, erreur) {
    var t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast' + (erreur ? ' erreur' : ''); t.hidden = false;
    clearTimeout(toastT); toastT = setTimeout(function () { t.hidden = true; }, erreur ? 7000 : 3200);
  }

  // Fenêtre de dialogue simple (remplace confirm)
  function demander(titre, texte, boutons) {
    return new Promise(function (ok) {
      var d = document.createElement('div');
      d.className = 'modale-fond';
      d.innerHTML = '<div class="modale" role="dialog" aria-modal="true" aria-labelledby="mt"><h2 id="mt">' + titre + '</h2><p>' + texte + '</p><div class="modale-actions">' +
        boutons.map(function (b, i) { return '<button type="button" class="btn ' + (b.cls || 'btn-discret') + '" data-r="' + i + '">' + b.txt + '</button>'; }).join('') + '</div></div>';
      document.body.appendChild(d);
      var dernier = d.querySelectorAll('button'); dernier[dernier.length - 1].focus();
      function fin(r) { d.remove(); document.removeEventListener('keydown', esc_); ok(r); }
      function esc_(e) { if (e.key === 'Escape') fin(null); }
      document.addEventListener('keydown', esc_);
      d.addEventListener('click', function (e) {
        if (e.target === d) return fin(null);
        var b = e.target.closest('[data-r]'); if (b) fin(boutons[+b.dataset.r].val);
      });
    });
  }

  // =============== Coller une liste depuis Word ===============
  function analyser(texte) {
    var lignes = texte.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
    var puce = /^[-–—•*·]\s*/;
    var modePuces = lignes.some(function (l) { return puce.test(l); });
    var out = [], cur = null;
    lignes.forEach(function (l) {
      var prix = '', m = l.match(/\s*(\d+(?:[.,]\d{1,2})?)\s*€(.*)$/);
      if (m) { prix = formatPrix(m[1]) + (m[2].trim() ? ' ' + m[2].trim() : ''); l = l.slice(0, m.index).trim(); }
      l = l.replace(/[,;:]\s*$/, '');
      var nouveau = !modePuces || puce.test(l) || !cur;
      l = l.replace(puce, '');
      if (nouveau) {
        var v = l.indexOf(', '), nom = v > 0 ? l.slice(0, v) : l, desc = v > 0 ? l.slice(v + 2) : '';
        cur = { nom: nom.charAt(0).toUpperCase() + nom.slice(1), desc: desc, prix: prix };
        out.push(cur);
      } else {
        if (l) cur.desc = cur.desc ? cur.desc + ', ' + l : l;
        if (prix) cur.prix = prix;
      }
    });
    return out.filter(function (p) { return p.nom || p.desc; });
  }
  function coller(path) {
    var d = document.createElement('div');
    d.className = 'modale-fond';
    d.innerHTML = '<div class="modale modale-large" role="dialog" aria-modal="true" aria-labelledby="ct"><h2 id="ct">Coller une liste de plats</h2>' +
      '<p>Copiez les plats depuis votre document Word et collez-les ci-dessous. Une ligne par plat ; les lignes commençant par un tiret sont reconnues, ainsi que les prix en €.</p>' +
      '<textarea id="colle" rows="8" placeholder="- Carbonnade de bœuf à la bière,\npommes de terre vapeur et chou rouge à la flamande 8.50 €\n- Blanquette de veau, petits légumes et riz pilaf 9.50 €"></textarea>' +
      '<p class="apercu-titre" id="colle-n">Aperçu</p><div class="colle-apercu" id="colle-ap"><p class="vide">Collez votre texte pour voir le résultat.</p></div>' +
      '<label class="case"><input type="checkbox" id="colle-remplacer"> Remplacer les plats déjà présents dans cette catégorie</label>' +
      '<div class="modale-actions"><button type="button" class="btn btn-discret" data-r="0">Annuler</button><button type="button" class="btn btn-violet" data-r="1" disabled>Ajouter les plats</button></div></div>';
    document.body.appendChild(d);
    var ta = d.querySelector('#colle'), ap = d.querySelector('#colle-ap'), ok = d.querySelector('[data-r="1"]'), res = [];
    ta.focus();
    ta.addEventListener('input', function () {
      res = analyser(ta.value);
      d.querySelector('#colle-n').textContent = res.length ? pluriel(res.length, 'plat') + ' reconnu' + (res.length > 1 ? 's' : '') : 'Aperçu';
      ap.innerHTML = res.length ? '<ul>' + res.map(function (p) { return '<li><strong>' + esc(p.nom) + '</strong> <span>' + esc(p.desc) + '</span><em>' + esc(p.prix) + '</em></li>'; }).join('') + '</ul>' : '<p class="vide">Collez votre texte pour voir le résultat.</p>';
      ok.disabled = !res.length;
      ok.textContent = res.length ? 'Ajouter ' + pluriel(res.length, 'plat') : 'Ajouter les plats';
    });
    function fin() { d.remove(); document.removeEventListener('keydown', echap); }
    function echap(e) { if (e.key === 'Escape') fin(); }
    document.addEventListener('keydown', echap);
    d.addEventListener('click', function (e) {
      if (e.target === d || e.target.closest('[data-r="0"]')) return fin();
      if (!e.target.closest('[data-r="1"]')) return;
      var arr = get(path);
      var vides = arr.every(function (p) { return !p.nom && !p.desc && !p.prix; });
      if (d.querySelector('#colle-remplacer').checked || vides) arr.length = 0;
      res.forEach(function (p) { arr.push(p); });
      fin(); render(true); toast(pluriel(res.length, 'plat') + ' ajouté' + (res.length > 1 ? 's' : '') + '. Vérifiez puis enregistrez.');
    });
  }

  // =============== Enregistrement ===============
  var enCours = false;
  function enregistrer(part) {
    if (enCours) return Promise.resolve(false);
    enCours = true;
    var btn = document.getElementById('enregistrer');
    btn.disabled = true; btn.textContent = 'Enregistrement…';
    return fetch(E.api + '?api=save', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': E.csrf },
      body: JSON.stringify({ part: part, data: site[part] })
    }).then(function (r) {
      return r.json().catch(function () { return { erreur: 'Réponse inattendue du serveur (' + r.status + ').' }; });
    }).then(function (j) {
      if (!j.ok) throw new Error(j.erreur || 'Erreur inconnue.');
      site[part] = j.data; saved[part] = clone(j.data);
      if (part === 'semaine') specialOuvert = !!(j.data.special.nom || j.data.special.lignes.length || j.data.special.titre);
      render(true);
      toast('Enregistré : ' + NOMS[part] + ' ' + (NOMS[part].indexOf('les ') === 0 ? 'sont' : 'est') + ' à jour sur le site.');
      return true;
    }).catch(function (e) {
      toast(e.message === 'Failed to fetch' ? 'Pas de connexion internet : rien n’a été perdu, réessayez dans un instant.' : e.message, true);
      return false;
    }).then(function (r) { enCours = false; btn.disabled = false; btn.textContent = 'Enregistrer'; return r; });
  }
  document.getElementById('enregistrer').addEventListener('click', function () { enregistrer(onglet); });
  document.getElementById('annuler').addEventListener('click', function () {
    demander('Annuler les modifications ?', 'Vous revenez à la dernière version enregistrée de ' + NOMS[onglet] + '.', [
      { txt: 'Garder mes modifications', val: false }, { txt: 'Annuler les modifications', val: true, cls: 'btn-danger' }
    ]).then(function (r) {
      if (!r) return;
      site[onglet] = clone(saved[onglet]);
      if (onglet === 'semaine') specialOuvert = !!(site.semaine.special.nom || site.semaine.special.lignes.length || site.semaine.special.titre);
      render(true);
    });
  });
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); if (sale(onglet)) enregistrer(onglet); }
  });
  window.addEventListener('beforeunload', function (e) {
    if (PARTS.some(sale)) { e.preventDefault(); e.returnValue = ''; }
  });

  // =============== Événements ===============
  document.querySelector('.onglets').addEventListener('click', function (e) {
    var b = e.target.closest('[data-onglet]'); if (!b) return;
    onglet = b.dataset.onglet; ecrire('onglet', onglet);
    if (onglet !== 'menus') carteOuverte = null;
    render();
  });

  app.addEventListener('input', function (e) {
    var t = e.target, p = t.dataset.p;
    if (!p || t.type === 'radio' || t.type === 'color' || t.type === 'checkbox') return;
    var v = t.value;
    if ('lignes' in t.dataset) v = v.split('\n');
    else if ('nombre' in t.dataset) v = +v;
    set(p, v);
    if (p === 'semaine.du' && v) {
      var au = site.semaine.au;
      if (!au || au < v) { site.semaine.au = plus(v, 4); var f = app.querySelector('[data-p="semaine.au"]'); if (f) f.value = site.semaine.au; }
    }
    if (/\.titre$/.test(p) && onglet === 'menus') {
      var r = t.closest('details'); if (r) r.querySelector('.cat-resume strong').textContent = v || 'Catégorie sans nom';
    }
    apresSaisie();
  });
  app.addEventListener('change', function (e) {
    var t = e.target;
    if (t.type === 'radio' && t.dataset.p) { set(t.dataset.p, t.value); apresSaisie(); render(true); return; }
    if (t.type === 'checkbox' && t.dataset.p) { set(t.dataset.p, t.checked); render(true); return; }
    if (t.type === 'color' && t.dataset.p) { set(t.dataset.p, t.value); render(true); return; }
    if (t.type === 'file' && t.dataset.envoi) { envoyerPhoto(t); return; }
    if ('prix' in t.dataset && t.dataset.p) { var f = formatPrix(t.value); if (f !== t.value) { t.value = f; set(t.dataset.p, f); apresSaisie(); } }
    if (t.dataset.p && /^semaine\.(du|au)$/.test(t.dataset.p)) {
      var st = statutSemaine(site.semaine), el = document.getElementById('statut-sem');
      if (el) { el.className = 'statut statut-' + st[0]; el.textContent = st[1]; }
    }
    if (t.matches('select[data-p$=".jour"]')) apresSaisie();
  });
  // Entrée sur le prix du dernier plat : ajoute une ligne
  app.addEventListener('keydown', function (e) {
    var t = e.target;
    if (e.key !== 'Enter' || t.tagName !== 'INPUT') return;
    e.preventDefault();
    if (t.dataset.dernier) {
      t.dispatchEvent(new Event('change', { bubbles: true }));
      get(t.dataset.dernier).push(MODELES.plat());
      render(true);
      var l = app.querySelectorAll('[data-p^="' + t.dataset.dernier + '."][data-p$=".nom"]');
      if (l.length) l[l.length - 1].focus();
      return;
    }
    var champs = Array.prototype.slice.call(app.querySelectorAll('input:not([type=radio]):not([type=checkbox]),select,textarea'));
    var s = champs[champs.indexOf(t) + 1]; if (s) s.focus();
  });
  app.addEventListener('toggle', function (e) {
    var d = e.target; if (d.tagName !== 'DETAILS') return;
    if (d.open) catsOuvertes[d.dataset.cle] = true; else delete catsOuvertes[d.dataset.cle];
  }, true);

  app.addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]'); if (!b) return;
    var a = b.dataset.act, p = b.dataset.p, i = +b.dataset.i, arr = p ? get(p) : null;
    if (a === 'add') {
      arr.push(MODELES[b.dataset.tpl](arr));
      if (b.dataset.tpl === 'carte') { carteOuverte = arr.length - 1; catsOuvertes[carteOuverte + '-0'] = true; render(); }
      else {
        if (b.dataset.tpl === 'cat') catsOuvertes[carteOuverte + '-' + (arr.length - 1)] = true;
        render(true);
        var champs = app.querySelectorAll('[data-p^="' + p + '.' + (arr.length - 1) + '."]:not([type=radio]):not([type=checkbox])');
        var cible = Array.prototype.filter.call(champs, function (x) { return x.tagName !== 'SELECT'; })[0] || champs[0];
        if (cible) cible.focus();
      }
    } else if (a === 'del') {
      var vide = JSON.stringify(arr[i]).replace(/"[a-z]+":|[\[\]{},"]/g, '') === '';
      (vide || !b.dataset.quoi ? Promise.resolve(true) : demander('Supprimer ?', 'Vous allez supprimer ' + esc(b.dataset.quoi) + '. Vous pourrez encore annuler tant que vous n’avez pas enregistré.', [
        { txt: 'Garder', val: false }, { txt: 'Supprimer', val: true, cls: 'btn-danger' }
      ])).then(function (r) {
        if (!r) return;
        arr.splice(i, 1);
        if (p === 'menus') catsOuvertes = {};
        render(true);
      });
    } else if (a === 'up' || a === 'down') {
      var j = a === 'up' ? i - 1 : i + 1;
      if (j < 0 || j >= arr.length) return;
      arr.splice(j, 0, arr.splice(i, 1)[0]);
      if (/\.cats$/.test(p)) catsOuvertes = {};
      render(true);
      var bt = app.querySelector('[data-act="' + a + '"][data-p="' + p + '"][data-i="' + j + '"]');
      if (bt && !bt.disabled) bt.focus();
    } else if (a === 'ouvrir') {
      carteOuverte = i; catsOuvertes = {}; render();
    } else if (a === 'retour') {
      carteOuverte = null; render();
    } else if (a === 'dup') {
      var copie = clone(site.menus[i]); copie.titre += ' (copie)';
      site.menus.splice(i + 1, 0, copie); render(true);
    } else if (a === 'tout-ouvrir' || a === 'tout-fermer') {
      app.querySelectorAll('details.cat-bloc').forEach(function (d) { d.open = a === 'tout-ouvrir'; });
    } else if (a === 'coller') {
      coller(p);
    } else if (a === 'special') {
      specialOuvert = b.checked;
      if (!specialOuvert) site.semaine.special = { titre: '', nom: '', prix: '', lignes: [] };
      render(true);
    } else if (a === 'compte-modif') {
      formCompte(E.comptes.filter(function (u) { return u.id === b.dataset.id; })[0]);
    } else if (a === 'compte-suppr') {
      var cu = E.comptes.filter(function (u) { return u.id === b.dataset.id; })[0];
      demander('Supprimer ce compte ?', esc(cu.nom || cu.identifiant) + ' ne pourra plus se connecter au back-office.', [{ txt: 'Garder', val: false }, { txt: 'Supprimer', val: true, cls: 'btn-danger' }])
        .then(function (r) { if (r) api('compte-suppr', { id: cu.id }).then(function (j) { E.comptes = j.comptes; render(true); toast('Compte supprimé.'); }).catch(function (er) { toast(er.message, true); }); });
    } else if (a === 'mdp') {
      var n1 = document.getElementById('mdp-nouveau').value;
      if (n1 !== document.getElementById('mdp-conf').value) return toast('Les deux nouveaux mots de passe ne sont pas identiques.', true);
      api('mdp', { actuel: document.getElementById('mdp-actuel').value, nouveau: n1 })
        .then(function () { render(true); toast('Mot de passe changé.'); }).catch(function (er) { toast(er.message, true); });
    } else if (a === 'couleur') {
      set(p, b.dataset.c); render(true);
    } else if (a === 'visuel') {
      b.disabled = true;
      Visuels.image(b.dataset.format, donneesVisuels())
        .then(function () { toast('Image téléchargée : retrouvez-la dans vos Téléchargements.'); })
        .catch(function (e) { toast(e.message || 'Impossible de créer l’image.', true); })
        .then(function () { b.disabled = false; });
    } else if (a === 'video-apercu') {
      var cv = b.closest('.visuel').querySelector('canvas');
      b.disabled = true;
      Visuels.jouer(cv, donneesVisuels()).then(function () { b.disabled = false; });
    } else if (a === 'video') {
      var carteV = b.closest('.visuel'), txt = b.textContent;
      carteV.classList.add('en-cours');
      app.querySelectorAll('[data-act=video],[data-act=video-apercu]').forEach(function (x) { x.disabled = true; });
      Visuels.video(donneesVisuels(), function (p) { b.textContent = 'Création… ' + Math.round(p * Visuels.DUREE) + ' s / ' + Visuels.DUREE; })
        .then(function (ext) { toast('Vidéo ' + ext.toUpperCase() + ' téléchargée : retrouvez-la dans vos Téléchargements.'); })
        .catch(function (e) { toast(e.message || 'Impossible de créer la vidéo.', true); })
        .then(function () { b.textContent = txt; carteV.classList.remove('en-cours'); app.querySelectorAll('[data-act=video],[data-act=video-apercu]').forEach(function (x) { x.disabled = false; }); });
    } else if (a === 'periodeStats') {
      periodeStats = i; ecrire('periodeStats', String(i)); render(true);
    } else if (a === 'dup-evt') {
      var ce = clone(site.evenements[i]); ce.titre += ' (copie)'; ce.actif = false;
      site.evenements.splice(i + 1, 0, ce); render(true);
    } else if (a === 'semaine-suivante') {
      semaineSuivante();
    } else if (a === 'imprimer') {
      ouvrirApres('semaine', 'affiche');
    } else if (a === 'imprimer-carte') {
      ouvrirApres('menus', 'carte-traiteur?c=' + b.dataset.i);
    } else if (a === 'aller') {
      var cible = document.getElementById(b.dataset.cible);
      if (cible) cible.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });

  // Ouvre une page à imprimer, après avoir enregistré les modifications en cours (elle lit les données enregistrées)
  function ouvrirApres(part, url) {
    var w = window.open('about:blank', '_blank');
    (sale(part) ? enregistrer(part) : Promise.resolve(true)).then(function (ok) {
      if (!w) return toast('Autorisez les fenêtres pop-up pour imprimer.', true);
      if (ok) w.location.href = url; else w.close();
    });
  }
  function semaineSuivante() {
    var s = site.semaine;
    var du = s.du ? plus(s.du, 7) : '', au = s.au ? plus(s.au, 7) : (du ? plus(du, 4) : '');
    demander('Préparer la semaine suivante ?', 'Les dates passent ' + (du ? periode(du, au) : 'à la semaine suivante') + '. Les jours sont conservés ; que faire des plats ?', [
      { txt: 'Annuler', val: null },
      { txt: 'Garder les plats', val: 'garder', cls: 'btn-ligne' },
      { txt: 'Vider les plats', val: 'vider', cls: 'btn-violet' }
    ]).then(function (r) {
      if (!r) return;
      s.du = du; s.au = au;
      if (r === 'vider') {
        s.jours.forEach(function (j) { j.nom = ''; j.desc = ''; j.prix = ''; j.ferme = false; j.motif = ''; j.message = ''; });
        s.special = { titre: '', nom: '', prix: '', lignes: [] }; specialOuvert = false;
      }
      render(true);
      var f = app.querySelector('[data-p="semaine.jours.0.nom"]'); if (f && r === 'vider') f.focus();
      toast('Semaine suivante prête : saisissez les plats puis enregistrez.');
    });
  }

  if (!VUES[onglet]) onglet = 'stats';
  render();
})();
