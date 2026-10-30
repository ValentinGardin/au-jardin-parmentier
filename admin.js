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
  var NOMS = { evenements: 'les événements', semaine: 'le menu de la semaine', semaine_suivante: 'le menu de la semaine suivante', menus: 'les cartes traiteur', mots: 'les mots défilants', avis: 'les avis' };
  var onglet = lire('onglet') || 'resas';
  var PARTS = ['evenements', 'semaine', 'semaine_suivante', 'menus', 'mots', 'avis'];
  var SEM = lire('sem') === 'semaine_suivante' ? 'semaine_suivante' : 'semaine';   // semaine affichée dans l'onglet Menu de la semaine
  function partActive() { return onglet === 'semaine' ? SEM : onglet; }
  function aSpecial(s) { return !!(s.special.nom || s.special.lignes.length || s.special.titre); }
  var VISITE = E.moi.role === 'visiteur';   // compte de démonstration : on peut tout essayer, rien n'est enregistré
  var carteOuverte = null;          // index de la carte en cours de modification
  var catsOuvertes = {};            // catégories dépliées : { "carte-cat": true }
  var specialOuvert = aSpecial(site[SEM]);

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
    if (s === site.semaine_suivante) {
      if (!s.du) return ['gris', 'Dates à renseigner'];
      return s.publie ? ['vert', 'Publiée : visible sur le site, en ligne le ' + dateFr(s.du)] : ['gris', 'Brouillon : remplacera le menu actuel le ' + dateFr(s.du)];
    }
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
    var s = site[SEM], st = statutSemaine(s), n = s.jours.length;
    var jours = s.jours.map(function (j, i) {
      var p = SEM + '.jours.' + i;
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
        boutonsOrdre(SEM + '.jours', i, n) + '</div>';
    }).join('');
    var sp = specialOuvert
      ? '<div class="grille-2">' + champ('Quand', SEM + '.special.titre', { ph: 'ex. Vendredi et samedi' }) + champ('Prix', SEM + '.special.prix', { ph: '19.00 €', prix: true }) + '</div>' +
        champ('Nom de l’offre', SEM + '.special.nom', { ph: 'ex. Plateau apéritif « Halloween »' }) +
        champ('Contenu', SEM + '.special.lignes', { lignes: true, rows: 4, ph: 'Une ligne par élément\nex. Tapenade aux olives noires et ses gressins', aide: 'Une ligne par élément.' })
      : '';
    var cur = site.semaine, nx = site.semaine_suivante;
    var choix = '<div class="choix-sem" role="tablist" aria-label="Semaine à modifier">' +
      '<button type="button" role="tab" data-act="sem" data-s="semaine" aria-selected="' + (SEM === 'semaine') + '"><b>Semaine en cours</b><small>' + esc(cur.du ? periode(cur.du, cur.au) : 'dates à renseigner') + '</small></button>' +
      '<button type="button" role="tab" data-act="sem" data-s="semaine_suivante" aria-selected="' + (SEM === 'semaine_suivante') + '"><b>Semaine suivante</b><small>' +
        (nx.du ? esc(periode(nx.du, nx.au)) + ' · ' + (nx.publie ? 'publiée' : 'brouillon') : 'à préparer') + '</small></button></div>';
    var publication = SEM === 'semaine_suivante'
      ? '<section class="bloc bloc-publication' + (s.publie ? ' actif' : '') + '"><div class="bloc-tete"><h2>Publication</h2>' +
          '<label class="interrupteur"><input type="checkbox" data-p="semaine_suivante.publie"' + (s.publie ? ' checked' : '') + '><span></span>' + (s.publie ? 'Publiée en avance' : 'Brouillon') + '</label></div>' +
          '<p class="aide">' + (s.publie
            ? 'Visible dès maintenant sur le site grâce au bouton « Semaine suivante », à côté des dates du menu actuel.'
            : 'Préparez le menu tranquillement : il n’est pas visible sur le site. Activez « Publiée en avance » pour l’afficher avant le début de la semaine.') +
          (s.du ? ' Dans tous les cas, il <b>remplacera automatiquement le menu actuel le ' + esc(dateFr(s.du)) + '</b>, sans rien faire.' : '') + '</p>' +
          '<div class="g-actions"><button type="button" class="btn btn-ligne" data-act="copier-semaine">' + ico('copie') + 'Reprendre les plats de la semaine en cours</button>' +
          '<button type="button" class="lien" data-act="vider-semaine">Tout effacer</button></div></section>'
      : '';
    return tete('Menu de la semaine', SEM === 'semaine' ? 'Les plats du jour affichés sur le site. Le plat du jour est mis en avant automatiquement.' : 'Préparez la semaine prochaine à l’avance : affiche, publications et vidéo sont disponibles comme pour la semaine en cours.',
        (SEM === 'semaine' ? '<button type="button" class="btn btn-discret" data-act="sem" data-s="semaine_suivante">' + ico('suivant') + 'Préparer la semaine suivante</button>' : '') +
        '<button type="button" class="btn btn-ligne" data-act="aller" data-cible="visuels">' + ico('imprim') + 'Visuels et affiche</button>') +
      choix +
      '<div class="deux-cols"><div class="col-form">' + publication +
        '<section class="bloc"><div class="bloc-tete"><h2>1. La semaine</h2><span class="statut statut-' + st[0] + '" id="statut-sem">' + st[1] + '</span></div>' +
          '<div class="grille-3">' + champ('Du', SEM + '.du', { type: 'date' }) + champ('Au', SEM + '.au', { type: 'date' }) + champ('Titre', SEM + '.titre', { ph: 'Inspirations de la semaine' }) + '</div></section>' +
        '<section class="bloc bloc-conges' + (s.conges.actif ? ' actif' : '') + '"><div class="bloc-tete"><h2>Fermeture de la semaine <em>congés, travaux…</em></h2>' +
          '<label class="interrupteur"><input type="checkbox" data-p="' + SEM + '.conges.actif"' + (s.conges.actif ? ' checked' : '') + '><span></span>' + (s.conges.actif ? 'Activée' : 'Désactivée') + '</label></div>' +
          (s.conges.actif
            ? '<p class="alerte-info">Le menu est remplacé sur le site par ce message. Les plats restent enregistrés pour plus tard.</p>' +
              champ('Titre', SEM + '.conges.titre', { ph: 'Fermeture pour congés' }) +
              champ('Message', SEM + '.conges.message', { zone: true, rows: 3, ph: 'ex. Nous sommes en congés du 24 décembre au 4 janvier. Réouverture le mardi 5 janvier, à très vite !' })
            : '<p class="aide">À activer si la boutique est fermée toute la semaine : un titre et un message remplacent le menu sur le site et sur les visuels. Pour un seul jour (férié…), utilisez plutôt le bouton « Fermé » du jour concerné.</p>') + '</section>' +
        '<div class="' + (s.conges.actif ? 'attenue' : '') + '">' +
        '<section class="bloc"><div class="bloc-tete"><h2>2. Les plats du jour</h2><span class="compte">' + pluriel(n, 'jour') + '</span></div>' +
          '<div class="jours-liste">' + (jours || '<p class="vide">Aucun jour. Ajoutez le premier plat.</p>') + '</div>' +
          '<button type="button" class="btn-ajout" data-act="add" data-p="' + SEM + '.jours" data-tpl="jour">' + ico('plus') + 'Ajouter un jour</button></section>' +
        '<section class="bloc"><div class="bloc-tete"><h2>3. Offre spéciale <em>facultatif</em></h2>' +
          '<label class="interrupteur"><input type="checkbox" data-act="special"' + (specialOuvert ? ' checked' : '') + '><span></span>' + (specialOuvert ? 'Affichée' : 'Masquée') + '</label></div>' +
          (specialOuvert ? sp : '<p class="aide">Un plateau ou une formule pour quelques jours (ex. plateau apéritif du week-end). Elle apparaît dans un encadré à côté des plats.</p>') + '</section>' +
        '<section class="bloc">' + champ('Petite note sous le menu', SEM + '.note', { opt: true, ph: 'ex. Commandes la veille avant 18 h' }) + '</section>' +
        '</div>' +
        vueVisuels() +
      '</div><aside class="col-apercu"><p class="apercu-titre">Aperçu de l’affiche</p><div class="affiche-mini" id="apercu">' + apercu() + '</div></aside></div>';
  }
  // ---------- Visuels à télécharger (dessinés par visuels.js) ----------
  function donneesVisuels() {
    var s = site[SEM];
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
    var s = site[SEM], sp = s.special;
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
    if (!G) chargerGoogle();
    return tete('Avis', 'La note Google et vos avis clients, affichés dans la section « Avis » du site.') +
      '<section class="bloc" id="google">' + (G ? blocGoogle() : '<p class="chargement">Chargement…</p>') + '</section>' +
      '<section class="bloc"><div class="bloc-tete"><h2>Avis choisis à la main <em>facultatif</em></h2>' +
        '<button type="button" class="btn btn-ligne" data-act="add" data-p="avis" data-tpl="avis">' + ico('plus') + 'Ajouter un avis</button></div>' +
        '<p class="aide petit">Pour mettre en avant un avis précis (Google, Facebook, livre d’or…). Ils s’affichent après les avis Google.</p>' +
        (liste || '<p class="vide">Aucun avis ajouté à la main.</p>') + '</section>';
  }

  // ---------- Avis Google ----------
  var G = null, gResultats = null;
  function chargerGoogle() {
    fetch(E.api + '?api=google', { credentials: 'same-origin' }).then(function (r) { return r.json(); })
      .then(function (j) { G = j; majGoogle(); }).catch(function () {});
  }
  function majGoogle() { var z = document.getElementById('google'); if (z && onglet === 'avis') z.innerHTML = blocGoogle(); }
  function etoiles(n) { var p = Math.round((n || 0) / 5 * 100); return '<span class="g-etoiles"><span>★★★★★</span><span style="width:' + p + '%">★★★★★</span></span>'; }
  function blocGoogle() {
    var admin = E.moi.role === 'admin', h = '<div class="bloc-tete"><h2>Avis Google</h2>';
    if (VISITE && G.cle && G.place_id) return h + '<span class="statut statut-vert">Connecté</span></div><div class="g-resume"><div class="g-note">' + (G.note ? String(G.note).replace('.', ',') : '–') + '</div><div>' + etoiles(G.note) + '<p><strong>' + (G.nombre || 0) + ' avis</strong> · ' + esc(G.nom) + '</p><small>Mis à jour automatiquement chaque jour.</small></div></div>';
    if (VISITE) return h + '</div><p class="aide">La note Google s’affiche ici une fois Google connecté par un administrateur.</p>';
    if (!G.cle) {
      if (!admin) return h + '</div><p class="aide">Pas encore configuré. Un administrateur peut le faire depuis cet onglet.</p>';
      return h + '<span class="statut statut-gris">Non configuré</span></div>' +
        '<p class="aide">Affichez automatiquement votre <b>note Google</b>, le <b>nombre d’avis</b> et vos meilleurs avis, mis à jour chaque jour.</p>' +
        '<ol class="etapes"><li><b>Étape 1 :</b> créez votre clé d’accès Google (Claude vous guide pas à pas).</li><li><b>Étape 2 :</b> collez-la ci-dessous. Elle reste sur votre serveur, elle n’est jamais visible sur le site.</li><li><b>Étape 3 :</b> choisissez votre commerce dans la liste.</li></ol>' +
        '<div class="g-ligne"><label class="champ"><span>Clé d’accès Google</span><input type="password" id="g-cle" placeholder="AIza…" autocomplete="off" spellcheck="false"></label>' +
        '<button type="button" class="btn btn-violet" data-act="g-cle">Enregistrer la clé</button></div>';
    }
    if (!G.place_id) {
      if (!admin) return h + '</div><p class="aide">Configuration en cours par un administrateur.</p>';
      return h + '<span class="statut statut-bleu">Clé enregistrée ' + esc(G.cle) + '</span></div>' +
        '<p class="aide">Dernière étape : retrouvez votre commerce sur Google.</p>' +
        '<div class="g-ligne"><label class="champ"><span>Nom et ville du commerce</span><input id="g-q" value="Au Jardin Parmentier Montdidier"></label>' +
        '<button type="button" class="btn btn-violet" data-act="g-recherche">Rechercher</button></div>' +
        (gResultats ? (gResultats.length ? '<ul class="g-resultats">' + gResultats.map(function (r, i) {
          return '<li><div><strong>' + esc(r.nom) + '</strong><small>' + esc(r.adresse) + '</small>' + (r.note ? '<small>' + etoiles(r.note) + ' ' + String(r.note).replace('.', ',') + ' · ' + r.nombre + ' avis</small>' : '') + '</div>' +
            '<button type="button" class="btn btn-violet" data-act="g-choisir" data-i="' + i + '">C’est mon commerce</button></li>';
        }).join('') + '</ul>' : '<p class="vide">Aucun résultat. Essayez avec l’adresse complète.</p>') : '') +
        '<button type="button" class="lien" data-act="g-oublier">Changer de clé</button>';
    }
    return h + (G.erreur ? '<span class="statut statut-orange">Problème de mise à jour</span>' : '<span class="statut statut-vert">Connecté</span>') + '</div>' +
      '<div class="g-resume"><div class="g-note">' + (G.note ? String(G.note).replace('.', ',') : '–') + '</div><div>' + etoiles(G.note) +
        '<p><strong>' + (G.nombre || 0) + ' avis</strong> · ' + esc(G.nom) + '</p><small>' + esc(G.adresse) + '</small>' +
        '<small>' + (G.maj ? 'Mis à jour le ' + esc(G.maj) + ' · ' + G.nb_avis + ' avis récupérés (Google en fournit 5 au maximum)' : 'Pas encore mis à jour') + '</small></div></div>' +
      (G.erreur ? '<p class="alerte">' + esc(G.erreur) + '</p>' : '') +
      (admin ? '<div class="g-options">' +
        '<label class="interrupteur"><input type="checkbox" data-g="afficher_note"' + (G.afficher_note ? ' checked' : '') + '><span></span>Afficher la note et le nombre d’avis</label>' +
        '<label class="interrupteur"><input type="checkbox" data-g="afficher_avis"' + (G.afficher_avis ? ' checked' : '') + '><span></span>Afficher les avis Google</label>' +
        '<label class="champ g-min"><span>Seulement les avis de</span><select data-g="note_min"' + (G.afficher_avis ? '' : ' disabled') + '>' + [5, 4, 3, 1].map(function (v) {
          return '<option value="' + v + '"' + (G.note_min === v ? ' selected' : '') + '>' + (v === 1 ? 'toutes les notes' : v + ' étoiles' + (v < 5 ? ' et plus' : '')) + '</option>'; }).join('') + '</select></label></div>' : '') +
      '<div class="g-actions"><button type="button" class="btn btn-ligne" data-act="g-actualiser">Mettre à jour maintenant</button>' +
        (admin ? '<button type="button" class="lien" data-act="g-changer">Changer de commerce</button><button type="button" class="lien" data-act="g-oublier">Déconnecter Google</button>' : '') + '</div>';
  }
  function actionGoogle(a, b) {
    function ok(j) { G = j; gResultats = null; majGoogle(); }
    function ko(e) { toast(e.message, true); majGoogle(); }
    if (a === 'g-cle') {
      var v = document.getElementById('g-cle').value.trim();
      if (!v) return toast('Collez d’abord la clé.', true);
      api('google-cle', { cle: v }).then(function (j) { ok(j); toast('Clé enregistrée.'); }).catch(ko);
    } else if (a === 'g-recherche') {
      b.disabled = true; b.textContent = 'Recherche…';
      api('google-recherche', { q: document.getElementById('g-q').value }).then(function (j) { gResultats = j.resultats; majGoogle(); }).catch(ko);
    } else if (a === 'g-choisir') {
      var r = gResultats[+b.dataset.i]; b.disabled = true; b.textContent = 'Connexion…';
      api('google-choisir', { id: r.id, nom: r.nom, adresse: r.adresse }).then(function (j) { ok(j); toast('C’est fait : la note Google est sur le site.'); }).catch(ko);
    } else if (a === 'g-actualiser') {
      b.disabled = true; b.textContent = 'Mise à jour…';
      api('google-actualiser', {}).then(function (j) { ok(j); toast('Avis Google mis à jour.'); }).catch(ko);
    } else if (a === 'g-changer') {
      G.place_id = ''; gResultats = null; majGoogle();
    } else if (a === 'g-oublier') {
      demander('Déconnecter Google ?', 'La note et les avis Google ne seront plus affichés sur le site. Vous pourrez reconnecter Google plus tard.', [{ txt: 'Annuler', val: false }, { txt: 'Déconnecter', val: true, cls: 'btn-danger' }])
        .then(function (o) { if (o) api('google-oublier', {}).then(ok).catch(ko); });
    }
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
    return tete('Statistiques', 'La fréquentation de votre site et ce que font vos visiteurs. Mesure anonyme, sans cookie.', '<div class="periodes" role="group" aria-label="Période">' + chips + '</div>') +
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
    if (VISITE) { input.value = ''; return toast('Mode visite : l’envoi de photos est désactivé.'); }
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
    if (VISITE) return tete('Mon compte', '') + '<section class="bloc"><p><b>Compte visiteur</b> (' + esc(E.moi.email) + ')</p><p class="aide">Ce compte sert à découvrir le back-office : vous pouvez parcourir tous les onglets et essayer les champs, mais aucune modification n’est enregistrée sur le site.</p></section>';
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
      '<label class="champ"><span>Rôle</span><select name="role"' + (u.id === E.moi.id ? ' disabled' : '') + '>' + Object.keys(E.roles).map(function (r) { return '<option value="' + r + '"' + (u.role === r ? ' selected' : '') + '>' + E.roles[r] + ({ admin: ' : tout, y compris les comptes', editeur: ' : contenu du site uniquement', visiteur: ' : découverte, aucune modification possible' }[r] || '') + '</option>'; }).join('') + '</select></label>' +
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

  // =============== Réservations ===============
  // Une journée à la fois : qui a commandé quoi, payé ou non, récupéré ou non. Tout se fait en un clic.
  var JC = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
  var R = { date: E.aujourdhui, filtre: 'tout', q: '', data: null, charge: '' };
  var CL = null, LETTRE = null, clFiltre = 'tout', clQ = '';
  function lundi(s) { var d = parse(s), l = (d.getDay() + 6) % 7; d.setDate(d.getDate() - l); return iso(d); }
  function jourNom(s) { return JOURS[(parse(s).getDay() + 6) % 7]; }
  function dateLongue(s) { return jourNom(s) + ' ' + dateFr(s) + (s.slice(0, 4) !== E.aujourdhui.slice(0, 4) ? ' ' + s.slice(0, 4) : ''); }
  function prixNum(p) { var m = String(p || '').replace(',', '.').match(/\d+(\.\d+)?/); return m ? +m[0] : 0; }
  function euros(n) { return n.toFixed(2) + ' €'; }
  function totalResa(r) { return r.lignes.reduce(function (t, l) { return t + prixNum(l.prix) * l.qte; }, 0); }
  function sansAccent(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
  function nomClient(c) { return ((c.prenom || '') + ' ' + (c.nom || '')).trim(); }
  function heure(s) { return s ? s.slice(11, 16).replace(':', 'h') : ''; }
  // « 2026-10-30 10:12 · Valentin » → « aujourd’hui à 10h12 par Valentin »
  function quand(s) {
    if (!s) return '';
    var p = s.split(' · '), d = p[0].slice(0, 10);
    return (d === E.aujourdhui ? 'aujourd’hui' : 'le ' + dateFr(d)) + ' à ' + heure(p[0]) + (p[1] ? ' par ' + p[1] : '');
  }
  // Plat du jour et offre spéciale prévus à une date (menu de la semaine en cours ou suivante, version enregistrée)
  function menuDu(date) {
    var out = { plat: null, special: null };
    ['semaine', 'semaine_suivante'].forEach(function (k) {
      var s = saved[k];
      if (!s || !s.du || date < s.du || (s.au && date > s.au) || s.conges.actif) return;
      var j = s.jours.filter(function (x) { return sansAccent(x.jour) === sansAccent(jourNom(date)); })[0];
      if (j && j.nom && !j.ferme && !out.plat) out.plat = j;
      if (s.special.nom && !out.special) out.special = s.special;
    });
    return out;
  }
  function getJSON(q) {
    return fetch(E.api + '?api=' + q, { credentials: 'same-origin' }).then(function (r) { return r.json(); })
      .then(function (j) { if (!j.ok) throw new Error(j.erreur || 'Erreur.'); return j; });
  }
  function chargerResas() {
    var du = lundi(R.date);
    if (R.charge === du) return;
    R.charge = du;
    getJSON('resas&du=' + du + '&au=' + plus(du, 6)).then(function (j) {
      if (lundi(R.date) !== du) return;
      if (R.data && R.data.du === du) {
        var connues = R.data.resas.map(function (r) { return r.id; });
        j.resas.filter(function (r) { return r.source === 'site' && connues.indexOf(r.id) < 0; }).forEach(function (r) {
          toast('Nouvelle réservation en ligne : n° ' + r.num + ', ' + (nomClient(r.c) || 'client') + ' pour ' + dateLongue(r.date).toLowerCase() + '.');
        });
      }
      R.data = j; if (onglet === 'resas') majResas();
    }).catch(function (e) { R.charge = ''; toast(e.message, true); });
  }
  function chargerClients() {
    return getJSON('clients').then(function (j) { CL = j.clients; LETTRE = j.lettre; if (onglet === 'clients') majClients(); return j; })
      .catch(function (e) { toast(e.message, true); });
  }
  function majResas() { var z = document.getElementById('resas'); if (z) z.innerHTML = resasContenu(); }
  function majListe() { var z = document.getElementById('r-liste'); if (z) z.innerHTML = resasListe(); }

  function vueResas() {
    if (!R.data || R.data.du !== lundi(R.date) || !R.charge) chargerResas();
    return tete('Réservations', 'Les plats réservés, jour par jour. Un clic pour noter qu’une commande est payée, puis récupérée.',
      '<button type="button" class="btn btn-ligne" data-act="r-imprimer">' + ico('imprim') + 'Imprimer la liste</button>' +
      '<button type="button" class="btn btn-violet" data-act="resa-nouvelle">' + ico('plus') + 'Nouvelle réservation</button>') +
      blocCommande() + '<div id="resas">' + resasContenu() + '</div>';
  }
  // Interrupteur « réservation en ligne » (page d'accueil du site)
  function blocCommande() {
    var c = E.commande, lect = VISITE ? ' disabled' : '';
    return '<div class="cmd-bloc' + (c.actif ? ' actif' : '') + '"><label class="interrupteur grand"><input type="checkbox" data-cmd="actif"' + (c.actif ? ' checked' : '') + lect + '><span></span>Réservation en ligne sur le site</label>' +
      '<span class="statut statut-' + (c.actif ? (c.jours ? 'vert">Ouverte · ' + pluriel(c.jours, 'jour') + ' réservable' + (c.jours > 1 ? 's' : '') : 'orange">Ouverte, mais aucun jour réservable (menu à remplir ?)') : 'gris">Fermée : les clients appellent') + '</span>' +
      '<label class="cmd-limite">Jusqu’à la veille à <input type="time" data-cmd="limite" value="' + esc(c.limite) + '"' + lect + '></label>' +
      '<a class="lien" href="./#reserver" target="_blank" rel="noopener">Voir sur le site ↗</a></div>';
  }
  function resasDuJour() { return R.data && R.data.du === lundi(R.date) ? R.data.resas.filter(function (r) { return r.date === R.date; }) : null; }
  function resasContenu() {
    var du = lundi(R.date), toutes = R.data && R.data.du === du ? R.data.resas : [];
    var jours = '';
    for (var i = 0; i < 7; i++) {
      var d = plus(du, i), n = toutes.filter(function (r) { return r.date === d; }).length, pd = menuDu(d).plat;
      jours += '<button type="button" class="r-jour' + (d === R.date ? ' actif' : '') + (d === E.aujourdhui ? ' auj' : '') + (pd ? '' : ' sans-plat') + '" data-act="r-date" data-d="' + d + '" title="' + esc(dateLongue(d) + (pd ? ' · ' + pd.nom : '')) + '">' +
        '<span>' + JC[parse(d).getDay()] + '</span><b>' + parse(d).getDate() + '</b>' + (n ? '<em>' + n + '</em>' : '') + '</button>';
    }
    var semaine = '<div class="r-semaine"><button type="button" class="ib" data-act="r-date" data-d="' + plus(du, -7) + '" title="Semaine précédente" aria-label="Semaine précédente">' + ico('retour') + '</button>' +
      '<div class="r-jours">' + jours + '</div>' +
      '<button type="button" class="ib" data-act="r-date" data-d="' + plus(du, 7) + '" title="Semaine suivante" aria-label="Semaine suivante">' + ico('suivant') + '</button>' +
      '<div class="r-aller">' + (R.date !== E.aujourdhui ? '<button type="button" class="btn btn-discret" data-act="r-date" data-d="' + E.aujourdhui + '">Aujourd’hui</button>' : '') +
      '<label class="r-cal" title="Aller à une date">' + ico('cal') + '<input type="date" id="r-cal" value="' + R.date + '" aria-label="Aller à une date"></label></div></div>';
    var m = menuDu(R.date);
    var titre = '<div class="r-titre"><h2>' + (R.date === E.aujourdhui ? 'Aujourd’hui · ' : '') + esc(dateLongue(R.date)) + '</h2><p>' +
      (m.plat ? 'Plat du jour : <b>' + esc(m.plat.nom) + '</b>' + (m.plat.prix ? ' · ' + esc(m.plat.prix) : '') : 'Pas de plat du jour prévu dans le menu de la semaine.') + '</p>' +
      (R.data && R.data.sms && R.data.sms.auto && R.date === E.aujourdhui ? '<p class="r-sms-info">' + ico('note') + 'Rappel SMS automatique à ' + R.data.sms.heure.replace(':', 'h') + ' pour les commandes pas encore récupérées.</p>' : '') + '</div>';
    var jour = resasDuJour();
    if (!jour) return semaine + titre + '<p class="chargement">Chargement…</p>';
    var total = 0, encaisse = 0, nbP = 0, nbR = 0, nbPlats = 0;
    jour.forEach(function (r) {
      var t = totalResa(r); total += t;
      if (r.paye) { nbP++; encaisse += t; }
      if (r.recupere) nbR++;
      r.lignes.forEach(function (l) { nbPlats += l.qte; });
    });
    var n = jour.length;
    var resume = n ? '<div class="r-resume">' +
      '<div class="tuile"><p>Commandes</p><strong>' + n + '</strong><small>' + pluriel(nbPlats, 'plat') + (total ? ' · ' + euros(total) : '') + '</small></div>' +
      '<div class="tuile"><p>Payées</p><strong>' + nbP + '<em>/' + n + '</em></strong><small>' + (total ? euros(encaisse) + ' encaissés sur ' + euros(total) : (n - nbP ? (n - nbP) + ' à encaisser' : 'tout est réglé')) + '</small></div>' +
      '<div class="tuile"><p>Récupérées</p><strong>' + nbR + '<em>/' + n + '</em></strong><small>' + (n - nbR ? (n - nbR) + ' encore à venir' : 'tout le monde est passé') + '</small></div></div>' : '';
    var f = [['tout', 'Toutes', n], ['attente', 'À récupérer', n - nbR], ['impaye', 'Non payées', n - nbP], ['fait', 'Récupérées', nbR]];
    var outils = '<div class="r-outils">' + (n ? '<div class="periodes" role="group" aria-label="Filtrer">' + f.map(function (x) {
      return '<button type="button" data-act="r-filtre" data-f="' + x[0] + '" aria-pressed="' + (R.filtre === x[0]) + '">' + x[1] + ' <span>' + x[2] + '</span></button>';
    }).join('') + '</div>' : '') + '<input type="search" id="r-q" class="r-q" value="' + esc(R.q) + '" placeholder="Rechercher un nom, un téléphone, un n° de commande…" aria-label="Rechercher"></div>';
    return semaine + titre + resume + deCote() + outils + '<div id="r-liste">' + resasListe() + '</div>' + recapJour(jour);
  }
  // Plats de ce jour réservés pour un jour suivant : à mettre de côté aujourd'hui
  function lignesDeCote() {
    var out = [];
    (R.data ? R.data.resas : []).forEach(function (r) {
      if (r.date === R.date) return;
      r.lignes.forEach(function (l) { if (l.jour === R.date) out.push({ r: r, l: l }); });
    });
    return out.sort(function (a, b) { return a.r.date < b.r.date ? -1 : 1; });
  }
  function deCote() {
    var l = lignesDeCote();
    if (!l.length) return '';
    return '<div class="r-cote"><p>' + ico('note') + '<b>À mettre de côté aujourd’hui</b> pour un autre jour</p><ul>' + l.map(function (x) {
      return '<li><b>' + x.l.qte + ' ×</b> ' + esc(x.l.nom) + ' <span>· ' + esc(nomClient(x.r.c) || 'client') + ', à récupérer ' + esc(dateLongue(x.r.date).toLowerCase()) + ' · <button type="button" class="lien" data-act="r-num" data-n="' + x.r.num + '">n° ' + x.r.num + '</button></span></li>';
    }).join('') + '</ul></div>';
  }
  // Récapitulatif du jour : un plat par ligne, quantité totale, prix, montant, puis le total de la journée
  function recapJour(jour) {
    if (!jour.length && !lignesDeCote().length) return '';
    var lignes = {}, ordre = [], qte = 0, total = 0, encaisse = 0;
    jour.forEach(function (r) {
      r.lignes.forEach(function (l) {
        var nom = (l.nom.trim() || 'Autre') + (l.jour && l.jour !== r.date ? ' (plat du ' + jourNom(l.jour).toLowerCase() + ')' : ''), k = nom + '|' + l.prix;
        if (!lignes[k]) { lignes[k] = { nom: nom, prix: prixNum(l.prix), qte: 0, recup: 0 }; ordre.push(k); }
        lignes[k].qte += l.qte; if (r.recupere) lignes[k].recup += l.qte;
        qte += l.qte; total += prixNum(l.prix) * l.qte; if (r.paye) encaisse += prixNum(l.prix) * l.qte;
      });
    });
    ordre.sort(function (a, b) { return lignes[b].qte - lignes[a].qte; });
    var cote = {};
    lignesDeCote().forEach(function (x) { cote[x.l.nom] = (cote[x.l.nom] || 0) + x.l.qte; });
    return '<section class="bloc r-recap"><h2>Récapitulatif du jour</h2><div class="r-recap-tab"><table><thead><tr><th>Plat</th><th class="n">Quantité</th><th class="n">Déjà récupérés</th><th class="n">Prix</th><th class="n">Montant</th></tr></thead><tbody>' +
      ordre.map(function (k) { var x = lignes[k];
        return '<tr><td>' + esc(x.nom) + '</td><td class="n"><b>' + x.qte + '</b></td><td class="n">' + x.recup + ' / ' + x.qte + '</td><td class="n">' + (x.prix ? euros(x.prix) : '–') + '</td><td class="n">' + (x.prix ? euros(x.prix * x.qte) : '–') + '</td></tr>';
      }).join('') +
      Object.keys(cote).map(function (k) { return '<tr class="r-recap-cote"><td>' + esc(k) + ' <small>à mettre de côté pour un autre jour</small></td><td class="n"><b>' + cote[k] + '</b></td><td class="n">–</td><td class="n">–</td><td class="n">–</td></tr>'; }).join('') +
      '</tbody>' + (jour.length ? '<tfoot><tr><td>Total</td><td class="n">' + qte + '</td><td></td><td></td><td class="n">' + euros(total) + '</td></tr></tfoot>' : '') + '</table></div>' +
      (jour.length ? '<p class="r-recap-pied"><span>Encaissé <b>' + euros(encaisse) + '</b></span><span>Reste à encaisser <b>' + euros(total - encaisse) + '</b></span></p>' : '') + '</section>';
  }
  function resasListe() {
    var jour = resasDuJour() || [];
    if (!jour.length && numCherche(R.q)) return '<p class="vide">Aucune réservation ce jour-là.<br><button type="button" class="btn btn-violet" data-act="r-num" data-n="' + numCherche(R.q) + '">Chercher la commande n° ' + numCherche(R.q) + ' sur tous les jours</button></p>';
    if (!jour.length) return '<div class="vide grand">Aucune réservation pour ce jour.<br><button type="button" class="btn btn-violet" data-act="resa-nouvelle">' + ico('plus') + 'Nouvelle réservation</button></div>';
    var q = sansAccent(R.q).trim(), qc = q.replace(/\D/g, ''), qn = numCherche(R.q);
    var l = jour.filter(function (r) {
      if (R.filtre === 'attente' && r.recupere) return false;
      if (R.filtre === 'impaye' && r.paye) return false;
      if (R.filtre === 'fait' && !r.recupere) return false;
      if (!q) return true;
      if (qn && r.num === qn) return true;
      return sansAccent(nomClient(r.c) + ' ' + r.c.email + ' ' + r.note + ' ' + r.lignes.map(function (x) { return x.nom; }).join(' ')).indexOf(q) >= 0 || (qc.length > 2 && r.c.tel.replace(/\D/g, '').indexOf(qc) >= 0);
    });
    // Les commandes déjà récupérées passent en bas de la liste
    l.sort(function (a, b) { return (a.recupere - b.recupere) || (a.cree < b.cree ? -1 : 1); });
    if (!l.length) return '<p class="vide">Aucune réservation ne correspond ce jour-là.' + (qn ? '<br><button type="button" class="btn btn-violet" data-act="r-num" data-n="' + qn + '">Chercher la commande n° ' + qn + ' sur tous les jours</button>' : '') + '</p>';
    return '<div class="r-liste">' + l.map(ligneResa).join('') + '</div>';
  }
  function ligneResa(r) {
    var c = r.c, t = totalResa(r);
    return '<article class="r-ligne' + (r.recupere ? ' fait' : '') + '">' +
      '<div class="r-client"><strong>' + esc(nomClient(c) || 'Sans nom') + ' <span class="r-num">n° ' + r.num + '</span>' + (r.source === 'site' ? ' <span class="r-web">En ligne</span>' : '') + '</strong>' +
        (c.tel ? '<a href="tel:' + esc(c.tel.replace(/[^\d+]/g, '')) + '">' + ico('tel') + esc(c.tel) + '</a>' : '') +
        '<small>' + (r.source === 'site' ? 'Réservée sur le site' : 'Prise par ' + esc(r.par)) + ' · ' + quand(r.cree).replace(/ par .*$/, '') + '</small>' +
        (r.rappel ? '<small class="r-sms-ok" title="SMS de rappel envoyé ' + esc(quand(r.rappel)) + '">' + ico('ok') + 'SMS envoyé à ' + heure(r.rappel) + '</small>' : '') + '</div>' +
      '<div class="r-cmd"><ul>' + r.lignes.map(function (x) { return '<li><b>' + x.qte + ' ×</b> ' + esc(x.nom) + (x.jour && x.jour !== r.date ? ' <small class="r-orig">plat du ' + jourNom(x.jour).toLowerCase() + ' ' + dateFr(x.jour) + '</small>' : '') + '</li>'; }).join('') + '</ul>' +
        (r.note ? '<p class="r-note">' + ico('note') + esc(r.note) + '</p>' : '') + '</div>' +
      '<div class="r-total">' + (t ? euros(t) : '') + '</div>' +
      '<div class="r-etats">' +
        '<button type="button" class="r-etat r-paye' + (r.paye ? ' on' : '') + '" data-act="r-etat" data-champ="paye" data-id="' + r.id + '" aria-pressed="' + r.paye + '" title="' + esc(r.paye ? 'Payé ' + quand(r.paye_le) + ' · cliquer pour annuler' : 'Cliquer quand le client a payé') + '">' + (r.paye ? ico('ok') + 'Payé' : 'Non payé') + '</button>' +
        '<button type="button" class="r-etat r-recup' + (r.recupere ? ' on' : '') + '" data-act="r-etat" data-champ="recupere" data-id="' + r.id + '" aria-pressed="' + r.recupere + '" title="' + esc(r.recupere ? 'Récupéré ' + quand(r.recupere_le) + ' · cliquer pour annuler' : 'Cliquer quand le client est passé') + '">' + (r.recupere ? ico('ok') + 'Récupéré' : 'À récupérer') + '</button>' +
      '</div>' +
      '<div class="r-actions">' + (R.data.sms && R.data.sms.pret && !r.recupere && /^0[67]/.test(c.tel.replace(/\D/g, '')) && (c.sms || {}).statut !== 'non' ? '<button type="button" class="ib" data-act="r-sms" data-id="' + r.id + '" title="Envoyer un SMS de rappel" aria-label="Envoyer un SMS de rappel">' + ico('note') + '</button>' : '') +
        '<button type="button" class="ib" data-act="resa-modif" data-id="' + r.id + '" title="Modifier" aria-label="Modifier">' + ico('crayon') + '</button>' +
        '<button type="button" class="ib ib-suppr" data-act="resa-suppr" data-id="' + r.id + '" title="Supprimer" aria-label="Supprimer">' + ico('suppr') + '</button></div>' +
      '</article>';
  }
  // « 12 », « #12 », « n° 12 » → 12 (numéro de commande)
  function numCherche(q) { var m = String(q || '').trim().match(/^(?:#|n°|no|n)?\s*(\d{1,5})$/i); return m ? +m[1] : 0; }
  function allerNum(n) {
    getJSON('resas&num=' + n).then(function (j) {
      if (!j.resas.length) return toast('Aucune commande n° ' + n + '.', true);
      var r = j.resas[0]; R.date = r.date; R.q = String(n); R.filtre = 'tout'; R.data = null; R.charge = ''; render(true);
      toast('Commande n° ' + n + ' : ' + (nomClient(r.c) || 'client') + ', ' + dateLongue(r.date).toLowerCase() + '.');
    }).catch(function (e) { toast(e.message, true); });
  }
  function resaParId(id) { return R.data ? R.data.resas.filter(function (r) { return r.id === id; })[0] : null; }
  function changerEtat(b) {
    var r = resaParId(b.dataset.id), ch = b.dataset.champ, v = !r[ch];
    r[ch] = v; r[ch + '_le'] = v ? E.aujourdhui + ' ' + new Date().toTimeString().slice(0, 5) + ' · ' + E.qui : '';
    majResas();
    if (VISITE) return toast('Mode visite : ce changement n’est pas enregistré.');
    api('resa-etat', { id: r.id, champ: ch, valeur: v }).then(function (j) { remplacerResa(j.resa); })
      .catch(function (e) { r[ch] = !v; majResas(); toast(e.message, true); });
  }
  function remplacerResa(n) {
    if (!R.data) return;
    var i = R.data.resas.map(function (r) { return r.id; }).indexOf(n.id);
    var dedans = n.date >= R.data.du && n.date <= R.data.au;
    if (i >= 0) { if (dedans) R.data.resas[i] = n; else R.data.resas.splice(i, 1); }
    else if (dedans) R.data.resas.push(n);
    if (onglet === 'resas') majResas();
  }

  // ---------- Fenêtre « Nouvelle réservation » ----------
  // Pensée pour aller vite au téléphone : on tape le nom (les clients connus sont proposés), le jour est déjà choisi,
  // le plat du jour est déjà dans la commande ; il reste la quantité, puis Entrée.
  function ligneVide(date, type) {
    // « jour:2026-10-28 » : le plat d'un jour précédent, à mettre de côté ce jour-là
    if (String(type).indexOf('jour:') === 0) { var j = type.slice(5), pj = menuDu(j).plat; if (pj) return { type: 'plat', nom: pj.nom, prix: pj.prix, qte: 1, jour: j }; }
    var m = menuDu(date);
    if (type !== 'autre' && type !== 'special' && m.plat) return { type: 'plat', nom: m.plat.nom, prix: m.plat.prix, qte: 1 };
    if (type === 'special' && m.special) return { type: 'special', nom: m.special.nom, prix: m.special.prix, qte: 1 };
    return { type: 'autre', nom: '', prix: '', qte: 1 };
  }
  function formResa(r, clientInit) {
    var nouveau = !r;
    var f = r ? { id: r.id, date: r.date, c: { id: r.c.id, prenom: r.c.prenom, nom: r.c.nom, tel: r.c.tel, email: r.c.email, lettre: '', sms: '' }, lignes: clone(r.lignes), note: r.note, paye: r.paye }
              : { id: '', date: R.date, c: { id: '', prenom: '', nom: '', tel: '', email: '', lettre: '', sms: '' }, lignes: [ligneVide(R.date)], note: '', paye: false };
    if (clientInit) f.c = { id: clientInit.id, prenom: clientInit.prenom, nom: clientInit.nom, tel: clientInit.tel, email: clientInit.email, lettre: '', sms: '' };
    var d = document.createElement('div');
    d.className = 'modale-fond';
    document.body.appendChild(d);
    function connu() { return f.c.id && CL ? CL.filter(function (c) { return c.id === f.c.id; })[0] : null; }
    function suggestions() {
      if (f.c.id || !CL) return [];
      var q = sansAccent((f.c.prenom + ' ' + f.c.nom).trim()), t = f.c.tel.replace(/\D/g, '');
      if (q.length < 2 && t.length < 3) return [];
      return CL.filter(function (c) {
        var n = sansAccent(nomClient(c)), n2 = sansAccent((c.nom || '') + ' ' + (c.prenom || ''));
        return (q.length >= 2 && (n.indexOf(q) >= 0 || n2.indexOf(q) >= 0 || q.split(' ').every(function (m) { return n.indexOf(m) >= 0; }))) || (t.length >= 3 && c.tel.replace(/\D/g, '').indexOf(t) >= 0);
      }).slice(0, 4);
    }
    function htmlSugg() {
      var s = suggestions();
      return s.length ? '<div class="fr-sugg"><p>Client déjà connu ?</p>' + s.map(function (c) {
        return '<button type="button" data-f="choisir" data-id="' + c.id + '"><span class="avatar">' + esc((nomClient(c) || '?').charAt(0).toUpperCase()) + '</span><span><b>' + esc(nomClient(c)) + '</b><small>' + esc([c.tel, c.email].filter(Boolean).join(' · ')) + (c.nb ? ' · ' + pluriel(c.nb, 'commande') : '') + '</small></span>' + ico('suivant') + '</button>';
      }).join('') + '</div>' : '';
    }
    function htmlLettre() { return htmlSms() + htmlMail(); }
    function htmlSms() {
      var k = connu();
      if (f.c.tel.replace(/\D/g, '').length < 10) return '';
      if (k && k.sms.statut === 'oui') return '<p class="fr-lettre deja">' + ico('ok') + 'Accepte de recevoir des SMS</p>';
      return '<div class="fr-lettre"><span>Accepte de recevoir des SMS de la boutique ?</span><div class="seg" role="group" aria-label="SMS">' +
        '<button type="button" data-f="sms" data-v="oui" aria-pressed="' + (f.c.sms === 'oui') + '">Oui</button>' +
        '<button type="button" data-f="sms" data-v="non" aria-pressed="' + (f.c.sms === 'non') + '">Non</button></div></div>';
    }
    function htmlMail() {
      var k = connu();
      if (!f.c.email.trim()) return '';
      if (k && k.lettre.statut === 'oui' && k.email === f.c.email.trim().toLowerCase()) return '<p class="fr-lettre deja">' + ico('ok') + 'Reçoit déjà le menu de la semaine par e-mail</p>';
      return '<div class="fr-lettre"><span>Envoyer le menu de la semaine par e-mail chaque lundi ?</span><div class="seg" role="group" aria-label="Menu par e-mail">' +
        '<button type="button" data-f="lettre" data-v="oui" aria-pressed="' + (f.c.lettre === 'oui') + '">Oui, il accepte</button>' +
        '<button type="button" data-f="lettre" data-v="non" aria-pressed="' + (f.c.lettre === 'non') + '">Non</button></div></div>';
    }
    function optionsLigne(l) {
      var m = menuDu(f.date), o = [];
      var dj0 = l.type === 'plat' && !l.jour;
      if (m.plat || dj0) o.push(['plat', 'Plat du jour · ' + (dj0 ? l.nom : m.plat.nom) + ((dj0 ? l.prix : m.plat.prix) ? ' · ' + (dj0 ? l.prix : m.plat.prix) : '')]);
      if (m.special || l.type === 'special') o.push(['special', 'Offre spéciale · ' + (l.type === 'special' ? l.nom : m.special.nom)]);
      // plats des 6 jours précédents (pour un client qui veut le plat de mercredi le vendredi)
      var avant = [], v = l.jour ? 'jour:' + l.jour : l.type;
      for (var k = 6; k >= 1; k--) {
        var dj = plus(f.date, -k), pj = menuDu(dj).plat;
        if (pj || dj === l.jour) avant.push(['jour:' + dj, jourNom(dj) + ' ' + dateFr(dj) + ' · ' + (dj === l.jour ? l.nom : pj.nom) + ((dj === l.jour ? l.prix : pj.prix) ? ' · ' + (dj === l.jour ? l.prix : pj.prix) : '')]);
      }
      o.push(['autre', 'Autre chose…']);
      function opt(x) { return '<option value="' + x[0] + '"' + (x[0] === v ? ' selected' : '') + '>' + esc(x[1]) + '</option>'; }
      return o.slice(0, -1).map(opt).join('') + (avant.length ? '<optgroup label="Plats des jours précédents (à mettre de côté)">' + avant.map(opt).join('') + '</optgroup>' : '') + opt(o[o.length - 1]);
    }
    function htmlLignes() {
      var tot = 0;
      var h = f.lignes.map(function (l, i) {
        tot += prixNum(l.prix) * l.qte;
        return '<div class="fr-ligne' + (l.type === 'autre' ? ' autre' : '') + '">' +
          '<select data-l="' + i + '" data-k="type" aria-label="Plat">' + optionsLigne(l) + '</select>' +
          (l.type === 'autre' ? '<input data-l="' + i + '" data-k="nom" value="' + esc(l.nom) + '" placeholder="Quoi ? ex. Quiche lorraine" aria-label="Quoi ?"><input class="prix" data-l="' + i + '" data-k="prix" value="' + esc(l.prix) + '" placeholder="Prix" inputmode="decimal" aria-label="Prix à l’unité">' : '') +
          '<div class="qte"><button type="button" data-f="moins" data-i="' + i + '" aria-label="Un de moins">−</button><input type="number" min="1" max="99" data-l="' + i + '" data-k="qte" value="' + l.qte + '" aria-label="Quantité"><button type="button" data-f="plus" data-i="' + i + '" aria-label="Un de plus">+</button></div>' +
          (f.lignes.length > 1 ? '<button type="button" class="ib ib-suppr" data-f="retirer" data-i="' + i + '" title="Retirer" aria-label="Retirer">' + ico('suppr') + '</button>' : '') + '</div>' +
          (l.jour ? '<p class="fr-cote">' + ico('note') + 'Plat du ' + jourNom(l.jour).toLowerCase() + ' ' + dateFr(l.jour) + ' : il sera noté « à mettre de côté » ce jour-là.</p>' : '');
      }).join('');
      return h + '<div class="fr-pied-cmd"><button type="button" class="btn btn-discret" data-f="ajouter">' + ico('plus') + 'Ajouter un plat</button>' + (tot ? '<span class="fr-total">Total <b>' + euros(tot) + '</b></span>' : '') + '</div>';
    }
    function htmlDates() {
      var du = lundi(f.date), h = '';
      for (var i = 0; i < 7; i++) {
        var x = plus(du, i), pd = menuDu(x).plat;
        h += '<button type="button" class="r-jour' + (x === f.date ? ' actif' : '') + (x === E.aujourdhui ? ' auj' : '') + (pd ? '' : ' sans-plat') + '" data-f="date" data-d="' + x + '" title="' + esc(dateLongue(x) + (pd ? ' · ' + pd.nom : ' · pas de plat du jour')) + '"><span>' + JC[parse(x).getDay()] + '</span><b>' + parse(x).getDate() + '</b></button>';
      }
      return '<div class="fr-dates"><button type="button" class="ib" data-f="date" data-d="' + plus(du, -7) + '" aria-label="Semaine précédente">' + ico('retour') + '</button><div class="r-jours">' + h + '</div>' +
        '<button type="button" class="ib" data-f="date" data-d="' + plus(du, 7) + '" aria-label="Semaine suivante">' + ico('suivant') + '</button></div><p class="fr-date-txt">' + esc(dateLongue(f.date)) + '</p>';
    }
    function dessiner() {
      var k = connu();
      d.innerHTML = '<form class="modale modale-resa" role="dialog" aria-modal="true" aria-labelledby="fr-t" novalidate>' +
        '<h2 id="fr-t">' + (nouveau ? 'Nouvelle réservation' : 'Modifier la réservation <span class="r-num">n° ' + r.num + '</span>') + '</h2>' +
        '<fieldset class="fr-bloc"><legend>Client' + (k ? ' <span class="statut statut-violet">' + ico('ok') + 'Client connu' + (k.nb ? ' · ' + pluriel(k.nb, 'commande') : '') + '</span> <button type="button" class="lien" data-f="oublier">Autre client</button>' : '') + '</legend>' +
          '<div class="grille-2"><label class="champ"><span>Prénom</span><input data-c="prenom" value="' + esc(f.c.prenom) + '" autocomplete="off" placeholder="ex. Marie"></label>' +
          '<label class="champ"><span>Nom</span><input data-c="nom" value="' + esc(f.c.nom) + '" autocomplete="off" placeholder="ex. Dupont"></label></div>' +
          '<div id="fr-sugg">' + htmlSugg() + '</div>' +
          '<div class="grille-2"><label class="champ"><span>Téléphone</span><input data-c="tel" type="tel" value="' + esc(f.c.tel) + '" autocomplete="off" placeholder="06 12 34 56 78"></label>' +
          '<label class="champ"><span>E-mail <em>facultatif</em></span><input data-c="email" type="email" value="' + esc(f.c.email) + '" autocomplete="off" placeholder="marie@exemple.fr"></label></div>' +
          '<div id="fr-lettre">' + htmlLettre() + '</div></fieldset>' +
        '<fieldset class="fr-bloc"><legend>Pour quel jour ?</legend><div id="fr-dates">' + htmlDates() + '</div></fieldset>' +
        '<fieldset class="fr-bloc"><legend>Commande</legend><div id="fr-lignes">' + htmlLignes() + '</div></fieldset>' +
        '<label class="champ"><span>Note <em>facultatif</em></span><textarea rows="2" data-n placeholder="ex. sans sauce, passe vers 12 h 30">' + esc(f.note) + '</textarea></label>' +
        '<label class="interrupteur grand fr-paye"><input type="checkbox" data-paye' + (f.paye ? ' checked' : '') + '><span></span>Déjà payé</label>' +
        '<p class="alerte" hidden></p>' +
        '<div class="modale-actions"><button type="button" class="btn btn-discret" data-f="fermer">Annuler</button><button class="btn btn-violet">' + (nouveau ? 'Enregistrer la réservation' : 'Enregistrer') + '</button></div></form>';
    }
    function part(id, html) { var z = d.querySelector('#' + id); if (z) z.innerHTML = html; }
    function fin() { d.remove(); document.removeEventListener('keydown', echap); }
    function echap(e) { if (e.key === 'Escape') fin(); }
    document.addEventListener('keydown', echap);
    dessiner();
    // la liste des clients (pour les suggestions) arrive en arrière-plan
    if (CL === null) chargerClients().then(function () { if (d.isConnected) { part('fr-sugg', htmlSugg()); part('fr-lettre', htmlLettre()); } });
    (f.c.id ? d.querySelector('[data-l="0"][data-k="qte"]') : d.querySelector('[data-c="prenom"]')).focus();
    d.addEventListener('input', function (e) {
      var t = e.target;
      if (t.dataset.c) {
        f.c[t.dataset.c] = t.value;
        if (t.dataset.c !== 'email') part('fr-sugg', htmlSugg());
        part('fr-lettre', htmlLettre());
      } else if (t.dataset.l != null) {
        var l = f.lignes[+t.dataset.l];
        if (t.dataset.k === 'qte') l.qte = Math.max(1, Math.min(99, parseInt(t.value, 10) || 1));
        else if (t.dataset.k !== 'type') l[t.dataset.k] = t.value;
        if (t.dataset.k === 'prix' || t.dataset.k === 'qte') { var tt = d.querySelector('.fr-total'); var s = f.lignes.reduce(function (a, x) { return a + prixNum(x.prix) * x.qte; }, 0); if (tt) tt.innerHTML = 'Total <b>' + euros(s) + '</b>'; else if (s) part('fr-lignes', htmlLignes()); }
      } else if ('n' in t.dataset) f.note = t.value;
    });
    d.addEventListener('change', function (e) {
      var t = e.target;
      if (t.dataset.k === 'type') {
        var i = +t.dataset.l, nv = ligneVide(f.date, t.value);
        nv.qte = f.lignes[i].qte; f.lignes[i] = nv;
        part('fr-lignes', htmlLignes());
        if (nv.type === 'autre') d.querySelector('[data-l="' + i + '"][data-k="nom"]').focus();
      } else if (t.dataset.k === 'prix') { var fp = formatPrix(t.value); f.lignes[+t.dataset.l].prix = fp; t.value = fp; }
      else if ('paye' in t.dataset) f.paye = t.checked;
    });
    d.addEventListener('click', function (e) {
      if (e.target === d) return fin();
      var b = e.target.closest('[data-f]'); if (!b) return;
      var a = b.dataset.f, i = +b.dataset.i;
      if (a === 'fermer') fin();
      else if (a === 'choisir') {
        var c = CL.filter(function (x) { return x.id === b.dataset.id; })[0];
        f.c = { id: c.id, prenom: c.prenom, nom: c.nom, tel: c.tel, email: c.email, lettre: '', sms: '' };
        dessiner(); d.querySelector('[data-l="0"][data-k="qte"]').focus();
      } else if (a === 'oublier') { f.c = { id: '', prenom: '', nom: '', tel: '', email: '', lettre: '', sms: '' }; dessiner(); d.querySelector('[data-c="prenom"]').focus(); }
      else if (a === 'lettre' || a === 'sms') { f.c[a] = f.c[a] === b.dataset.v ? '' : b.dataset.v; part('fr-lettre', htmlLettre()); }
      else if (a === 'date') {
        f.date = b.dataset.d;
        // le plat du jour suit le jour choisi
        f.lignes = f.lignes.map(function (l) { if (l.type === 'autre' || l.jour) return l; var n = ligneVide(f.date, l.type); if (n.type !== l.type) { n = l; n.type = 'autre'; } n.qte = l.qte; return n; });
        part('fr-dates', htmlDates()); part('fr-lignes', htmlLignes());
      } else if (a === 'plus' || a === 'moins') { f.lignes[i].qte = Math.max(1, Math.min(99, f.lignes[i].qte + (a === 'plus' ? 1 : -1))); part('fr-lignes', htmlLignes()); }
      else if (a === 'retirer') { f.lignes.splice(i, 1); part('fr-lignes', htmlLignes()); }
      else if (a === 'ajouter') {
        // deuxième ligne : « autre chose » (le plat du jour est déjà dans la commande, il suffit d'augmenter la quantité)
        f.lignes.push(ligneVide(f.date, f.lignes.some(function (l) { return l.type === 'plat'; }) ? 'autre' : 'plat'));
        part('fr-lignes', htmlLignes());
        var n = d.querySelectorAll('.fr-ligne'); var z = n[n.length - 1].querySelector('select'); if (z) z.focus();
      }
    });
    d.addEventListener('submit', function (e) {
      e.preventDefault();
      var al = d.querySelector('.alerte');
      function erreur(m) { al.textContent = m; al.hidden = false; }
      if (!f.c.prenom.trim() && !f.c.nom.trim()) { d.querySelector('[data-c="prenom"]').focus(); return erreur('Indiquez le nom ou le prénom du client.'); }
      var lignes = f.lignes.filter(function (l) { return l.nom.trim(); });
      if (!lignes.length) return erreur('Ajoutez au moins un plat à la commande.');
      if (VISITE) { fin(); return toast('Mode visite : la réservation n’est pas enregistrée.'); }
      var btn = d.querySelector('.modale-actions .btn-violet'); btn.disabled = true;
      api('resa', { id: f.id, date: f.date, c: f.c, lignes: lignes, note: f.note, paye: f.paye })
        .then(function (j) {
          fin(); chargerClients();
          if (lundi(j.resa.date) !== lundi(R.date)) { R.date = j.resa.date; R.data = null; R.charge = ''; }
          else { R.date = j.resa.date; remplacerResa(j.resa); }
          if (onglet === 'resas') render(true); else if (onglet === 'clients') chargerClients();
          toast((nouveau ? 'Réservation n° ' + j.resa.num + ' enregistrée' : 'Réservation n° ' + j.resa.num + ' modifiée') + ' pour ' + dateLongue(j.resa.date).toLowerCase() + '.');
        })
        .catch(function (er) { btn.disabled = false; erreur(er.message); });
    });
  }

  // =============== Clients ===============
  function majClients() {
    var z = document.getElementById('clients'); if (z) z.innerHTML = clientsContenu();
    var l = document.getElementById('lettre-zone'); if (l && LETTRE) l.innerHTML = blocLettre();
  }
  function vueClients() {
    if (CL === null) chargerClients();
    if (SMS === null || SMS.erreurChargement) chargerSms();
    return tete('Clients', 'Toutes les personnes qui ont réservé ou qui reçoivent le menu par e-mail. Un client est reconnu automatiquement par son téléphone ou son e-mail.',
      (VISITE ? '' : '<a class="btn btn-ligne" href="' + E.api + '?api=clients-csv" download>' + ico('telecharger') + 'Exporter (Excel)</a>') +
      '<button type="button" class="btn btn-violet" data-act="client-fiche">' + ico('plus') + 'Nouveau client</button>') +
      '<div class="messages-clients"><div id="lettre-zone">' + (LETTRE ? blocLettre() : '') + '</div><div id="sms">' + blocSms() + '</div></div>' +
      '<div id="clients">' + clientsContenu() + '</div>';
  }
  // Pastilles « E-mail » et « SMS » : vert = accepte, barré orange = refuse, gris = pas encore demandé
  function pastilles(c) {
    return '<span class="accords">' + [['lettre', 'mail', 'E-mail', 'le menu par e-mail'], ['sms', 'tel', 'SMS', 'les SMS']].map(function (x) {
      var s = (c[x[0]] || {}).statut || '';
      var t = s === 'oui' ? 'Accepte ' + x[3] : s === 'non' ? 'Refuse ' + x[3] : 'Pas encore demandé pour ' + x[3];
      return '<span class="accord accord-' + (s || 'vide') + '" title="' + t + '">' + ico(x[1]) + x[2] + '</span>';
    }).join('') + '</span>';
  }
  function filtreClient(c) {
    var l = c.lettre.statut, m = (c.sms || {}).statut || '';
    if (clFiltre === 'oui') return l === 'oui';
    if (clFiltre === 'sms') return m === 'oui';
    if (clFiltre === 'non') return l === 'non' || m === 'non';
    if (clFiltre === '') return l === '' || m === '';
    return true;
  }
  function blocLettre() {
    var L = LETTRE, s = L.semaine, txt;
    var dernier = L.envois.filter(function (e) { return e.mode !== 'test'; })[0];
    if (!s) txt = 'Aucun menu ne commence cette semaine : rien ne sera envoyé. Préparez le menu dans l’onglet « Menu de la semaine ».';
    else if (s.envoye) txt = ico('ok') + 'Le menu ' + esc(periode(s.du, s.au)) + ' a été envoyé ' + quand(s.envoye.date) + (s.envoye.en_cours ? ' (envoi en cours).' : ' à ' + pluriel(s.envoye.ok, 'personne') + '.');
    else if (!L.auto) txt = 'Envoi automatique désactivé : le menu ' + esc(periode(s.du, s.au)) + ' ne partira que si vous cliquez sur « Envoyer maintenant ».';
    else if (!s.complete) txt = 'Le menu ' + esc(periode(s.du, s.au)) + ' n’est pas complet : il partira automatiquement dès que chaque jour aura son plat (ou sera marqué fermé).';
    else txt = 'Le menu ' + esc(periode(s.du, s.au)) + ' partira automatiquement lundi à partir de 8 h.';
    return '<section class="bloc lettre-bloc"><div class="bloc-tete"><div><h2>Le menu de la semaine par e-mail</h2><p class="aide">Les inscrits reçoivent chaque lundi le menu de la semaine, avec un lien pour se désinscrire. Ils s’inscrivent sur le site, ou vous les inscrivez avec leur accord.</p></div>' +
      '<span class="lettre-nb"><b>' + L.inscrits + '</b> inscrit' + (L.inscrits > 1 ? 's' : '') + '</span></div>' +
      '<label class="interrupteur"><input type="checkbox" data-lettre-auto' + (L.auto ? ' checked' : '') + (VISITE ? ' disabled' : '') + '><span></span>Envoi automatique chaque lundi matin</label>' +
      '<p class="lettre-etat">' + txt + '</p>' +
      '<div class="lettre-actions"><button type="button" class="btn btn-ligne" data-act="lettre-test">' + ico('mail') + 'M’envoyer un essai</button>' +
      '<button type="button" class="btn btn-ligne" data-act="lettre-envoyer"' + (L.inscrits ? '' : ' disabled') + '>' + ico('envoyer') + 'Envoyer maintenant</button>' +
      (VISITE ? '' : '<a class="btn btn-discret" href="' + E.api + '?api=clients-csv&f=lettre" download>Liste des inscrits (Excel)</a>') + '</div>' +
      (dernier ? '<p class="aide petit">Dernier envoi : ' + quand(dernier.date) + ' · ' + pluriel(dernier.ok, 'e-mail') + (dernier.mode === 'auto' ? ' (automatique)' : '') + '</p>' : '') +
      '</section>';
  }
  function clientsContenu() {
    if (CL === null) return '<p class="chargement">Chargement…</p>';
    var nb = {}, garde = clFiltre;
    ['tout', 'oui', 'sms', 'non', ''].forEach(function (k) { clFiltre = k; nb[k] = CL.filter(filtreClient).length; });
    clFiltre = garde;
    var q = sansAccent(clQ).trim(), qc = q.replace(/\D/g, '');
    var l = CL.filter(function (c) {
      if (!filtreClient(c)) return false;
      return !q || sansAccent(nomClient(c) + ' ' + c.email).indexOf(q) >= 0 || (qc.length > 2 && c.tel.replace(/\D/g, '').indexOf(qc) >= 0);
    });
    var f = [['tout', 'Tous'], ['oui', 'Menu par e-mail'], ['sms', 'SMS'], ['non', 'Ont refusé'], ['', 'À demander']];
    return '<div class="r-outils"><div class="periodes" role="group" aria-label="Filtrer">' + f.map(function (x) {
        return '<button type="button" data-act="cl-filtre" data-f="' + x[0] + '" aria-pressed="' + (clFiltre === x[0]) + '">' + x[1] + ' <span>' + nb[x[0]] + '</span></button>';
      }).join('') + '</div><input type="search" id="cl-q" class="r-q" value="' + esc(clQ) + '" placeholder="Rechercher un nom, un téléphone, un e-mail…" aria-label="Rechercher"></div>' +
      '<div id="cl-liste">' + clientsListe(l) + '</div>';
  }
  function clientsListe(l) {
    if (!CL.length) return '<div class="vide grand">Pas encore de client. Ils s’ajoutent tout seuls à chaque réservation et à chaque inscription au menu par e-mail.</div>';
    if (!l.length) return '<p class="vide">Aucun client ne correspond.</p>';
    return '<div class="cl-liste">' + l.map(function (c) {
      return '<button type="button" class="cl-ligne" data-act="client-fiche" data-id="' + c.id + '"><span class="avatar grand">' + esc((nomClient(c) || '?').charAt(0).toUpperCase()) + '</span>' +
        '<span class="cl-nom"><strong>' + esc(nomClient(c) || 'Sans nom') + '</strong><small>' + esc([c.tel, c.email].filter(Boolean).join(' · ') || 'pas de coordonnées') + '</small></span>' +
        pastilles(c) +
        '<span class="cl-cmd">' + (c.nb ? pluriel(c.nb, 'commande') + '<small>dernière le ' + dateFr(c.der) + '</small>' : '<small>aucune commande</small>') + '</span></button>';
    }).join('') + '</div>';
  }
  function majClientsListe() {
    var z = document.getElementById('cl-liste'); if (!z) return;
    var q = sansAccent(clQ).trim(), qc = q.replace(/\D/g, '');
    z.innerHTML = clientsListe(CL.filter(function (c) {
      if (!filtreClient(c)) return false;
      return !q || sansAccent(nomClient(c) + ' ' + c.email).indexOf(q) >= 0 || (qc.length > 2 && c.tel.replace(/\D/g, '').indexOf(qc) >= 0);
    }));
  }
  function ficheClient(c) {
    var nouveau = !c; c = c || { id: '', prenom: '', nom: '', tel: '', email: '', note: '', lettre: { statut: '', historique: [] } };
    var d = document.createElement('div');
    d.className = 'modale-fond';
    c.sms = c.sms || { statut: '', historique: [] };
    function hist(a, oui, non) {
      var h = (a.historique || []).slice().reverse().map(function (x) {
        return '<li>' + (x.statut === 'oui' ? oui : non) + ' ' + quand(x.date) + ' · ' + esc(x.source === 'site' ? 'depuis le site' : x.source === 'désinscription' ? 'avec le lien de l’e-mail' : x.source.replace('back-office · ', 'dans le back-office par ')) + '</li>';
      }).join('');
      return h ? '<ul class="fc-hist">' + h + '</ul>' : '';
    }
    function accord(nom, a, titre, aide, oui, non) {
      return '<div class="fc-accord"><div class="fc-accord-t">' + ico(nom === 'sms' ? 'tel' : 'mail') + '<span><b>' + titre + '</b><small>' + aide + '</small></span></div><div class="seg" role="radiogroup" aria-label="' + titre + '">' +
        [['oui', 'Oui, il accepte'], ['non', 'Non']].concat(a.statut === '' ? [['', 'Pas demandé']] : []).map(function (x) {
          return '<label><input type="radio" name="' + nom + '" value="' + x[0] + '"' + (a.statut === x[0] ? ' checked' : '') + '><span>' + x[1] + '</span></label>';
        }).join('') + '</div>' + hist(a, oui, non) + '</div>';
    }
    d.innerHTML = '<form class="modale modale-large" role="dialog" aria-modal="true" aria-labelledby="fc-t"><h2 id="fc-t">' + (nouveau ? 'Nouveau client' : esc(nomClient(c) || 'Client')) + '</h2>' +
      (nouveau ? '' : '<p>Client depuis le ' + dateFr(c.cree.slice(0, 10)) + ' ' + c.cree.slice(0, 4) + (c.nb ? ' · ' + pluriel(c.nb, 'commande') : '') + '</p>') +
      '<div class="grille-2"><label class="champ"><span>Prénom</span><input name="prenom" value="' + esc(c.prenom) + '"></label><label class="champ"><span>Nom</span><input name="nom" value="' + esc(c.nom) + '"></label></div>' +
      '<div class="grille-2"><label class="champ"><span>Téléphone</span><input name="tel" type="tel" value="' + esc(c.tel) + '"></label><label class="champ"><span>E-mail</span><input name="email" type="email" value="' + esc(c.email) + '"></label></div>' +
      '<label class="champ"><span>Note <em>facultatif</em></span><textarea name="note" rows="2" placeholder="ex. allergique aux fruits à coque, préfère être appelé le matin">' + esc(c.note) + '</textarea></label>' +
      '<div class="champ"><span>Ce qu’il accepte de recevoir</span>' +
        accord('lettre', c.lettre, 'Le menu de la semaine par e-mail', 'chaque lundi, à son adresse e-mail', 'Inscrit', 'Désinscrit') +
        accord('sms', c.sms, 'Des messages par SMS', 'sur son téléphone : menu, rappel de commande…', 'Accepte les SMS', 'Refuse les SMS') + '</div>' +
      (nouveau ? '' : '<div class="champ"><span>Commandes</span><div id="fc-cmd" class="fc-cmd"><p class="aide">Chargement…</p></div></div>') +
      '<p class="alerte" hidden></p><div class="modale-actions">' +
        (nouveau ? '' : '<button type="button" class="btn btn-discret fc-suppr" data-r="suppr">' + ico('suppr') + 'Supprimer</button><button type="button" class="btn btn-ligne" data-r="resa">' + ico('plus') + 'Réservation</button>') +
        '<button type="button" class="btn btn-discret" data-r="0">Annuler</button><button class="btn btn-violet">Enregistrer</button></div></form>';
    document.body.appendChild(d);
    var f = d.querySelector('form'), al = d.querySelector('.alerte');
    f.querySelector('input').focus();
    function fin() { d.remove(); document.removeEventListener('keydown', echap); }
    function echap(e) { if (e.key === 'Escape') fin(); }
    document.addEventListener('keydown', echap);
    if (!nouveau) getJSON('resas&client=' + c.id).then(function (j) {
      var z = d.querySelector('#fc-cmd'); if (!z) return;
      z.innerHTML = j.resas.length ? '<ul>' + j.resas.slice().reverse().slice(0, 12).map(function (r) {
        return '<li><b>' + esc(dateLongue(r.date)) + '</b><span>' + r.lignes.map(function (x) { return x.qte + ' × ' + esc(x.nom); }).join(', ') + '</span><em class="statut statut-' + (r.paye ? 'vert' : 'orange') + '">' + (r.paye ? 'Payé' : 'Non payé') + '</em></li>';
      }).join('') + '</ul>' : '<p class="aide">Aucune commande pour l’instant.</p>';
    }).catch(function () {});
    d.addEventListener('click', function (e) {
      if (e.target === d) return fin();
      var b = e.target.closest('[data-r]'); if (!b) return;
      if (b.dataset.r === '0') fin();
      else if (b.dataset.r === 'resa') { fin(); formResa(null, c); }
      else if (b.dataset.r === 'suppr') {
        demander('Supprimer ce client ?', esc(nomClient(c) || 'Ce client') + ' sera effacé (nom, téléphone, e-mail, inscription au menu). Ses anciennes réservations restent dans l’historique, sans son nom.', [{ txt: 'Garder', val: false }, { txt: 'Supprimer', val: true, cls: 'btn-danger' }])
          .then(function (r) { if (!r) return; if (VISITE) return toast('Mode visite : rien n’est supprimé.'); api('client-suppr', { id: c.id }).then(function (j) { CL = j.clients; LETTRE = j.lettre; fin(); majClients(); toast('Client supprimé.'); }).catch(function (er) { toast(er.message, true); }); });
      }
    });
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      if (VISITE) { fin(); return toast('Mode visite : rien n’est enregistré.'); }
      var lt = f.querySelector('[name=lettre]:checked'), sm = f.querySelector('[name=sms]:checked');
      api('client', { id: c.id, prenom: f.prenom.value, nom: f.nom.value, tel: f.tel.value, email: f.email.value, note: f.note.value, lettre: lt ? lt.value : '', sms: sm ? sm.value : '' })
        .then(function (j) { CL = j.clients; LETTRE = j.lettre; fin(); majClients(); toast(nouveau ? 'Client ajouté.' : 'Fiche client enregistrée.'); })
        .catch(function (er) { al.textContent = er.message; al.hidden = false; });
    });
  }
  function actionLettre(a, b) {
    if (VISITE) return toast('Mode visite : aucun e-mail n’est envoyé.');
    if (a === 'lettre-test') {
      b.disabled = true;
      api('lettre-test', {}).then(function (j) { CL = j.clients; LETTRE = j.lettre; majClients(); toast('Essai envoyé à ' + j.a + '. Regardez votre boîte mail (et les indésirables).'); })
        .catch(function (e) { toast(e.message, true); }).then(function () { b.disabled = false; });
      return;
    }
    var s = LETTRE.semaine;
    demander('Envoyer le menu maintenant ?', 'Le menu de la semaine va partir par e-mail à ' + pluriel(LETTRE.inscrits, 'inscrit') + '.' + (s && s.envoye ? '<br><b>Attention : il a déjà été envoyé ' + quand(s.envoye.date) + '.</b>' : ''), [{ txt: 'Annuler', val: false }, { txt: 'Envoyer', val: true, cls: 'btn-violet' }])
      .then(function (r) {
        if (!r) return;
        b.disabled = true; b.textContent = 'Envoi en cours…';
        api('lettre-envoyer', {}).then(function (j) { CL = j.clients; LETTRE = j.lettre; majClients(); toast('Menu envoyé à ' + pluriel(j.n, 'personne') + '.' + (j.avert ? ' ' + j.avert : '')); })
          .catch(function (e) { toast(e.message, true); majClients(); });
      });
  }

  // =============== SMS (rappel « votre commande vous attend ») ===============
  var SMS = null;
  function chargerSms() {
    return getJSON('sms').then(function (j) { SMS = j; if (onglet === 'clients') majSms(); return j; })
      .catch(function (e) { SMS = { erreurChargement: e.message }; if (onglet === 'clients') majSms(); });
  }
  function majSms() { var z = document.getElementById('sms'); if (z) z.innerHTML = blocSms(); }
  var GSM = /^[@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&'()*+,\-.\/0-9:;<=>?¡A-ZÄÖÑÜ§¿a-zäöñüà^{}\\\[~\]|€]*$/;
  function compteSms(modele) {
    var t = modele.replace(/\{prenom\}/g, 'Marie').replace(/\{nom\}/g, 'Dupont').replace(/\{plats\}/g, '2 Blanquette de veau').trim();
    var gsm = GSM.test(t), n = t.length + (gsm ? (t.match(/[\^{}\\\[~\]|€]/g) || []).length : 0);
    var nb = gsm ? (n <= 160 ? 1 : Math.ceil(n / 153)) : (n <= 70 ? 1 : Math.ceil(n / 67));
    return n + ' caractères avec un exemple · <b>' + pluriel(nb, 'SMS') + '</b> par envoi' + (gsm ? '' : ' <span class="sms-unicode">(un caractère spécial comme « ’ », « À » ou « ê » réduit un SMS à 70 caractères)</span>');
  }
  function blocSms() {
    var S = SMS, admin = E.moi.role === 'admin';
    var tete_ = '<div class="bloc-tete"><div><h2>Rappel par SMS</h2><p class="aide">Un SMS « votre commande vous attend » aux clients qui ne sont pas encore passés récupérer leur commande du jour, à l’heure de votre choix.</p></div>' +
      (S && S.pret && S.credits != null ? '<span class="lettre-nb"><b>' + S.credits + '</b> crédit' + (S.credits > 1 ? 's' : '') + ' SMS</span>' : '') + '</div>';
    if (!S) return '<section class="bloc">' + tete_ + '<p class="chargement">Chargement…</p></section>';
    if (S.erreurChargement) return '<section class="bloc">' + tete_ + '<p class="vide">' + esc(S.erreurChargement) + '</p></section>';
    if (!S.pret) {
      if (!admin) return '<section class="bloc">' + tete_ + '<p class="vide">Le service SMS n’est pas encore connecté : demandez à un administrateur.</p></section>';
      return '<section class="bloc sms-bloc">' + tete_ + '<ol class="etapes">' +
        '<li><b>Commandez des crédits SMS chez OVH</b><span>Dans votre espace client OVH : Télécom → SMS → Commander des crédits (quelques centimes par SMS). Une centaine de crédits suffit pour commencer.</span></li>' +
        '<li><b>Créez les clés d’accès du site</b><span>Ouvrez <a href="https://eu.api.ovh.com/createToken/?GET=/sms&GET=/sms/*&POST=/sms/*" target="_blank" rel="noopener">cette page OVH</a>, connectez-vous avec votre compte OVH, donnez un nom (ex. « Site Jardin Parmentier »), choisissez « Unlimited » pour la durée, puis validez.</span></li>' +
        '<li><b>Copiez ici les 3 clés affichées par OVH</b><div class="grille-3 sms-cles"><label class="champ"><span>Application Key</span><input id="sms-ak" autocomplete="off" spellcheck="false"></label><label class="champ"><span>Application Secret</span><input id="sms-as" type="password" autocomplete="off"></label><label class="champ"><span>Consumer Key</span><input id="sms-ck" type="password" autocomplete="off"></label></div>' +
        '<button type="button" class="btn btn-violet" data-act="sms-cles">Connecter le service SMS</button></li></ol>' +
        (S.erreur ? '<p class="alerte">' + esc(S.erreur) + '</p>' : '') + '</section>';
    }
    var etat;
    if (!S.auto) etat = 'Envoi automatique désactivé : utilisez « Envoyer maintenant », ou le bouton SMS sur une réservation.';
    else if (S.fait && !S.parti) etat = 'Activé après ' + S.heure.replace(':', 'h') + ' : les rappels partiront automatiquement à partir de demain.' + (S.a_rappeler ? ' Pour aujourd’hui, utilisez « Envoyer maintenant ».' : '');
    else if (S.fait) etat = ico('ok') + 'Les rappels d’aujourd’hui sont partis.' + (S.a_rappeler ? ' ' + pluriel(S.a_rappeler, 'commande') + ' non récupérée' + (S.a_rappeler > 1 ? 's' : '') + ' n’en ont pas encore reçu (ajoutée' + (S.a_rappeler > 1 ? 's' : '') + ' après l’envoi).' : '') ;
    else etat = 'Aujourd’hui à ' + S.heure.replace(':', 'h') + ', ' + (S.a_rappeler ? '<b>' + pluriel(S.a_rappeler, 'client') + '</b> recevr' + (S.a_rappeler > 1 ? 'ont' : 'a') + ' un SMS (commandes pas encore récupérées).' : 'les clients qui n’auront pas récupéré leur commande recevront un SMS. Pour l’instant, personne.');
    var dernier = S.envois.filter(function (e) { return e.mode.indexOf('essai') !== 0; })[0];
    var lect = VISITE || !admin ? ' disabled' : '';
    return '<section class="bloc sms-bloc">' + tete_ +
      '<div class="sms-auto"><label class="interrupteur"><input type="checkbox" data-sms="auto"' + (S.auto ? ' checked' : '') + lect + '><span></span>Envoi automatique chaque jour à</label>' +
      '<input type="time" class="sms-heure" data-sms="heure" value="' + esc(S.heure) + '" aria-label="Heure d’envoi"' + lect + '></div>' +
      '<p class="lettre-etat">' + etat + '</p>' +
      '<div class="champ"><span>À qui ?</span><div class="seg" role="radiogroup" aria-label="À qui ?">' +
        [['tous', 'Tous les clients, sauf ceux qui refusent les SMS'], ['accord', 'Seulement ceux qui ont accepté les SMS']].map(function (x) {
          return '<label><input type="radio" name="sms-cible" data-sms="cible" value="' + x[0] + '"' + (S.cible === x[0] ? ' checked' : '') + lect + '><span>' + x[1] + '</span></label>';
        }).join('') + '</div></div>' +
      '<label class="champ"><span>Message</span><textarea rows="3" data-sms="message" maxlength="600"' + lect + '>' + esc(S.message) + '</textarea>' +
        '<small>{prenom} et {plats} sont remplacés par le prénom du client et sa commande. <span id="sms-compte">' + compteSms(S.message) + '</span>' +
        (S.message !== S.defaut && !lect ? ' · <button type="button" class="lien" data-act="sms-defaut">Message d’origine</button>' : '') + '</small></label>' +
      '<div class="sms-actions"><div class="sms-essai"><input type="tel" id="sms-tel" value="' + esc(S.tel_essai) + '" placeholder="Votre portable : 06…" aria-label="Numéro pour l’essai"><button type="button" class="btn btn-ligne" data-act="sms-test">' + ico('note') + 'M’envoyer un SMS d’essai</button></div>' +
        '<button type="button" class="btn btn-ligne" data-act="sms-rappels"' + (S.a_rappeler ? '' : ' disabled') + '>' + ico('envoyer') + 'Envoyer maintenant' + (S.a_rappeler ? ' (' + S.a_rappeler + ')' : '') + '</button></div>' +
      (dernier ? '<p class="aide petit">Dernier envoi : ' + quand(dernier.date) + ' · ' + pluriel(dernier.ok, 'SMS') + (dernier.mode === 'automatique' ? ' (automatique)' : '') + (dernier.erreur ? ' · <span class="txt-rouge">' + esc(dernier.erreur) + '</span>' : '') + '</p>' : '') +
      (S.erreur ? '<p class="alerte">' + esc(S.erreur) + '</p>' : '') +
      (admin && !VISITE ? '<details class="sms-avance"><summary>Réglages avancés</summary>' +
        '<p class="aide">L’envoi automatique part dès qu’une page du site ou du back-office est ouverte après ' + S.heure.replace(':', 'h') + ' (l’onglet Réservations ouvert à la boutique suffit). Pour qu’il parte pile à l’heure même quand tout est fermé, faites appeler cette adresse à ' + S.heure.replace(':', 'h') + ' par une tâche planifiée (OVH → Hébergement → Tâches planifiées, ou un service comme cron-job.org) :</p>' +
        '<p class="sms-cron"><code>' + esc(S.cron) + '</code> <button type="button" class="lien" data-act="sms-copier">Copier</button></p>' +
        '<label class="champ"><span>Nom d’expéditeur <em>facultatif</em></span><input data-sms="expediteur" value="' + esc(S.expediteur) + '" maxlength="11" placeholder="ex. JPARMENTIER"><small>11 lettres maximum, à faire valider d’abord chez OVH (Télécom → SMS → Expéditeurs). Vide : le SMS part d’un numéro OVH.</small></label>' +
        '<button type="button" class="btn btn-discret fc-suppr" data-act="sms-oublier">Déconnecter le service SMS</button></details>' : '') +
      '</section>';
  }
  function actionSms(a, b) {
    function ok(j) { SMS = j; majSms(); }
    function ko(e) { toast(e.message, true); }
    if (a === 'sms-copier') { navigator.clipboard.writeText(SMS.cron).then(function () { toast('Adresse copiée.'); }); return; }
    if (VISITE) return toast('Mode visite : aucun SMS n’est envoyé.');
    if (a === 'sms-cles') {
      b.disabled = true; b.textContent = 'Connexion…';
      api('sms-cles', { app_key: document.getElementById('sms-ak').value, app_secret: document.getElementById('sms-as').value, consumer_key: document.getElementById('sms-ck').value })
        .then(function (j) { ok(j); toast('Service SMS connecté.'); }).catch(function (e) { ko(e); b.disabled = false; b.textContent = 'Connecter le service SMS'; });
    } else if (a === 'sms-test') {
      b.disabled = true;
      api('sms-test', { tel: document.getElementById('sms-tel').value }).then(function (j) { ok(j); toast('SMS d’essai envoyé : il arrive en général en quelques secondes.'); })
        .catch(ko).then(function () { b.disabled = false; });
    } else if (a === 'sms-rappels') {
      demander('Envoyer les rappels maintenant ?', pluriel(SMS.a_rappeler, 'client') + ' qui n’' + (SMS.a_rappeler > 1 ? 'ont' : 'a') + ' pas encore récupéré sa commande d’aujourd’hui ' + (SMS.a_rappeler > 1 ? 'vont' : 'va') + ' recevoir le SMS.', [{ txt: 'Annuler', val: false }, { txt: 'Envoyer', val: true, cls: 'btn-violet' }])
        .then(function (r) { if (r) api('sms-rappels', {}).then(function (j) { ok(j); R.charge = ''; toast(pluriel(j.n, 'SMS') + ' envoyé' + (j.n > 1 ? 's' : '') + '.' + (j.avert ? ' ' + j.avert : '')); }).catch(ko); });
    } else if (a === 'sms-defaut') {
      api('sms-options', { message: SMS.defaut }).then(ok).catch(ko);
    } else if (a === 'sms-oublier') {
      demander('Déconnecter le service SMS ?', 'Plus aucun SMS ne partira. Vous pourrez le reconnecter avec de nouvelles clés.', [{ txt: 'Annuler', val: false }, { txt: 'Déconnecter', val: true, cls: 'btn-danger' }])
        .then(function (r) { if (r) api('sms-oublier', {}).then(ok).catch(ko); });
    }
  }
  // Bouton SMS sur une réservation
  function smsUne(r) {
    var c = r.c;
    demander('Envoyer un SMS de rappel ?', esc(nomClient(c) || 'Le client') + ' (' + esc(c.tel) + ') va recevoir : « ' + esc((SMS && SMS.message || '{prenom}, votre commande vous attend').replace(/\{prenom\}/g, c.prenom || c.nom).replace(/\{nom\}/g, c.nom).replace(/\{plats\}/g, r.lignes.map(function (l) { return (l.qte > 1 ? l.qte + ' ' : '') + l.nom; }).join(', '))) + ' »', [{ txt: 'Annuler', val: false }, { txt: 'Envoyer le SMS', val: true, cls: 'btn-violet' }])
      .then(function (ok) {
        if (!ok) return;
        if (VISITE) return toast('Mode visite : aucun SMS n’est envoyé.');
        api('sms-une', { id: r.id }).then(function (j) { remplacerResa(j.resa); toast('SMS envoyé à ' + (nomClient(c) || c.tel) + '.'); }).catch(function (e) { toast(e.message, true); });
      });
  }

  // =============== Affichage ===============
  var VUES = { comptes: vueComptes, stats: vueStats, resas: vueResas, clients: vueClients, evenements: vueEvenements, semaine: vueSemaine, menus: vueCartes, mots: vueMots, avis: vueAvis };
  function render(garderScroll) {
    var y = window.scrollY;
    document.querySelectorAll('[data-onglet]').forEach(function (b) {
      b.setAttribute('aria-selected', String(b.dataset.onglet === onglet));
      b.classList.toggle('modifie', sale(b.dataset.onglet) || (b.dataset.onglet === 'semaine' && sale('semaine_suivante')));
    });
    // « Plus » : affiché en violet (avec le nom de la rubrique) quand on est dans l'un de ses onglets
    var dansPlus = document.querySelector('#plus-liste [data-onglet="' + onglet + '"]');
    document.getElementById('plus-btn').classList.toggle('actif', !!dansPlus);
    document.getElementById('plus-txt').textContent = dansPlus ? dansPlus.textContent : 'Plus';
    app.innerHTML = (VISITE ? '<p class="bandeau-visite">' + ico('oeil') + '<span><b>Mode visite</b> : explorez librement, essayez les champs et les aperçus. Rien n’est enregistré sur le site.</span></p>' : '') +
      '<div class="panneau" data-panneau="' + onglet + '">' + VUES[onglet]() + '</div>';
    if (garderScroll) window.scrollTo(0, y); else window.scrollTo(0, 0);
    barre();
    if (onglet === 'semaine') dessinerApercus();
  }
  var bar = document.getElementById('enreg'), barTxt = document.getElementById('enreg-txt');
  function barre() {
    bar.hidden = !sale(partActive());
    barTxt.textContent = VISITE ? 'Mode visite : ces essais ne seront pas enregistrés' : 'Modifications non enregistrées sur ' + NOMS[partActive()];
    document.getElementById('enregistrer').hidden = VISITE;
    document.getElementById('annuler').textContent = VISITE ? 'Revenir à l’original' : 'Annuler';
    document.querySelectorAll('[data-onglet]').forEach(function (b) { b.classList.toggle('modifie', sale(b.dataset.onglet) || (b.dataset.onglet === 'semaine' && sale('semaine_suivante'))); });
    document.querySelectorAll('.choix-sem [data-s]').forEach(function (b) { b.classList.toggle('modifie', sale(b.dataset.s)); });
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
    if (VISITE) { toast('Mode visite : les modifications ne sont pas enregistrées.'); return Promise.resolve(false); }
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
      if (part === SEM) specialOuvert = aSpecial(j.data);
      render(true);
      toast('Enregistré : ' + NOMS[part] + ' ' + (NOMS[part].indexOf('les ') === 0 ? 'sont' : 'est') + ' à jour sur le site.');
      return true;
    }).catch(function (e) {
      toast(e.message === 'Failed to fetch' ? 'Pas de connexion internet : rien n’a été perdu, réessayez dans un instant.' : e.message, true);
      return false;
    }).then(function (r) { enCours = false; btn.disabled = false; btn.textContent = 'Enregistrer'; return r; });
  }
  document.getElementById('enregistrer').addEventListener('click', function () { enregistrer(partActive()); });
  document.getElementById('annuler').addEventListener('click', function () {
    var part = partActive();
    demander('Annuler les modifications ?', 'Vous revenez à la dernière version enregistrée de ' + NOMS[part] + '.', [
      { txt: 'Garder mes modifications', val: false }, { txt: 'Annuler les modifications', val: true, cls: 'btn-danger' }
    ]).then(function (r) {
      if (!r) return;
      site[part] = clone(saved[part]);
      if (onglet === 'semaine') specialOuvert = aSpecial(site[SEM]);
      render(true);
    });
  });
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); if (sale(partActive())) enregistrer(partActive()); }
  });
  window.addEventListener('beforeunload', function (e) {
    if (VISITE) return;
    if (PARTS.some(sale)) { e.preventDefault(); e.returnValue = ''; }
  });

  // =============== Événements ===============
  // Menu « Plus » (Avis, Mots défilants, Statistiques)
  var plusBtn = document.getElementById('plus-btn'), plusListe = document.getElementById('plus-liste');
  function plusOuvrir(o) {
    plusListe.hidden = !o; plusBtn.setAttribute('aria-expanded', String(o));
    if (!o) return;
    var r = plusBtn.getBoundingClientRect();
    plusListe.style.top = (r.bottom + 6) + 'px';
    plusListe.style.left = Math.max(8, Math.min(r.left, window.innerWidth - plusListe.offsetWidth - 8)) + 'px';
    plusListe.querySelector('[aria-selected="true"]') ? plusListe.querySelector('[aria-selected="true"]').focus() : plusListe.querySelector('button').focus();
  }
  plusBtn.addEventListener('click', function (e) { e.stopPropagation(); plusOuvrir(plusListe.hidden); });
  plusListe.addEventListener('click', function (e) {
    var b = e.target.closest('[data-onglet]'); if (!b) return;
    e.stopPropagation();
    plusOuvrir(false);
    onglet = b.dataset.onglet; ecrire('onglet', onglet); carteOuverte = null; render();
  });
  plusListe.addEventListener('keydown', function (e) {
    var l = Array.prototype.slice.call(plusListe.querySelectorAll('button')), i = l.indexOf(document.activeElement);
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); l[(i + (e.key === 'ArrowDown' ? 1 : l.length - 1)) % l.length].focus(); }
    if (e.key === 'Escape' || e.key === 'Tab') { plusOuvrir(false); if (e.key === 'Escape') plusBtn.focus(); }
  });
  document.addEventListener('click', function (e) { if (!plusListe.hidden && !plusListe.contains(e.target)) plusOuvrir(false); });
  window.addEventListener('resize', function () { plusOuvrir(false); });
  document.querySelector('.onglets').addEventListener('scroll', function () { plusOuvrir(false); });

  document.querySelector('.barre-haut').addEventListener('click', function (e) {
    var b = e.target.closest('[data-onglet]'); if (!b) return;
    onglet = b.dataset.onglet; ecrire('onglet', onglet);
    if (onglet === 'resas') R.charge = '';   // on recharge : quelqu'un d'autre a pu prendre des réservations
    if (onglet === 'clients') { CL = null; SMS = null; }
    if (onglet !== 'menus') carteOuverte = null;
    render();
  });

  app.addEventListener('input', function (e) {
    var t = e.target, p = t.dataset.p;
    if (t.id === 'r-q') { R.q = t.value; majListe(); return; }
    if (t.id === 'cl-q') { clQ = t.value; majClientsListe(); return; }
    if (t.dataset.sms === 'message') { var sc = document.getElementById('sms-compte'); if (sc) sc.innerHTML = compteSms(t.value); return; }
    if (!p || t.type === 'radio' || t.type === 'color' || t.type === 'checkbox') return;
    var v = t.value;
    if ('lignes' in t.dataset) v = v.split('\n');
    else if ('nombre' in t.dataset) v = +v;
    set(p, v);
    if (/^semaine(_suivante)?\.du$/.test(p) && v) {
      var sw = site[p.split('.')[0]];
      if (!sw.au || sw.au < v) { sw.au = plus(v, 4); var f = app.querySelector('[data-p="' + p.split('.')[0] + '.au"]'); if (f) f.value = sw.au; }
    }
    if (/\.titre$/.test(p) && onglet === 'menus') {
      var r = t.closest('details'); if (r) r.querySelector('.cat-resume strong').textContent = v || 'Catégorie sans nom';
    }
    apresSaisie();
  });
  app.addEventListener('change', function (e) {
    var t = e.target;
    if (t.id === 'r-cal') { if (t.value) { R.date = t.value; render(true); } return; }
    if (t.dataset.sms) {
      var o = {}; o[t.dataset.sms] = t.type === 'checkbox' ? t.checked : t.value;
      api('sms-options', o).then(function (j) { SMS = j; majSms(); R.charge = ''; toast(t.dataset.sms === 'auto' ? (t.checked ? 'Rappel automatique activé : chaque jour à ' + j.heure.replace(':', 'h') + '.' : 'Rappel automatique désactivé.') : 'Réglage enregistré.'); })
        .catch(function (er) { toast(er.message, true); });
      return;
    }
    if (t.dataset.cmd) {
      var oc = {}; oc[t.dataset.cmd] = t.type === 'checkbox' ? t.checked : t.value;
      api('commande-options', oc).then(function (j) {
        E.commande = j.commande; E.commande.jours = j.jours; render(true);
        toast(t.dataset.cmd === 'actif' ? (t.checked ? 'Réservation en ligne ouverte : le bouton « Réserver » est sur le site.' : 'Réservation en ligne fermée.') : 'Heure limite enregistrée.');
      }).catch(function (er) { toast(er.message, true); render(true); });
      return;
    }
    if ('lettreAuto' in t.dataset) {
      api('lettre-auto', { auto: t.checked }).then(function (j) { CL = j.clients; LETTRE = j.lettre; majClients(); toast(t.checked ? 'Envoi automatique activé : le menu partira chaque lundi matin.' : 'Envoi automatique désactivé.'); })
        .catch(function (er) { t.checked = !t.checked; toast(er.message, true); });
      return;
    }
    if (t.type === 'radio' && t.dataset.p) { set(t.dataset.p, t.value); apresSaisie(); render(true); return; }
    if (t.type === 'checkbox' && t.dataset.p) { set(t.dataset.p, t.checked); render(true); return; }
    if (t.type === 'color' && t.dataset.p) { set(t.dataset.p, t.value); render(true); return; }
    if ('g' in t.dataset) {
      var o = { afficher_note: G.afficher_note, afficher_avis: G.afficher_avis, note_min: G.note_min };
      o[t.dataset.g] = t.type === 'checkbox' ? t.checked : +t.value;
      api('google-options', o).then(function (j) { G = j; majGoogle(); toast('Réglage enregistré.'); }).catch(function (e) { toast(e.message, true); });
      return;
    }
    if (t.type === 'file' && t.dataset.envoi) { envoyerPhoto(t); return; }
    if ('prix' in t.dataset && t.dataset.p) { var f = formatPrix(t.value); if (f !== t.value) { t.value = f; set(t.dataset.p, f); apresSaisie(); } }
    if (t.dataset.p && /^semaine(_suivante)?\.(du|au)$/.test(t.dataset.p)) {
      var st = statutSemaine(site[SEM]), el = document.getElementById('statut-sem');
      if (el) { el.className = 'statut statut-' + st[0]; el.textContent = st[1]; }
    }
    if (t.matches('select[data-p$=".jour"]')) apresSaisie();
  });
  // Entrée sur le prix du dernier plat : ajoute une ligne
  app.addEventListener('keydown', function (e) {
    var t = e.target;
    if (e.key !== 'Enter' || t.tagName !== 'INPUT') return;
    if (t.id === 'r-q') {
      // Entrée sur un numéro de commande absent de ce jour : on va le chercher sur tous les jours
      var n = numCherche(t.value), jr = resasDuJour() || [];
      if (n && !jr.some(function (r) { return r.num === n; })) allerNum(n);
      e.preventDefault(); return;
    }
    if (t.id === 'cl-q') { e.preventDefault(); return; }
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
      if (!specialOuvert) site[SEM].special = { titre: '', nom: '', prix: '', lignes: [] };
      render(true);
    } else if (a.indexOf('g-') === 0) {
      actionGoogle(a, b);
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
    } else if (a === 'sem') {
      SEM = b.dataset.s; ecrire('sem', SEM);
      if (SEM === 'semaine_suivante' && !site.semaine_suivante.du && !semaineRemplie(site.semaine_suivante)) preparerSuivante(false);
      specialOuvert = aSpecial(site[SEM]); render(true);
    } else if (a === 'copier-semaine' || a === 'vider-semaine') {
      var copier = a === 'copier-semaine';
      (semaineRemplie(site.semaine_suivante) ? demander(copier ? 'Reprendre les plats de la semaine en cours ?' : 'Tout effacer ?', copier ? 'Les plats déjà saisis pour la semaine suivante seront remplacés.' : 'Les plats de la semaine suivante seront effacés (les dates sont conservées).', [{ txt: 'Annuler', val: false }, { txt: copier ? 'Reprendre' : 'Effacer', val: true, cls: copier ? 'btn-violet' : 'btn-danger' }]) : Promise.resolve(true))
        .then(function (r) { if (r) { preparerSuivante(copier); specialOuvert = aSpecial(site.semaine_suivante); render(true); } });
    } else if (a === 'imprimer') {
      ouvrirApres(SEM, SEM === 'semaine_suivante' ? 'affiche?s=suivante' : 'affiche');
    } else if (a === 'imprimer-carte') {
      ouvrirApres('menus', 'carte-traiteur?c=' + b.dataset.i);
    } else if (a === 'r-date') {
      R.date = b.dataset.d; render(true);
    } else if (a === 'r-num') {
      allerNum(+b.dataset.n);
    } else if (a === 'r-filtre') {
      R.filtre = b.dataset.f; majResas();
    } else if (a === 'r-etat') {
      changerEtat(b);
    } else if (a === 'resa-nouvelle') {
      formResa(null);
    } else if (a === 'resa-modif') {
      formResa(resaParId(b.dataset.id));
    } else if (a === 'resa-suppr') {
      var rs = resaParId(b.dataset.id);
      demander('Supprimer cette réservation ?', 'La commande de ' + esc(nomClient(rs.c) || 'ce client') + ' pour ' + esc(dateLongue(rs.date)) + ' sera supprimée.', [{ txt: 'Garder', val: false }, { txt: 'Supprimer', val: true, cls: 'btn-danger' }])
        .then(function (r) {
          if (!r) return;
          if (VISITE) return toast('Mode visite : rien n’est supprimé.');
          api('resa-suppr', { id: rs.id }).then(function () { R.data.resas = R.data.resas.filter(function (x) { return x.id !== rs.id; }); chargerClients(); majResas(); toast('Réservation supprimée.'); }).catch(function (er) { toast(er.message, true); });
        });
    } else if (a === 'r-imprimer') {
      window.print();
    } else if (a === 'cl-filtre') {
      clFiltre = b.dataset.f; majClients();
    } else if (a === 'client-fiche') {
      ficheClient(b.dataset.id ? CL.filter(function (c) { return c.id === b.dataset.id; })[0] : null);
    } else if (a.indexOf('sms-') === 0) {
      actionSms(a, b);
    } else if (a === 'r-sms') {
      if (SMS === null) chargerSms();
      smsUne(resaParId(b.dataset.id));
    } else if (a === 'lettre-test' || a === 'lettre-envoyer') {
      actionLettre(a, b);
    } else if (a === 'aller') {
      var cible = document.getElementById(b.dataset.cible);
      if (cible) cible.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });

  // Ouvre une page à imprimer, après avoir enregistré les modifications en cours (elle lit les données enregistrées)
  function ouvrirApres(part, url) {
    if (VISITE) { window.open(url, '_blank'); return; }
    var w = window.open('about:blank', '_blank');
    (sale(part) ? enregistrer(part) : Promise.resolve(true)).then(function (ok) {
      if (!w) return toast('Autorisez les fenêtres pop-up pour imprimer.', true);
      if (ok) w.location.href = url; else w.close();
    });
  }
  function semaineRemplie(s) { return s.conges.actif || s.jours.some(function (j) { return j.nom || j.ferme; }); }
  // Semaine suivante : dates de la semaine en cours + 7 jours, mêmes jours ; plats repris (copier) ou vides
  function preparerSuivante(copier) {
    var s = site.semaine, n = site.semaine_suivante, du = n.du || (s.du ? plus(s.du, 7) : ''), au = n.au || (s.au ? plus(s.au, 7) : (du ? plus(du, 4) : ''));
    site.semaine_suivante = {
      titre: s.titre || 'Inspirations de la semaine', du: du, au: au, publie: n.publie || false, note: copier ? s.note : '',
      jours: s.jours.map(function (j) { return copier ? clone(j) : { jour: j.jour, nom: '', desc: '', prix: '', ferme: false, motif: '', message: '' }; }),
      special: copier ? clone(s.special) : { titre: '', nom: '', prix: '', lignes: [] },
      conges: { actif: false, titre: '', message: '' }
    };
    if (!site.semaine_suivante.jours.length) site.semaine_suivante.jours = ['Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'].map(function (d) { return { jour: d, nom: '', desc: '', prix: '', ferme: false, motif: '', message: '' }; });
  }


  // Réservations : mise à jour toutes les minutes (plusieurs personnes peuvent prendre des commandes en même temps)
  setInterval(function () {
    if (onglet !== 'resas' || document.hidden || document.querySelector('.modale-fond') || document.activeElement.id === 'r-q') return;
    R.charge = ''; chargerResas();
  }, 60000);

  if (!VUES[onglet]) onglet = 'resas';
  render();
})();
