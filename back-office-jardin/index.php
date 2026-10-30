<?php
if (version_compare(PHP_VERSION, '7.3.0', '<')) { exit('PHP 7.3 ou plus récent est nécessaire (version actuelle : ' . PHP_VERSION . ').'); }
// Back-office : volontairement à une adresse non standard (pas de /admin.php) pour échapper aux robots.
// Les liens de la page sont relatifs à la racine du site grâce à <base href="../">.
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__) . '/stats.php';
require dirname(__DIR__) . '/comptes.php';
session_name('jp_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
session_start();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$comptes = comptes_charger();
$moi = !empty($_SESSION['uid']) ? compte_par_id($_SESSION['uid']) : null;
if (!empty($_SESSION['uid']) && !$moi) { $_SESSION = []; session_destroy(); session_start(); $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$logged = (bool)$moi;
$estAdmin = $logged && $moi['role'] === 'admin';
$err = '';

function ouvrir_session($u) {
    session_regenerate_id(true);
    $_SESSION['uid'] = $u['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    $l = comptes_charger();
    foreach ($l as &$x) if ($x['id'] === $u['id']) $x['derniere'] = date('Y-m-d H:i');
    comptes_enregistrer($l);
}
function repondre($code, $data) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function api_garde($admin = false) {
    global $logged, $estAdmin;
    if (!$logged) repondre(401, ['erreur' => 'Votre session a expiré. Rechargez la page pour vous reconnecter (vos modifications non enregistrées seront perdues).']);
    if (!hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) repondre(403, ['erreur' => 'Session expirée : rechargez la page.']);
    if ($admin && !$estAdmin) repondre(403, ['erreur' => 'Réservé aux administrateurs.']);
}
function entree() { $in = json_decode((string)file_get_contents('php://input'), true); return is_array($in) ? $in : []; }

// Copie de sécurité avant chaque enregistrement (les 30 dernières sont gardées dans data/sauvegardes/)
function sauvegarder() {
    $src = DATA . '/site.json';
    if (!is_file($src)) return;
    $dir = DATA . '/sauvegardes';
    if (!is_dir($dir)) @mkdir($dir, 0755);
    @copy($src, $dir . '/site-' . date('Y-m-d-His') . '.json');
    $l = glob($dir . '/site-*.json') ?: [];
    sort($l);
    foreach (array_slice($l, 0, max(0, count($l) - 30)) as $f) @unlink($f);
}

// Photo envoyée depuis le back-office : redimensionnée (1600 px max) et enregistrée en WebP dans img/photos/
function photo_recue($f) {
    if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) return [null, 'La photo n’a pas pu être envoyée (fichier trop lourd ? 8 Mo maximum).'];
    if ($f['size'] > 8 * 1024 * 1024) return [null, 'Photo trop lourde : 8 Mo maximum.'];
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return [null, 'Format non reconnu : envoyez une photo JPG, PNG ou WebP.'];
    $nom = 'envoi-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
    $dir = dirname(__DIR__) . '/img/photos';
    if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
        $src = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
        if ($src) {
            if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $o = (int)(@exif_read_data($f['tmp_name'])['Orientation'] ?? 1);
                if ($o === 3) $src = imagerotate($src, 180, 0); elseif ($o === 6) $src = imagerotate($src, -90, 0); elseif ($o === 8) $src = imagerotate($src, 90, 0);
            }
            $w = imagesx($src); $h = imagesy($src); $r = min(1, 1600 / max($w, $h));
            $dst = imagecreatetruecolor((int)round($w * $r), (int)round($h * $r));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
            if (@imagewebp($dst, "$dir/$nom.webp", 80)) return [$nom, null];
        }
    }
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]];
    return move_uploaded_file($f['tmp_name'], "$dir/$nom.$ext") ? [$nom, null] : [null, 'Impossible d’enregistrer la photo : vérifiez les droits du dossier img/photos/.'];
}

$api = $_GET['api'] ?? '';
if ($api !== '') {
    // Enregistrement d'une partie du site
    if ($api === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        api_garde();
        $in = entree();
        $part = $in['part'] ?? '';
        if (!in_array($part, ['evenements', 'semaine', 'menus', 'mots', 'avis'], true)) repondre(400, ['erreur' => 'Données invalides.']);
        $d = site_load();
        $d[$part] = $in['data'] ?? null;
        $d = site_clean($d);
        sauvegarder();
        if (!json_save(DATA . '/site.json', $d)) repondre(500, ['erreur' => 'Enregistrement impossible : le dossier data/ n’est pas modifiable. Donnez-lui les droits d’écriture (755 ou 775) chez votre hébergeur.']);
        repondre(200, ['ok' => true, 'data' => $d[$part]]);
    }
    if ($api === 'stats') {
        if (!$logged) repondre(401, ['erreur' => 'Votre session a expiré. Rechargez la page.']);
        repondre(200, stats_synthese((int)($_GET['jours'] ?? 30)));
    }
    if ($api === 'photo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        api_garde();
        [$nom, $e] = photo_recue($_FILES['photo'] ?? null);
        if ($e) repondre(400, ['erreur' => $e]);
        repondre(200, ['ok' => true, 'nom' => $nom, 'url' => photo($nom)]);
    }
    // Comptes
    if ($api === 'compte' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        api_garde(true);
        $in = entree();
        $l = comptes_charger();
        $i = null;
        foreach ($l as $k => $u) if ($u['id'] === ($in['id'] ?? '')) $i = $k;
        [$u, $e] = compte_valider($in, $i === null ? null : $l[$i], $l);
        if ($e) repondre(400, ['erreur' => $e]);
        if ($i !== null && $l[$i]['role'] === 'admin' && $u['role'] !== 'admin' && nb_admins($l) < 2) repondre(400, ['erreur' => 'Il faut garder au moins un administrateur.']);
        if ($i === null) $l[] = $u; else $l[$i] = $u;
        if (!comptes_enregistrer($l)) repondre(500, ['erreur' => 'Enregistrement impossible : le dossier data/ n’est pas modifiable.']);
        repondre(200, ['ok' => true, 'comptes' => array_map('compte_public', $l)]);
    }
    if ($api === 'compte-suppr' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        api_garde(true);
        $id = (string)(entree()['id'] ?? '');
        if ($id === $moi['id']) repondre(400, ['erreur' => 'Vous ne pouvez pas supprimer votre propre compte.']);
        $l = array_values(array_filter(comptes_charger(), function ($u) use ($id) { return $u['id'] !== $id; }));
        if (!nb_admins($l)) repondre(400, ['erreur' => 'Il faut garder au moins un administrateur.']);
        comptes_enregistrer($l);
        repondre(200, ['ok' => true, 'comptes' => array_map('compte_public', $l)]);
    }
    if ($api === 'mdp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        api_garde();
        $in = entree();
        if (!password_verify((string)($in['actuel'] ?? ''), $moi['hash'])) repondre(400, ['erreur' => 'Le mot de passe actuel est incorrect.']);
        if ($e = mdp_faible((string)($in['nouveau'] ?? ''))) repondre(400, ['erreur' => $e]);
        $l = comptes_charger();
        foreach ($l as &$u) if ($u['id'] === $moi['id']) $u['hash'] = password_hash((string)$in['nouveau'], PASSWORD_DEFAULT);
        unset($u);
        comptes_enregistrer($l);
        repondre(200, ['ok' => true]);
    }
    repondre(404, ['erreur' => 'Action inconnue.']);
}

// ---- Connexion ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $err = 'Session expirée : rechargez la page et recommencez.';
    } elseif ($action === 'setup' && !$comptes) {
        // Premier lancement : création du compte administrateur
        [$u, $err] = compte_valider(['identifiant' => $_POST['identifiant'] ?? '', 'email' => $_POST['email'] ?? '', 'nom' => $_POST['nom'] ?? '', 'role' => 'admin', 'mdp' => $_POST['pwd'] ?? ''], null, []);
        if (!$err && (string)($_POST['pwd'] ?? '') !== (string)($_POST['pwd2'] ?? '')) $err = 'Les deux mots de passe ne sont pas identiques.';
        if (!$err && !comptes_enregistrer([$u])) $err = 'Impossible d’écrire dans le dossier data/ : vérifiez ses droits d’écriture.';
        if (!$err) { ouvrir_session($u); header('Location: ./'); exit; }
    } elseif ($action === 'login' && $comptes) {
        // Pièges à robots : un champ invisible que seuls les robots remplissent, et un envoi trop rapide (moins de 2 s)
        $robot = ($_POST['site_web'] ?? '') !== '' || time() - (int)($_SESSION['form_t'] ?? 0) < 2;
        if ($robot) { tentatives(true); usleep(700000); $err = 'Identifiant, e-mail ou mot de passe incorrect.'; }
        elseif (tentatives() >= 5) {
            $err = 'Trop de tentatives. Réessayez dans 15 minutes.';
        } else {
            $u = compte_par_login($_POST['login'] ?? '');
            if ($u && password_verify((string)($_POST['pwd'] ?? ''), $u['hash'])) { tentatives(false); ouvrir_session($u); header('Location: ./'); exit; }
            usleep(700000); tentatives(true);
            $err = 'Identifiant, e-mail ou mot de passe incorrect.';
        }
    } elseif ($action === 'logout') {
        $_SESSION = []; session_destroy(); header('Location: ./'); exit;
    }
}

$etat = null;
if ($logged) {
    $photos = [];
    foreach (photos_dispo() as $p) $photos[] = ['nom' => $p, 'url' => photo($p)];
    $etat = ['site' => site_load(), 'photos' => $photos, 'csrf' => $_SESSION['csrf'], 'aujourdhui' => date('Y-m-d'),
             'api' => ESPACE . '/', 'moi' => compte_public($moi), 'comptes' => $estAdmin ? array_map('compte_public', comptes_charger()) : [], 'roles' => ROLES];
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<base href="../">
<title>Gestion du site · Au Jardin Parmentier</title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="admin.css?v=7" rel="stylesheet">
</head>
<body>

<?php if (!$logged): $setup = !$comptes; $_SESSION['form_t'] = time(); ?>
<main class="auth">
  <form method="post" class="auth-carte">
    <img src="favicon.svg" alt="" width="56" height="56">
    <h1><?= $setup ? 'Créer le compte administrateur' : 'Gestion du site' ?></h1>
    <p class="aide"><?= $setup ? 'Première connexion : créez votre compte. Vous pourrez ensuite ajouter d’autres utilisateurs.' : 'Au Jardin Parmentier' ?></p>
<?php if ($err): ?>    <p class="alerte" role="alert"><?= h($err) ?></p>
<?php endif; ?>
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="<?= $setup ? 'setup' : 'login' ?>">
    <div class="piege" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"><label>Site web <input name="site_web" tabindex="-1" autocomplete="off"></label></div>
<?php if ($setup): ?>
    <label class="champ"><span>Identifiant</span><input name="identifiant" required pattern="[A-Za-z0-9._\-]{3,30}" autocomplete="username" value="<?= h($_POST['identifiant'] ?? '') ?>"></label>
    <label class="champ"><span>Adresse e-mail</span><input type="email" name="email" required autocomplete="email" value="<?= h($_POST['email'] ?? '') ?>"></label>
    <label class="champ"><span>Mot de passe</span><input type="password" name="pwd" minlength="10" required autocomplete="new-password"><small>10 caractères minimum, avec au moins une lettre et un chiffre.</small></label>
    <label class="champ"><span>Confirmation</span><input type="password" name="pwd2" minlength="10" required autocomplete="new-password"></label>
<?php else: ?>
    <label class="champ"><span>Identifiant ou adresse e-mail</span><input name="login" required autocomplete="username" autofocus value="<?= h($_POST['login'] ?? '') ?>"></label>
    <label class="champ"><span>Mot de passe</span>
      <span class="mdp-zone"><input type="password" name="pwd" required autocomplete="current-password"><button type="button" class="voir-mdp" aria-label="Afficher le mot de passe" onclick="var i=this.previousElementSibling;i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'Afficher':'Masquer'">Afficher</button></span></label>
<?php endif; ?>
    <button class="btn btn-violet btn-plein"><?= $setup ? 'Créer le compte' : 'Se connecter' ?></button>
<?php if (!$setup): ?>    <p class="aide petit-aide">Mot de passe oublié ? Demandez à un administrateur de le réinitialiser dans l’onglet « Utilisateurs ».</p>
<?php endif; ?>    <a class="lien-site" href="index.php">← Retour au site</a>
    <p class="version">Version <?= h(VERSION) ?></p>
  </form>
</main>

<?php else: ?>
<header class="barre-haut">
  <a class="logo" href="<?= ESPACE ?>/"><img src="favicon.svg" alt="" width="34" height="34"><span>Gestion du site</span></a>
  <nav class="onglets" role="tablist" aria-label="Rubriques">
    <button role="tab" data-onglet="stats" aria-selected="true"><svg class="ico"><use href="#i-stats"/></svg>Tableau de bord</button>
    <button role="tab" data-onglet="evenements" aria-selected="false"><svg class="ico"><use href="#i-fete"/></svg>Événements</button>
    <button role="tab" data-onglet="semaine" aria-selected="false"><svg class="ico"><use href="#i-cal"/></svg>Menu de la semaine</button>
    <button role="tab" data-onglet="menus" aria-selected="false"><svg class="ico"><use href="#i-livre"/></svg>Cartes traiteur</button>
    <button role="tab" data-onglet="mots" aria-selected="false"><svg class="ico"><use href="#i-mots"/></svg>Mots</button>
    <button role="tab" data-onglet="avis" aria-selected="false"><svg class="ico"><use href="#i-etoile"/></svg>Avis</button>
    <button role="tab" data-onglet="comptes" aria-selected="false"><svg class="ico"><use href="#i-user"/></svg><?= $estAdmin ? 'Utilisateurs' : 'Mon compte' ?></button>
  </nav>
  <div class="haut-actions">
    <span class="qui" title="<?= h($moi['email']) ?>"><span class="avatar"><?= h(strtoupper(substr($moi['nom'] ?: $moi['identifiant'], 0, 1))) ?></span><span class="qui-nom"><?= h($moi['nom'] ?: $moi['identifiant']) ?></span></span>
    <a class="btn btn-discret" href="index.php" target="_blank" rel="noopener"><svg class="ico"><use href="#i-oeil"/></svg><span>Voir le site</span></a>
    <form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="logout"><button class="btn btn-discret" title="Se déconnecter"><svg class="ico"><use href="#i-sortie"/></svg><span>Quitter</span></button></form>
  </div>
</header>

<main id="app" class="app" aria-live="polite"></main>

<div class="enreg" id="enreg" hidden>
  <p id="enreg-txt">Modifications non enregistrées</p>
  <button class="btn btn-discret" id="annuler" type="button">Annuler</button>
  <button class="btn btn-violet" id="enregistrer" type="button">Enregistrer</button>
</div>
<div class="toast" id="toast" role="status" hidden></div>

<script type="application/json" id="etat"><?= json_encode($etat, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="visuels.js?v=1"></script>
<script src="admin.js?v=7"></script>
<?php endif; ?>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <defs>
    <symbol id="i-user" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></g></symbol>
    <symbol id="i-upload" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></g></symbol>
    <symbol id="i-stats" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 3v18h18"/><path d="M7 15v3M12 10v8M17 6v12"/></g></symbol>
    <symbol id="i-fete" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 22h8M12 15v7M7 2h10l-1 7a4 4 0 0 1-8 0z"/></g></symbol>
    <symbol id="i-cal" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></g></symbol>
    <symbol id="i-livre" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V2H6.5A2.5 2.5 0 0 0 4 4.5v15z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/></g></symbol>
    <symbol id="i-mots" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h10M4 17h13"/></g></symbol>
    <symbol id="i-etoile" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/></symbol>
    <symbol id="i-oeil" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></g></symbol>
    <symbol id="i-sortie" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></g></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 5v14M5 12h14"/></symbol>
    <symbol id="i-haut" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="m18 15-6-6-6 6"/></symbol>
    <symbol id="i-bas" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></symbol>
    <symbol id="i-suppr" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></g></symbol>
    <symbol id="i-retour" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M19 12H5M12 19l-7-7 7-7"/></symbol>
    <symbol id="i-imprim" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></g></symbol>
    <symbol id="i-suivant" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/></symbol>
    <symbol id="i-coller" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></g></symbol>
    <symbol id="i-copie" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></g></symbol>
    <symbol id="i-crayon" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></g></symbol>
  </defs>
</svg>
</body>
</html>
