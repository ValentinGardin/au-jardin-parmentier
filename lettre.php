<?php
// Lettre « menu de la semaine » : inscription depuis la page d'accueil (POST) et désinscription en un clic (/lettre?d=…)
require __DIR__ . '/lib.php';
require __DIR__ . '/clients.php';
header('X-Robots-Tag: noindex');
header('X-Content-Type-Options: nosniff');

$json = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
function fin_json($code, $d) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

// Pas plus de 5 inscriptions par heure depuis une même connexion
function trop_frequent() {
    $f = DATA . '/lettre-essais.json';
    $t = array_filter(lire_json($f), function ($x) { return time() - (int)$x < 3600; });
    $k = substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . 'lettre'), 0, 16);
    $n = count(array_filter(array_keys($t), function ($x) use ($k) { return strpos($x, $k) === 0; }));
    if ($n >= 5) return true;
    $t[$k . '-' . bin2hex(random_bytes(3))] = time();
    json_save($f, $t);
    return false;
}

$jeton = preg_replace('/[^a-f0-9]/', '', (string)($_GET['d'] ?? ''));

// ---- Inscription ----
if ($jeton === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = email_ok($_POST['email'] ?? '');
    $prenom = txt($_POST['prenom'] ?? '', 40);
    $robot = ($_POST['site_web'] ?? '') !== '' || time() - (int)($_POST['t'] ?? 0) < 2;
    $err = '';
    if ($email === '') $err = 'Cette adresse e-mail ne semble pas valide.';
    elseif (empty($_POST['accord'])) $err = 'Cochez la case pour accepter de recevoir le menu.';
    elseif (!$robot && trop_frequent()) $err = 'Trop d’essais : réessayez dans une heure.';
    if (!$err && !$robot) {
        $nouveau = verrou(function () use ($email, $prenom) {
            $l = clients_charger();
            $i = client_index($l, '', $email);
            if ($i === null) { $l[] = client_clean(['prenom' => $prenom, 'email' => $email]); $i = count($l) - 1; }
            elseif ($l[$i]['prenom'] === '' && $prenom !== '') $l[$i]['prenom'] = $prenom;
            $deja = $l[$i]['lettre']['statut'] === 'oui';
            client_lettre($l[$i], true, 'site');
            clients_enregistrer($l);
            return $deja ? null : $l[$i];
        });
        // Message de bienvenue (avec le lien de désinscription)
        if ($nouveau) {
            $lien = lien_desabo($nouveau);
            $html = '<div style="font:15px/1.6 Arial,sans-serif;color:#1d171b;max-width:520px">' . ($prenom !== '' ? '<p>Bonjour ' . h($prenom) . ',</p>' : '')
                . '<p>C’est noté : vous recevrez <strong>le menu de la semaine</strong> d’Au Jardin Parmentier chaque lundi.</p><p>À très vite à la boutique,<br>L’équipe du Jardin Parmentier<br>09 50 17 39 62</p>'
                . '<p style="font-size:12px;color:#8b8288">Ce n’était pas vous ? <a href="' . h($lien) . '" style="color:#8b8288">Se désinscrire</a></p></div>';
            mail_envoyer($email, 'Bienvenue · le menu de la semaine, chaque lundi', $html,
                ($prenom !== '' ? "Bonjour $prenom,\n\n" : '') . "C'est noté : vous recevrez le menu de la semaine d'Au Jardin Parmentier chaque lundi.\n\nSe désinscrire : $lien\n", $lien);
        }
    }
    if ($json) fin_json($err ? 400 : 200, $err ? ['erreur' => $err] : ['ok' => true]);
    header('Location: ./?lettre=' . ($err ? 'erreur' : 'ok') . '#lettre', true, 303);
    exit;
}

// ---- Désinscription ----
$etat = 'inconnu';
$client = null;
if ($jeton !== '') {
    foreach (clients_charger() as $c) if (hash_equals($c['jeton'], $jeton)) $client = $c;
    if ($client) $etat = $client['lettre']['statut'] === 'oui' ? 'inscrit' : 'desinscrit';
    // POST : bouton « Confirmer », ou désinscription en un clic depuis la messagerie (Gmail, Outlook…)
    if ($client && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verrou(function () use ($jeton) {
            $l = clients_charger();
            foreach ($l as &$c) if (hash_equals($c['jeton'], $jeton)) client_lettre($c, false, 'désinscription');
            unset($c);
            clients_enregistrer($l);
        });
        $etat = 'fait';
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Menu de la semaine par e-mail · Au Jardin Parmentier</title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link href="fonts/fonts.css" rel="stylesheet">
<style>
  body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#faf8f5;font:400 16px/1.6 Inter,system-ui,sans-serif;color:#1d171b}
  .c{max-width:440px;width:100%;background:#fff;border-radius:20px;padding:36px 30px;text-align:center;box-shadow:0 12px 32px -12px rgba(29,23,27,.14)}
  img{width:52px;height:auto;margin:0 auto 14px;display:block}
  h1{font:700 1.7rem/1.1 "Baloo 2",system-ui,sans-serif;margin:0 0 .5rem}
  p{color:#6b6369;margin:0 0 1.2rem}
  button,a.b{display:inline-block;border:0;border-radius:12px;padding:.85rem 1.4rem;font:600 1rem Inter,sans-serif;background:#700954;color:#fff;text-decoration:none;cursor:pointer}
  a{color:#700954}
</style>
</head>
<body>
<main class="c">
  <img src="img/logo/symbole-violet.svg" alt="">
<?php if ($etat === 'inscrit'): ?>
  <h1>Se désinscrire ?</h1>
  <p>Vous ne recevrez plus le menu de la semaine à l’adresse <strong><?= h($client['email']) ?></strong>.</p>
  <form method="post"><button>Confirmer la désinscription</button></form>
<?php elseif ($etat === 'fait' || $etat === 'desinscrit'): ?>
  <h1>C’est fait</h1>
  <p>Vous ne recevrez plus le menu de la semaine par e-mail. Vous pouvez vous réinscrire à tout moment depuis notre site.</p>
  <a class="b" href="./">Retour au site</a>
<?php else: ?>
  <h1>Lien expiré</h1>
  <p>Ce lien de désinscription n’est plus valable. Écrivez-nous à <a href="mailto:<?= EXPEDITEUR ?>"><?= EXPEDITEUR ?></a> et nous vous retirerons de la liste.</p>
  <a class="b" href="./">Retour au site</a>
<?php endif; ?>
</main>
</body>
</html>
