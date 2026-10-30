<?php
// Fonctions communes au site (index.php), à l'affiche (affiche.php) et au back-office (back-office-jardin/)
const DATA = __DIR__ . '/data';
const ESPACE = 'back-office-jardin';   // dossier du back-office : https://…/back-office-jardin/
const VERSION = '1.4 · 30 octobre 2026';   // affichée en bas de la page de connexion du back-office
const JOURS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
date_default_timezone_set('Europe/Paris');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function site_load() {
    // site.json est créé par le back-office ; tant qu'il n'existe pas, le contenu de départ (site.exemple.json) est affiché
    $f = is_file(DATA . '/site.json') ? DATA . '/site.json' : DATA . '/site.exemple.json';
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return site_clean(is_array($d) ? $d : []);
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
        'titre' => txt($c['titre'] ?? '', 60) ?: 'Menu', 'photo' => $photo, 'intro' => txt($c['intro'] ?? '', 300),
        'prix' => txt($c['prix'] ?? '', 40), 'cats' => $cats, 'note' => txt($c['note'] ?? '', 300), 'encart' => liste_txt($c['encart'] ?? [], 200),
    ];
}
function semaine_clean($s) {
    $s = is_array($s) ? $s : [];
    $jours = [];
    foreach (liste($s['jours'] ?? []) as $j) {
        $j = ['jour' => txt($j['jour'] ?? '', 30), 'nom' => txt($j['nom'] ?? '', 140), 'desc' => txt($j['desc'] ?? '', 200), 'prix' => txt($j['prix'] ?? '', 20)];
        if ($j['jour'] !== '' || $j['nom'] !== '') $jours[] = $j;
    }
    $sp = is_array($s['special'] ?? null) ? $s['special'] : [];
    return [
        'titre' => txt($s['titre'] ?? '', 60) ?: 'Inspirations de la semaine',
        'du' => date_ok($s['du'] ?? ''), 'au' => date_ok($s['au'] ?? ''), 'jours' => $jours,
        'special' => ['titre' => txt($sp['titre'] ?? '', 60), 'nom' => txt($sp['nom'] ?? '', 100), 'prix' => txt($sp['prix'] ?? '', 20), 'lignes' => liste_txt($sp['lignes'] ?? [], 160)],
        'note' => txt($s['note'] ?? '', 200),
    ];
}
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
function evenement_clean($e) {
    $items = [];
    foreach (liste($e['items'] ?? []) as $p) {
        $p = ['nom' => txt($p['nom'] ?? '', 140), 'prix' => txt($p['prix'] ?? '', 30)];
        if ($p['nom'] !== '') $items[] = $p;
    }
    $r = is_array($e['reservation'] ?? null) ? $e['reservation'] : [];
    return [
        'actif' => !empty($e['actif']),
        'titre' => txt($e['titre'] ?? '', 80) ?: 'Événement',
        'date' => date_ok($e['date'] ?? ''),
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
        return $e['actif'] && !($e['masquer_apres'] && $e['date'] !== '' && $e['date'] < $t);
    }));
    usort($v, function ($a, $b) { return strcmp($a['date'] ?: '9999', $b['date'] ?: '9999'); });
    return $v;
}
function reservation_ouverte($e) { return !$e['reservation']['active'] || $e['reservation']['date'] === '' || date('Y-m-d') <= $e['reservation']['date']; }

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
        'menus' => array_map('carte_clean', liste($d['menus'] ?? [])),
        'mots' => $mots ?: ['épicerie fine', 'traiteur', 'charcuterie', 'fromagerie', 'fruits et légumes'],
        'avis' => $avis,
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
    if ($nom === '' || $nom === null) return null;
    foreach (['webp', 'jpg', 'jpeg', 'png'] as $ext) {
        $f = 'img/photos/' . $nom . '.' . $ext;
        if (is_file(__DIR__ . '/' . $f)) return $f . '?v=' . filemtime(__DIR__ . '/' . $f);
    }
    return null;
}
function photos_dispo() {
    $l = [];
    foreach (glob(__DIR__ . '/img/photos/*.{webp,jpg,jpeg,png}', GLOB_BRACE) ?: [] as $f) $l[] = pathinfo($f, PATHINFO_FILENAME);
    return array_values(array_unique($l));
}
// Bandeau photo défilant : toutes les images du dossier img/galerie/ (ordre alphabétique)
function photos_galerie() {
    $l = glob(__DIR__ . '/img/galerie/*.{webp,jpg,jpeg,png}', GLOB_BRACE) ?: [];
    sort($l);
    return array_map(function ($f) { return 'img/galerie/' . basename($f); }, $l);
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
