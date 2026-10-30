<?php
if (version_compare(PHP_VERSION, '7.3.0', '<')) { exit('PHP 7.3 ou plus récent est nécessaire (version actuelle : ' . PHP_VERSION . ').'); }
require __DIR__ . '/lib.php';
require __DIR__ . '/stats.php';
session_name('jp_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
session_start();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

$cfgFile = DATA . '/config.json';
$cfg = is_file($cfgFile) ? json_decode((string)file_get_contents($cfgFile), true) : null;
$hasPwd = is_array($cfg) && !empty($cfg['hash']);
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$logged = !empty($_SESSION['ok']);
$err = '';

function ouvrir_session() { session_regenerate_id(true); $_SESSION['ok'] = 1; $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
function repondre($code, $data) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

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

// ---- Enregistrement d'une partie du site (appel du back-office en JSON) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['api'] ?? '') === 'save') {
    if (!$logged) repondre(401, ['erreur' => 'Votre session a expiré. Rechargez la page pour vous reconnecter (vos modifications non enregistrées seront perdues).']);
    if (!hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) repondre(403, ['erreur' => 'Session expirée : rechargez la page.']);
    $in = json_decode((string)file_get_contents('php://input'), true);
    $part = $in['part'] ?? '';
    if (!is_array($in) || !in_array($part, ['evenements', 'semaine', 'menus', 'mots', 'avis'], true)) repondre(400, ['erreur' => 'Données invalides.']);
    $d = site_load();
    $d[$part] = $in['data'] ?? null;
    $d = site_clean($d);
    sauvegarder();
    if (!json_save(DATA . '/site.json', $d)) repondre(500, ['erreur' => 'Enregistrement impossible : le dossier data/ n’est pas modifiable. Donnez-lui les droits d’écriture (755 ou 775) chez votre hébergeur.']);
    repondre(200, ['ok' => true, 'data' => $d[$part]]);
}

// ---- Statistiques pour le tableau de bord ----
if (($_GET['api'] ?? '') === 'stats') {
    if (!$logged) repondre(401, ['erreur' => 'Votre session a expiré. Rechargez la page.']);
    repondre(200, stats_synthese((int)($_GET['jours'] ?? 30)));
}

// ---- Connexion ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $err = 'Session expirée : rechargez la page et recommencez.';
    } elseif ($action === 'setup' && !$hasPwd) {
        $p = (string)($_POST['pwd'] ?? '');
        if (strlen($p) < 10) $err = 'Le mot de passe doit faire au moins 10 caractères.';
        elseif ($p !== (string)($_POST['pwd2'] ?? '')) $err = 'Les deux mots de passe ne sont pas identiques.';
        elseif (!json_save($cfgFile, ['hash' => password_hash($p, PASSWORD_DEFAULT)])) $err = 'Impossible d’écrire dans le dossier data/ : vérifiez ses droits d’écriture.';
        else { ouvrir_session(); header('Location: admin.php'); exit; }
    } elseif ($action === 'login' && $hasPwd) {
        $tf = DATA . '/tentatives.json';
        $t = is_file($tf) ? json_decode((string)file_get_contents($tf), true) : [];
        $t = ['n' => (int)($t['n'] ?? 0), 't' => (int)($t['t'] ?? 0)];
        if (time() - $t['t'] > 900) $t['n'] = 0;
        if ($t['n'] >= 5) {
            $err = 'Trop de tentatives. Réessayez dans 15 minutes.';
        } elseif (password_verify((string)($_POST['pwd'] ?? ''), $cfg['hash'])) {
            @unlink($tf); ouvrir_session(); header('Location: admin.php'); exit;
        } else {
            usleep(700000); $t['n']++; $t['t'] = time(); json_save($tf, $t);
            $err = 'Mot de passe incorrect.';
        }
    } elseif ($action === 'logout') {
        $_SESSION = []; session_destroy(); header('Location: admin.php'); exit;
    }
}

$etat = null;
if ($logged) {
    $photos = [];
    foreach (photos_dispo() as $p) $photos[] = ['nom' => $p, 'url' => photo($p)];
    $etat = ['site' => site_load(), 'photos' => $photos, 'csrf' => $_SESSION['csrf'], 'aujourdhui' => date('Y-m-d')];
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Gestion du site · Au Jardin Parmentier</title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="admin.css?v=4" rel="stylesheet">
</head>
<body>

<?php if (!$logged): ?>
<main class="auth">
  <form method="post" class="auth-carte">
    <img src="favicon.svg" alt="" width="56" height="56">
    <h1><?= $hasPwd ? 'Gestion du site' : 'Créer votre mot de passe' ?></h1>
    <p class="aide"><?= $hasPwd ? 'Au Jardin Parmentier' : 'Première connexion : choisissez un mot de passe d’au moins 10 caractères.' ?></p>
<?php if ($err): ?>    <p class="alerte" role="alert"><?= h($err) ?></p>
<?php endif; ?>
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="<?= $hasPwd ? 'login' : 'setup' ?>">
    <label class="champ"><span>Mot de passe</span>
      <input type="password" name="pwd" required <?= $hasPwd ? 'autocomplete="current-password" autofocus' : 'minlength="10" autocomplete="new-password"' ?>></label>
<?php if (!$hasPwd): ?>
    <label class="champ"><span>Confirmation</span><input type="password" name="pwd2" minlength="10" required autocomplete="new-password"></label>
<?php endif; ?>
    <button class="btn btn-violet btn-plein"><?= $hasPwd ? 'Se connecter' : 'Créer le mot de passe' ?></button>
    <a class="lien-site" href="index.php">← Retour au site</a>
  </form>
</main>

<?php else: ?>
<header class="barre-haut">
  <a class="logo" href="admin.php"><img src="favicon.svg" alt="" width="34" height="34"><span>Gestion du site</span></a>
  <nav class="onglets" role="tablist" aria-label="Rubriques">
    <button role="tab" data-onglet="stats" aria-selected="true"><svg class="ico"><use href="#i-stats"/></svg>Tableau de bord</button>
    <button role="tab" data-onglet="evenements" aria-selected="false"><svg class="ico"><use href="#i-fete"/></svg>Événements</button>
    <button role="tab" data-onglet="semaine" aria-selected="false"><svg class="ico"><use href="#i-cal"/></svg>Menu de la semaine</button>
    <button role="tab" data-onglet="menus" aria-selected="false"><svg class="ico"><use href="#i-livre"/></svg>Cartes traiteur</button>
    <button role="tab" data-onglet="mots" aria-selected="false"><svg class="ico"><use href="#i-mots"/></svg>Mots</button>
    <button role="tab" data-onglet="avis" aria-selected="false"><svg class="ico"><use href="#i-etoile"/></svg>Avis</button>
  </nav>
  <div class="haut-actions">
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
<script src="admin.js?v=4"></script>
<?php endif; ?>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <defs>
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
