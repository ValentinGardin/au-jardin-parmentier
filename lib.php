<?php
// Fonctions communes au site (index.php), à l'affiche (affiche.php) et au back-office (back-office-jardin/)
const DATA = __DIR__ . '/data';
const ESPACE = 'back-office-jardin';   // dossier du back-office : https://…/back-office-jardin/
const VERSION = '1.63 · 4 novembre 2026';
const FORTE_DEMANDE = 15;   // parts réservées à partir desquelles un plat est signalé « forte demande »
const FORTE_OFFRE = 10;     // même chose pour l'offre spéciale et les produits des événements   // affichée en bas de la page de connexion du back-office
const JOURS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
date_default_timezone_set('Europe/Paris');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
// Adresse d'un fichier CSS ou JS : sa version compacte (.min, créée par outils/build.mjs) si elle est à jour,
// suivie d'un numéro de version tiré de la date du fichier (le navigateur recharge dès qu'il change)
function asset($f) {
    $min = preg_replace('/\.(js|css)$/', '.min.$1', $f);
    $src = @filemtime(__DIR__ . '/' . $f); $m = @filemtime(__DIR__ . '/' . $min);
    $u = $m && $m >= $src ? $min : $f;
    return $u . '?v=' . base_convert((string)max($m, $src), 10, 36);
}

function site_load() {
    // site.json est créé par le back-office ; tant qu'il n'existe pas, le contenu de départ (site.exemple.json) est affiché
    $f = is_file(DATA . '/site.json') ? DATA . '/site.json' : DATA . '/site.exemple.json';
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    $d = site_clean(is_array($d) ? $d : []);
    // Bascule automatique : le dimanche à minuit (ou dès le lendemain du dernier jour s'il tombe plus tôt),
    // le menu préparé dans « Semaine suivante » devient le menu en cours
    $n = $d['semaine_suivante']; $t = date('Y-m-d');
    if ($n['du'] !== '' && semaine_remplie($n) && ($n['du'] <= $t || (($b = date_bascule($d['semaine'])) !== '' && $b <= $t && $n['du'] > $d['semaine']['du']))) {
        unset($n['publie']);
        $d['semaine'] = $n;
        $d['semaine_suivante'] = semaine_suivante_clean([]);
        if (is_file(DATA . '/site.json')) json_save(DATA . '/site.json', $d);
    }
    return $d;
}

// ---- Nettoyage des données : utilisé au chargement et à l'enregistrement ----
function txt($v, $n) { return cut(trim(preg_replace('/\s+/u', ' ', (string)(is_scalar($v) ? $v : ''))), $n); }
function liste($v) { return is_array($v) ? array_values(array_filter($v, 'is_array')) : []; }
function liste_txt($v, $n) {
    $out = [];
    foreach (is_array($v) ? $v : lignes((string)$v) as $l) { $l = txt($l, $n); if ($l !== '') $out[] = $l; }
    return $out;
}
function date_ok($v) { $v = (string)$v; return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : ''; }

function plat_clean($p) {
    return ['nom' => txt($p['nom'] ?? '', 140), 'desc' => txt($p['desc'] ?? '', 300), 'prix' => txt($p['prix'] ?? '', 30)];
}
function carte_clean($c) {
    $cats = [];
    foreach (liste($c['cats'] ?? []) as $k) {
        $plats = [];
        foreach (liste($k['plats'] ?? []) as $p) { $p = plat_clean($p); if ($p['nom'] !== '' || $p['desc'] !== '') $plats[] = $p; }
        $cat = ['titre' => txt($k['titre'] ?? '', 80), 'desc' => txt($k['desc'] ?? '', 160), 'prix' => txt($k['prix'] ?? '', 30), 'plats' => $plats];
        if ($cat['titre'] !== '' || $plats) $cats[] = $cat;
    }
    $photo = preg_replace('/[^a-z0-9-]/', '', (string)($c['photo'] ?? ''));
    return [
        // « actif » : carte en ligne sur le site (les cartes d'avant cette option restent en ligne)
        'actif' => !array_key_exists('actif', $c) || !empty($c['actif']),
        'titre' => txt($c['titre'] ?? '', 60) ?: 'Menu', 'photo' => $photo, 'intro' => txt($c['intro'] ?? '', 300),
        'prix' => txt($c['prix'] ?? '', 40), 'cats' => $cats, 'note' => txt($c['note'] ?? '', 300), 'encart' => liste_txt($c['encart'] ?? [], 200),
    ];
}
function semaine_clean($s) {
    $s = is_array($s) ? $s : [];
    $jours = [];
    foreach (liste($s['jours'] ?? []) as $j) {
        // « ferme » : jour férié ou fermeture exceptionnelle, avec un titre (motif) et un message à la place du plat
        $j = ['jour' => txt($j['jour'] ?? '', 30), 'nom' => txt($j['nom'] ?? '', 140), 'desc' => txt($j['desc'] ?? '', 200), 'prix' => txt($j['prix'] ?? '', 20),
              'ferme' => !empty($j['ferme']), 'motif' => txt($j['motif'] ?? '', 60), 'message' => txt($j['message'] ?? '', 200),
              'epuise' => !empty($j['epuise'])];   // plus disponible (tout est parti) : plus réservable
        if ($j['jour'] !== '' || $j['nom'] !== '') $jours[] = $j;
    }
    $sp = is_array($s['special'] ?? null) ? $s['special'] : [];
    return [
        'titre' => txt($s['titre'] ?? '', 60) ?: 'Inspirations de la semaine',
        'du' => date_ok($s['du'] ?? ''), 'au' => date_ok($s['au'] ?? ''), 'jours' => $jours,
        'special' => ['titre' => txt($sp['titre'] ?? '', 60), 'nom' => txt($sp['nom'] ?? '', 100), 'prix' => txt($sp['prix'] ?? '', 20), 'lignes' => liste_txt($sp['lignes'] ?? [], 160), 'epuise' => !empty($sp['epuise'])],
        'note' => txt($s['note'] ?? '', 200),
        // Fermeture de toute la semaine (congés) : un titre et un message remplacent le menu
        'conges' => ['actif' => !empty($s['conges']['actif']), 'titre' => txt($s['conges']['titre'] ?? '', 80), 'message' => txt($s['conges']['message'] ?? '', 400)],
    ];
}
// Jours à afficher : ceux qui ont un plat, ou qui sont marqués fermés
// Semaine suivante : mêmes champs, plus « publie » (affichée en avance sur le site avec le bouton « Semaine suivante »)
// Jour où le menu de la semaine laisse la place au suivant : le dimanche de sa semaine,
// ou le lendemain de son dernier jour si celui-ci tombe avant (ex. menu du mardi au vendredi → samedi)
function date_bascule($s) {
    if ($s['du'] === '') return '';
    $dimanche = date('Y-m-d', strtotime('sunday this week', strtotime($s['du'])));
    $lendemain = $s['au'] !== '' ? date('Y-m-d', strtotime($s['au'] . ' +1 day')) : $dimanche;
    return min($dimanche, $lendemain);
}
function semaine_suivante_clean($s) {
    return semaine_clean($s) + ['publie' => !empty($s['publie'])];
}
function semaine_remplie($s) {
    return $s['conges']['actif'] || (bool)jours_visibles($s['jours']);
}
function jours_visibles($jours) { return array_values(array_filter($jours, function ($j) { return $j['nom'] !== '' || $j['ferme']; })); }
// Couleurs suggérées pour les événements (toute autre couleur peut être choisie dans le back-office)
const THEMES = ['violet' => '#700954', 'vin' => '#5e1426', 'sapin' => '#1c4634', 'printemps' => '#4f7a2b', 'or' => '#2a2118'];
function hex_ok($v) { $v = strtolower(trim((string)$v)); return preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : ''; }
// Couleurs dérivées : texte lisible (blanc ou encre) et accent pour les prix, selon la clarté du fond
function couleurs_evt($hex) {
    $r = hexdec(substr($hex, 1, 2)); $g = hexdec(substr($hex, 3, 2)); $b = hexdec(substr($hex, 5, 2));
    $lin = function ($c) { $c /= 255; return $c <= .03928 ? $c / 12.92 : pow(($c + .055) / 1.055, 2.4); };
    $L = .2126 * $lin($r) + .7152 * $lin($g) + .0722 * $lin($b);
    $clair = $L > .35;
    $m = function ($t) use ($r, $g, $b) { return sprintf('#%02x%02x%02x', $r + ($t - $r) * .72, $g + ($t - $g) * .72, $b + ($t - $b) * .72); };
    return ['fond' => $hex, 'texte' => $clair ? '#1d171b' : '#ffffff', 'accent' => $clair ? $m(0) : $m(255), 'clair' => $clair];
}
// Catégorie avec un prix = une formule « tout compris » : le client commande la catégorie, ses produits sont le contenu.
// Prix de la catégorie d'un produit d'événement ('' si la catégorie n'a pas de prix)
function evt_prix_cat($items, $k) {
    for ($i = $k; $i >= 0; $i--) if ($items[$i]['cat']) return $items[$i]['prix'];
    return '';
}
// Prix d'un produit d'événement : celui de sa catégorie s'il y en a un (ex. « Entrées · 9.00 € » : chaque entrée à 9 €), sinon le sien
function evt_prix($items, $k) {
    $c = $items[$k]['cat'] ? $items[$k]['prix'] : evt_prix_cat($items, $k);
    return $c !== '' ? $c : $items[$k]['prix'];
}
// Unités de prix d'un format : libellé par défaut et suffixe du prix (ex. « 32.00 €/kg »)
const UNITES = ['' => ['Format', ''], 'kg' => ['Au kilo', '/kg'], '500g' => ['Les 500 g', '/500 g'], 'portion' => ['La portion', '/portion'], 'pers' => ['Par personne', '/pers.']];
function unite_de($f) { return $f['unite'] ?? (!empty($f['kg']) ? 'kg' : ''); }
function format_nom($f) { return $f['nom'] !== '' ? $f['nom'] : UNITES[unite_de($f)][0]; }
function format_suffixe($f) { return UNITES[unite_de($f)][1]; }
// Contenu d'une formule (les produits sous une catégorie à prix)
function evt_contenu($items, $k) {
    $l = [];
    for ($i = $k + 1; $i < count($items) && !$items[$i]['cat']; $i++) $l[] = $items[$i]['nom'] . (($items[$i]['accomp'] ?? '') !== '' ? ' (' . $items[$i]['accomp'] . ')' : '');
    return $l;
}
// Liste de produits (événements et commandes spéciales) : catégories, formules, produits avec formats et choix
function produits_clean($liste) {
    $items = [];
    foreach (liste($liste) as $p) {
        // « cat » : un titre de catégorie (Entrées, Plats, Desserts…) et non un produit
        $p = !empty($p['cat']) ? ['nom' => txt($p['nom'] ?? '', 60), 'prix' => txt($p['prix'] ?? '', 30), 'epuise' => !empty($p['epuise']) && trim((string)($p['prix'] ?? '')) !== '', 'cat' => true]
                               : ['nom' => txt($p['nom'] ?? '', 140), 'prix' => txt($p['prix'] ?? '', 30), 'epuise' => !empty($p['epuise']), 'cat' => false,
                                  'desc' => txt($p['desc'] ?? '', 160),
                                  // 2e ligne sous le nom du plat : l'accompagnement (ex. « Gratin dauphinois et marrons »)
                                  'accomp' => txt($p['accomp'] ?? '', 140),
                                  // formats de vente (Entier, Demi, Tranche, Au kilo, Douzaine…) : chacun son prix ; « kg » = prix au kilo, le client choisit un poids
                                  'formats' => array_slice(array_values(array_filter(array_map(function ($f) {
                                      // unité du prix : '' (à l'unité), kg, 500g (le client choisit un poids), portion, pers (par personne)
                                      $u = in_array($f['unite'] ?? null, ['', 'kg', '500g', 'portion', 'pers'], true) ? $f['unite'] : (!empty($f['kg']) ? 'kg' : '');
                                      return ['nom' => txt($f['nom'] ?? '', 40), 'prix' => txt($f['prix'] ?? '', 30), 'unite' => $u, 'kg' => $u === 'kg' || $u === '500g'];
                                  }, liste($p['formats'] ?? [])), function ($f) { return $f['nom'] !== '' || $f['unite'] !== ''; })), 0, 8),
                                  // un choix à faire (ex. Huîtres : Ouvertes / Fermées), avec un supplément éventuel
                                  'option' => ['titre' => txt($p['option']['titre'] ?? '', 40), 'choix' => array_slice(array_values(array_filter(array_map(function ($c) {
                                      return ['nom' => txt($c['nom'] ?? '', 40), 'prix' => txt($c['prix'] ?? '', 30)];
                                  }, liste($p['option']['choix'] ?? [])), function ($c) { return $c['nom'] !== ''; })), 0, 6)]];
        if ($p['nom'] !== '') $items[] = $p;
    }
    return $items;
}
function evenement_clean($e) {
    $items = produits_clean($e['items'] ?? []);
    $r = is_array($e['reservation'] ?? null) ? $e['reservation'] : [];
    return [
        'actif' => !empty($e['actif']),
        'titre' => txt($e['titre'] ?? '', 80) ?: 'Événement',
        'date' => date_ok($e['date'] ?? ''),
        // dernier jour si l'événement dure plusieurs jours (ex. Noël : 24 et 25 décembre)
        'date_fin' => ($f = date_ok($e['date_fin'] ?? '')) !== '' && date_ok($e['date'] ?? '') !== '' && $f > $e['date'] ? $f : '',
        'accroche' => txt($e['accroche'] ?? '', 200),
        'items' => $items,
        'lignes' => liste_txt($e['lignes'] ?? [], 160),
        'cta' => txt($e['cta'] ?? '', 80),
        'reservation' => ['active' => !empty($r['active']), 'date' => date_ok($r['date'] ?? '')],
        'couleur' => hex_ok($e['couleur'] ?? '') ?: (THEMES[$e['theme'] ?? ''] ?? THEMES['violet']),
        'popup' => !empty($e['popup']),
        'photo' => preg_replace('/[^a-z0-9-]/', '', (string)($e['photo'] ?? '')),
        'masquer_apres' => !array_key_exists('masquer_apres', $e) || !empty($e['masquer_apres']),
    ];
}
// Événements à afficher sur le site : activés, et pas encore passés (si « masquer après la date » est coché)
function evenements_visibles($liste) {
    $t = date('Y-m-d');
    $v = array_values(array_filter($liste, function ($e) use ($t) {
        return $e['actif'] && !($e['masquer_apres'] && $e['date'] !== '' && evt_fin($e) < $t);
    }));
    usort($v, function ($a, $b) { return strcmp($a['date'] ?: '9999', $b['date'] ?: '9999'); });
    return $v;
}
function reservation_ouverte($e) { return !$e['reservation']['active'] || $e['reservation']['date'] === '' || date('Y-m-d') <= $e['reservation']['date']; }

// Commandes spéciales (raclette, corbeilles de fruits, plateaux de fromages…) : à commander à l'avance, en ligne ou non
function commande_speciale_clean($c) {
    $c = is_array($c) ? $c : [];
    return [
        'actif' => !empty($c['actif']),
        'titre' => txt($c['titre'] ?? '', 80) ?: 'Nouvelle offre',
        'accroche' => txt($c['accroche'] ?? '', 240),
        'delai' => txt($c['delai'] ?? '', 80),   // ex. « À commander 48 h à l'avance »
        'photo' => preg_replace('/[^a-z0-9-]/', '', (string)($c['photo'] ?? '')),
        'items' => produits_clean($c['items'] ?? []),
        'note' => txt($c['note'] ?? '', 240),
    ];
}
function site_clean($d) {
    $mots = liste_txt($d['mots'] ?? [], 40);
    $avis = [];
    foreach (liste($d['avis'] ?? []) as $a) {
        $a = ['nom' => txt($a['nom'] ?? '', 60), 'note' => max(0, min(5, (int)($a['note'] ?? 0))), 'texte' => txt($a['texte'] ?? '', 600)];
        if ($a['texte'] !== '') $avis[] = $a;
    }
    return [
        'evenements' => array_map('evenement_clean', liste($d['evenements'] ?? [])),
        'semaine' => semaine_clean($d['semaine'] ?? []),
        'semaine_suivante' => semaine_suivante_clean($d['semaine_suivante'] ?? []),
        'menus' => array_map('carte_clean', liste($d['menus'] ?? [])),
        'commandes' => array_map('commande_speciale_clean', liste($d['commandes'] ?? [])),
        'mots' => $mots ?: ['épicerie fine', 'traiteur', 'charcuterie', 'fromagerie', 'fruits et légumes'],
        'avis' => $avis,
        // Foire aux questions (bas de la page d'accueil, et réponses directes dans Google)
        'faq' => array_key_exists('faq', $d) ? faq_clean($d['faq']) : FAQ_DEPART,
    ];
}

// ---- Dates en français ----
function date_fr($iso, $avecJour = false) {
    $t = strtotime((string)$iso);
    if (!$t) return '';
    $j = (int)date('j', $t);
    return ($avecJour ? strtolower(JOURS[(int)date('N', $t) - 1]) . ' ' : '') . ($j === 1 ? '1er' : $j) . ' ' . MOIS[(int)date('n', $t) - 1];
}
// « du 27 au 31 octobre 2026 »
function periode_fr($du, $au) {
    $a = strtotime((string)$du); $b = strtotime((string)$au);
    if (!$a) return '';
    if (!$b || $b < $a) return 'à partir du ' . date_fr($du) . ' ' . date('Y', $a);
    $debut = date('n', $a) === date('n', $b) ? ((int)date('j', $a) === 1 ? '1er' : date('j', $a)) : date_fr($du);
    return 'du ' . $debut . ' au ' . date_fr($au) . ' ' . date('Y', $b);
}
function jour_actuel() { return JOURS[(int)date('N') - 1]; }
// La semaine est-elle en cours (aujourd'hui entre du et au) ?
function semaine_en_cours($s) {
    $t = date('Y-m-d');
    return $s['du'] !== '' && $s['du'] <= $t && ($s['au'] === '' || $t <= $s['au']);
}
function norm($s) { return strtolower(strtr(trim((string)$s), ['é' => 'e', 'É' => 'e', 'è' => 'e'])); }

// ---- Photos ----
// Déposez img/photos/<nom>.webp (ou .jpg / .png). Sans photo, le site affiche un visuel de remplacement.
function photo($nom) {
    $f = photo_fichier($nom);
    return $f ? $f . '?v=' . filemtime(__DIR__ . '/' . $f) : null;
}
// Chemin du fichier d'une photo (sans ?v=), ou null
function photo_fichier($nom) {
    if ($nom === '' || $nom === null) return null;
    foreach (['webp', 'jpg', 'jpeg', 'png'] as $ext) {
        $f = 'img/photos/' . $nom . '.' . $ext;
        if (is_file(__DIR__ . '/' . $f)) return $f;
    }
    return null;
}
// Photos utilisées directement par la mise en page (accueil, rayons, équipe) : on ne peut pas les supprimer du back-office
const PHOTOS_SITE = ['hero', 'legumes', 'charcuterie', 'equipe', 'traiteur', 'fromagerie', 'epicerie', 'vins'];
// Texte alternatif (référencement + lecteurs d'écran) : décrit la photo et rappelle la boutique et la ville
function alt_photo($quoi) {
    $quoi = trim((string)$quoi);
    return ($quoi !== '' ? $quoi . ' · ' : '') . 'Au Jardin Parmentier, épicerie fine et traiteur à Montdidier';
}
// Les versions réduites (photo-400.webp, photo-800.webp) ne sont pas proposées dans le back-office
function photos_dispo() {
    $l = [];
    foreach (glob(__DIR__ . '/img/photos/*.{webp,jpg,jpeg,png}', GLOB_BRACE) ?: [] as $f) {
        $n = pathinfo($f, PATHINFO_FILENAME);
        if (!preg_match('/-(400|600|800)$/', $n)) $l[] = $n;
    }
    return array_values(array_unique($l));
}
// Bandeau photo défilant : toutes les images du dossier img/galerie/ (ordre alphabétique)
function photos_galerie() {
    $l = array_filter(glob(__DIR__ . '/img/galerie/*.{webp,jpg,jpeg,png}', GLOB_BRACE) ?: [], function ($f) { return !preg_match('/-(400|600|800)\.\w+$/', $f); });
    sort($l);
    return array_map(function ($f) { return 'img/galerie/' . basename($f); }, $l);
}

// ---- Images à la bonne taille (performance) ----
// Pour chaque photo, des versions de 400 et 800 px de large sont créées une seule fois (à côté de l'original),
// et le navigateur choisit la plus adaptée à l'écran grâce à srcset. Sans GD, seule la photo d'origine est utilisée.
const LARGEURS = [400, 600, 800];
function variantes($rel) {
    $rel = preg_replace('/\?.*$/', '', (string)$rel);
    $abs = __DIR__ . '/' . $rel;
    if (!is_file($abs)) return [];
    $info = @getimagesize($abs);
    $w0 = $info ? $info[0] : 0;
    $v = '?v=' . filemtime($abs);
    $base = preg_replace('/\.\w+$/', '', $rel);
    $out = [];
    foreach (LARGEURS as $w) {
        if ($w0 && $w >= $w0) continue;
        $f = $base . '-' . $w . '.webp';
        if (!is_file(__DIR__ . '/' . $f) || filemtime(__DIR__ . '/' . $f) < filemtime($abs)) reduire($abs, __DIR__ . '/' . $f, $w);
        if (is_file(__DIR__ . '/' . $f)) $out[] = [$f . $v, $w];
    }
    $out[] = [$rel . $v, $w0 ?: 1600];
    return $out;
}
function reduire($src, $dst, $largeur) {
    if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) return false;
    $im = @imagecreatefromstring((string)file_get_contents($src));
    if (!$im) return false;
    $w = imagesx($im); $h = imagesy($im);
    $r = imagecreatetruecolor($largeur, (int)round($h * $largeur / $w));
    imagecopyresampled($r, $im, 0, 0, 0, 0, imagesx($r), imagesy($r), $w, $h);
    return @imagewebp($r, $dst, 76);
}
// Attributs src + srcset + sizes pour une balise <img>
function img_attrs($rel, $sizes) {
    $v = variantes($rel);
    if (!$v) return 'src="' . h($rel) . '"';
    $src = $v[min(1, count($v) - 1)][0];   // repli : la version moyenne
    $set = implode(', ', array_map(function ($x) { return h($x[0]) . ' ' . $x[1] . 'w'; }, $v));
    return 'src="' . h($src) . '" srcset="' . $set . '" sizes="' . h($sizes) . '"';
}

// Écriture atomique : fichier temporaire puis renommage
function json_save($file, $data) {
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (!rename($tmp, $file)) { @unlink($tmp); return false; }
    return true;
}

// Coupe un texte à $n caractères (sans dépendre de l'extension mbstring)
function cut($s, $n) { return function_exists('mb_substr') ? mb_substr((string)$s, 0, $n) : substr((string)$s, 0, $n); }
function lignes($t) { return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)$t) ?: []), 'strlen')); }

// ---- Foire aux questions ----
function faq_clean($l) {
    $out = [];
    foreach (liste($l) as $x) {
        $x = ['q' => txt($x['q'] ?? '', 200), 'r' => cut(trim(preg_replace("/[ \t]+/u", ' ', str_replace("\r", '', (string)($x['r'] ?? '')))), 1500)];
        if ($x['q'] !== '' && $x['r'] !== '') $out[] = $x;
    }
    return $out;
}
// Questions de départ : rédigées comme les gens les tapent dans Google (à adapter dans le back-office)
const FAQ_DEPART = [
    ['q' => 'Où trouver un plat du jour à emporter à Montdidier ?', 'r' => "Au Jardin Parmentier, 9 place Parmentier à Montdidier, nous préparons chaque jour un plat du jour différent, à emporter. Le menu de la semaine est affiché sur cette page et mis à jour chaque semaine."],
    ['q' => 'Comment réserver mon plat du jour ?', 'r' => "Le plus simple : en ligne sur cette page, avec le bouton « Réserver », jusqu'à la veille au soir. Vous pouvez aussi nous appeler au 09 50 17 39 62. Vous payez sur place, au moment du retrait."],
    ['q' => 'Peut-on réserver un plat pour le jour même ?', 'r' => "Pour le jour même, appelez-nous directement au 09 50 17 39 62 : nous ferons notre possible selon ce qu'il reste."],
    ['q' => 'Faites-vous traiteur pour un mariage, un anniversaire ou un repas d’entreprise ?', 'r' => "Oui. Notre atelier traiteur prépare des plats cuisinés, des buffets, des plateaux repas et des apéritifs pour vos repas de famille, anniversaires, réunions et événements, de 10 à 50 personnes. Consultez nos cartes traiteur sur cette page et appelez-nous pour composer votre menu."],
    ['q' => 'Combien de temps à l’avance faut-il commander un buffet ou des plateaux repas ?', 'r' => "Le plus tôt est le mieux, surtout en période de fêtes. Appelez-nous au 09 50 17 39 62 dès que vous connaissez la date et le nombre de personnes : nous vous confirmons ce qui est possible."],
    ['q' => 'Que trouve-t-on dans votre épicerie fine à Montdidier ?', 'r' => "Une fromagerie, une charcuterie à la coupe, des fruits et légumes de saison, une épicerie fine (conserves, condiments, douceurs), des vins et champagnes, et les huîtres de Normandie selon la saison."],
    ['q' => 'Proposez-vous des plateaux de fromages et de charcuterie ?', 'r' => "Oui, nous préparons des plateaux de fromages et de charcuterie, à partager ou pour vos apéritifs. Passez commande en boutique ou par téléphone au 09 50 17 39 62."],
    ['q' => 'Comment payer ma commande ?', 'r' => "Le paiement se fait sur place, en boutique, au moment où vous récupérez votre commande. Il n'y a pas de paiement en ligne."],
    ['q' => 'Comment recevoir le menu de la semaine ?', 'r' => "Inscrivez-vous avec votre adresse e-mail dans la rubrique « Le menu de la semaine dans votre boîte mail » : vous le recevez chaque lundi. Vous pouvez vous désinscrire en un clic."],
    ['q' => 'Prêtez-vous du matériel pour les buffets ?', 'r' => "Oui : nous louons des chafing-dish pour garder vos plats au chaud, et nous pouvons prêter des caissons isothermes (contre un chèque de caution)."],
    ['q' => 'Où se trouve Au Jardin Parmentier ?', 'r' => "Au 9, place Parmentier, 80500 Montdidier, dans la Somme, en plein centre-ville. Le plan et l'itinéraire sont en bas de cette page."],
];

// ---- Événements sur plusieurs jours ----
function evt_fin($e) { return $e['date_fin'] ?: $e['date']; }
function evt_jours($e) {
    $out = [];
    if ($e['date'] === '') return $out;
    for ($d = $e['date']; $d <= evt_fin($e) && count($out) < 14; $d = date('Y-m-d', strtotime($d . ' +1 day'))) $out[] = $d;
    return $out;
}
// « jeudi 24 décembre », « jeudi 24 et vendredi 25 décembre », « du jeudi 24 au samedi 26 décembre »
function evt_quand($e) {
    if ($e['date'] === '') return '';
    if (!$e['date_fin']) return date_fr($e['date'], true);
    $n = count(evt_jours($e));
    $a = date('n', strtotime($e['date'])) === date('n', strtotime($e['date_fin'])) ? strtolower(JOURS[(int)date('N', strtotime($e['date'])) - 1]) . ' ' . (int)date('j', strtotime($e['date'])) : date_fr($e['date'], true);
    return $n === 2 ? $a . ' et ' . date_fr($e['date_fin'], true) : 'du ' . $a . ' au ' . date_fr($e['date_fin'], true);
}
