<?php
if (version_compare(PHP_VERSION, '7.3.0', '<')) { exit('PHP 7.3 ou plus récent est nécessaire (version actuelle : ' . PHP_VERSION . ').'); }
// Back-office : volontairement à une adresse non standard (pas de /admin.php) pour échapper aux robots.
// Les liens de la page sont relatifs à la racine du site grâce à <base href="../">.
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__) . '/stats.php';
require dirname(__DIR__) . '/comptes.php';
require dirname(__DIR__) . '/google.php';
require dirname(__DIR__) . '/clients.php';
require dirname(__DIR__) . '/sms.php';
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
$info = '';
// Compte correspondant à un lien « mot de passe oublié » encore valable
function compte_reinit($jeton) {
    $jeton = (string)$jeton;
    if (!preg_match('/^[a-f0-9]{48}$/', $jeton)) return null;
    foreach (comptes_charger() as $u) if (!empty($u['reinit']) && hash_equals($u['reinit'], hash('sha256', $jeton)) && ($u['reinit_fin'] ?? 0) > time()) return $u;
    return null;
}
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
// Toutes les actions qui modifient quelque chose passent par ici : le compte « visiteur » est refusé
function api_garde($admin = false) {
    global $logged, $estAdmin, $moi;
    if (!$logged) repondre(401, ['erreur' => 'Votre session a expiré. Rechargez la page pour vous reconnecter (vos modifications non enregistrées seront perdues).']);
    if (!hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) repondre(403, ['erreur' => 'Session expirée : rechargez la page.']);
    if ($moi['role'] === 'visiteur') repondre(403, ['erreur' => 'Mode visite : les modifications ne sont pas enregistrées.']);
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
            if (@imagewebp($dst, "$dir/$nom.webp", 80)) { variantes("img/photos/$nom.webp"); return [$nom, null]; }
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
        if (!in_array($part, ['evenements', 'semaine', 'semaine_suivante', 'menus', 'mots', 'avis', 'faq'], true)) repondre(400, ['erreur' => 'Données invalides.']);
        $d = site_load();
        $ancien = $d[$part] ?? null;
        $d[$part] = $in['data'] ?? null;
        $d = site_clean($d);
        sauvegarder();
        if (!json_save(DATA . '/site.json', $d)) repondre(500, ['erreur' => 'Enregistrement impossible : le dossier data/ n’est pas modifiable. Donnez-lui les droits d’écriture (755 ou 775) chez votre hébergeur.']);
        // plats passés « épuisés » : gardés en mémoire pour les statistiques
        if (in_array($part, ['semaine', 'semaine_suivante'], true)) {
            foreach ($d[$part]['jours'] as $i => $j)
                if ($j['epuise'] && empty($ancien['jours'][$i]['epuise'])) epuise_noter(date_du_jour($d[$part], $j['jour']), $j['nom']);
            if ($d[$part]['special']['epuise'] && empty($ancien['special']['epuise'])) epuise_noter(date('Y-m-d'), $d[$part]['special']['nom']);
        }
        if ($part === 'evenements') foreach ($d['evenements'] as $i => $e) foreach ($e['items'] as $k => $it)
            if ($it['epuise'] && empty($ancien[$i]['items'][$k]['epuise'])) epuise_noter($e['date'] ?: date('Y-m-d'), $it['nom']);
        repondre(200, ['ok' => true, 'data' => $d[$part]]);
    }
    if ($api === 'stats') {
        if (!$logged) repondre(401, ['erreur' => 'Votre session a expiré. Rechargez la page.']);
        repondre(200, stats_synthese((int)($_GET['jours'] ?? 30)) + ['ventes' => ventes_synthese((int)($_GET['jours'] ?? 30))]);
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
    // ---- Avis Google ----
    if (strpos($api, 'google') === 0) {
        if (!$logged) repondre(401, ['erreur' => 'Votre session a expiré. Rechargez la page.']);
        $etatGoogle = function () {
            $c = google_config(); $d = google_cache();
            return ['ok' => true, 'cle' => $c['cle'] !== '' ? '••••••••' . substr($c['cle'], -4) : '', 'place_id' => $c['place_id'], 'nom' => $c['nom'], 'adresse' => $c['adresse'],
                    'afficher_note' => $c['afficher_note'], 'afficher_avis' => $c['afficher_avis'], 'note_min' => $c['note_min'],
                    'note' => $d['note'] ?? null, 'nombre' => $d['nombre'] ?? 0, 'nb_avis' => count($d['avis'] ?? []), 'maj' => !empty($d['maj']) ? date('d/m/Y à H\hi', $d['maj']) : '', 'erreur' => $d['erreur'] ?? ''];
        };
        $enregistrer = function ($modifs) {
            $c = is_file(GOOGLE_CONFIG) ? (json_decode((string)file_get_contents(GOOGLE_CONFIG), true) ?: []) : [];
            if (!json_save(GOOGLE_CONFIG, array_merge($c, $modifs))) repondre(500, ['erreur' => 'Impossible d’enregistrer : le dossier data/ n’est pas modifiable.']);
        };
        if ($api === 'google') repondre(200, $etatGoogle());
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') repondre(405, ['erreur' => 'Méthode non autorisée.']);
        $in = entree();
        if ($api === 'google-actualiser') {
            api_garde();
            [, $e] = google_actualiser();
            if ($e) repondre(400, ['erreur' => $e]);
            repondre(200, $etatGoogle());
        }
        api_garde(true);   // le reste est réservé aux administrateurs
        if ($api === 'google-cle') {
            $cle = trim((string)($in['cle'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_\-]{20,60}$/', $cle)) repondre(400, ['erreur' => 'Cette clé ne ressemble pas à une clé Google (elle commence en général par « AIza » et fait 39 caractères).']);
            $enregistrer(['cle' => $cle]);
            repondre(200, $etatGoogle());
        }
        if ($api === 'google-recherche') {
            [$r, $e] = google_recherche(txt($in['q'] ?? '', 120));
            if ($e) repondre(400, ['erreur' => $e]);
            repondre(200, ['ok' => true, 'resultats' => $r]);
        }
        if ($api === 'google-choisir') {
            $id = (string)($in['id'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_\-]{10,300}$/', $id)) repondre(400, ['erreur' => 'Commerce invalide.']);
            $enregistrer(['place_id' => $id, 'nom' => txt($in['nom'] ?? '', 120), 'adresse' => txt($in['adresse'] ?? '', 200)]);
            @unlink(GOOGLE_CACHE);
            [, $e] = google_actualiser();
            if ($e) repondre(400, ['erreur' => $e]);
            repondre(200, $etatGoogle());
        }
        if ($api === 'google-options') {
            $enregistrer(['afficher_note' => !empty($in['afficher_note']), 'afficher_avis' => !empty($in['afficher_avis']), 'note_min' => max(1, min(5, (int)($in['note_min'] ?? 4)))]);
            repondre(200, $etatGoogle());
        }
        if ($api === 'google-oublier') {
            @unlink(GOOGLE_CONFIG); @unlink(GOOGLE_CACHE);
            repondre(200, $etatGoogle());
        }
    }
    // ---- Réservations et clients ----
    if (in_array($api, ['resas', 'resa', 'resa-etat', 'resa-suppr', 'clients', 'client', 'client-suppr', 'clients-csv', 'clients-import', 'lettre-auto', 'lettre-test', 'lettre-envoyer'], true)) {
        if (!$logged) repondre(401, ['erreur' => 'Votre session a expiré. Rechargez la page.']);
        $masquer = $moi['role'] === 'visiteur';
        $qui = $moi['nom'] ?: $moi['identifiant'];
        $resaPublique = function ($r, $clients) use ($masquer) {
            $i = client_par_id($clients, $r['client']);
            $r['c'] = $i === null ? ['id' => '', 'prenom' => 'Client supprimé', 'nom' => '', 'tel' => '', 'email' => '', 'lettre' => ['statut' => '']] : client_public($clients[$i], $masquer);
            return $r;
        };
        $etatLettre = function ($clients) {
            $c = lettre_config(); $s = lettre_semaine(site_load());
            return ['auto' => $c['auto'], 'envois' => array_reverse($c['envois']), 'inscrits' => count(lettre_inscrits($clients)),
                    'semaine' => $s ? ['du' => $s['du'], 'au' => $s['au'], 'complete' => semaine_complete($s), 'envoye' => lettre_deja($s['du'])] : null];
        };
        $listeClients = function () use ($masquer, $etatLettre) {
            $clients = clients_charger(); $stats = [];
            foreach (resas_charger() as $r) {
                $k = $r['client']; $stats[$k]['n'] = ($stats[$k]['n'] ?? 0) + 1;
                if ($r['date'] > ($stats[$k]['der'] ?? '')) $stats[$k]['der'] = $r['date'];
            }
            $out = [];
            foreach ($clients as $c) { $p = client_public($c, $masquer); $p['nb'] = $stats[$c['id']]['n'] ?? 0; $p['der'] = $stats[$c['id']]['der'] ?? ''; $out[] = $p; }
            usort($out, function ($a, $b) { return strcasecmp(client_nom($a), client_nom($b)); });
            return ['ok' => true, 'clients' => $out, 'lettre' => $etatLettre($clients)];
        };
        // Lecture : réservations d'une période (une semaine affichée dans l'onglet)
        if ($api === 'resas') {
            $du = date_ok($_GET['du'] ?? '') ?: date('Y-m-d'); $au = date_ok($_GET['au'] ?? '') ?: $du;
            $cl = (string)($_GET['client'] ?? '');   // toutes les commandes d'un client (fiche client)
            $num = (int)($_GET['num'] ?? 0);         // recherche par numéro de commande
            resas_numeroter();
            $clients = clients_charger(); $out = [];
            foreach (resas_charger() as $r) {
                if ($num) $garder = (int)$r['num'] === $num;
                elseif ($cl !== '') $garder = $r['client'] === $cl;
                else {
                    // les réservations de la semaine, et celles d'autres jours qui contiennent un plat de cette semaine (à mettre de côté)
                    $garder = $r['date'] >= $du && $r['date'] <= $au;
                    foreach ($r['lignes'] as $li) if (($li['jour'] ?? '') !== '' && $li['jour'] >= $du && $li['jour'] <= $au) $garder = true;
                }
                if ($garder) $out[] = $resaPublique($r, $clients);
            }
            usort($out, function ($a, $b) { return strcmp($a['date'] . $a['cree'], $b['date'] . $b['cree']); });
            $sc = sms_config();
            if ($cl === '') sms_auto();   // l'onglet Réservations se met à jour chaque minute : l'envoi de 17 h 30 part à l'heure
            repondre(200, ['ok' => true, 'du' => $du, 'au' => $au, 'resas' => $out, 'sms' => ['pret' => sms_pret($sc), 'auto' => $sc['auto'], 'heure' => $sc['heure']]]);
        }
        if ($api === 'clients') repondre(200, $listeClients());
        if ($api === 'clients-csv') {
            if ($masquer) repondre(403, ['erreur' => 'Mode visite : export désactivé.']);
            $l = $listeClients()['clients'];
            if (($_GET['f'] ?? '') === 'lettre') $l = array_filter($l, function ($c) { return $c['lettre']['statut'] === 'oui' && $c['email'] !== ''; });
            if (($_GET['f'] ?? '') === 'sms') $l = array_filter($l, function ($c) { return $c['sms']['statut'] === 'oui' && $c['tel'] !== ''; });
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="clients-' . date('Y-m-d') . '.csv"');
            $o = fopen('php://output', 'w');
            fwrite($o, "\xEF\xBB\xBF");   // pour qu'Excel lise bien les accents
            fputcsv($o, ['Prénom', 'Nom', 'Téléphone', 'E-mail', 'Menu par e-mail', 'Depuis le', 'SMS', 'Depuis le', 'Commandes', 'Dernière commande', 'Note'], ';');
            foreach ($l as $c) fputcsv($o, [$c['prenom'], $c['nom'], $c['tel'], $c['email'], $c['lettre']['statut'], $c['lettre']['date'], $c['sms']['statut'], $c['sms']['date'], $c['nb'], $c['der'], $c['note']], ';');
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') repondre(405, ['erreur' => 'Méthode non autorisée.']);
        api_garde();
        $in = entree();
        // Création ou modification d'une réservation (le client est créé ou retrouvé par son téléphone / e-mail)
        if ($api === 'resa') {
            $res = verrou(function () use ($in, $qui) {
                $ci = is_array($in['c'] ?? null) ? $in['c'] : [];
                if (txt($ci['prenom'] ?? '', 40) === '' && txt($ci['nom'] ?? '', 60) === '') return [null, 'Indiquez au moins le nom ou le prénom du client.'];
                if (($ci['email'] ?? '') !== '' && email_ok($ci['email']) === '') return [null, 'L’adresse e-mail du client n’est pas valide.'];
                $clients = clients_charger();
                $i = ($ci['id'] ?? '') !== '' ? client_par_id($clients, $ci['id']) : null;
                if ($i === null) $i = client_index($clients, $ci['tel'] ?? '', $ci['email'] ?? '');
                if ($i === null) { $clients[] = client_clean($ci); $i = count($clients) - 1; }
                else {
                    // client existant : les champs laissés vides gardent leur valeur
                    $m = $clients[$i];
                    foreach (['prenom', 'nom', 'tel', 'email'] as $f) if (trim((string)($ci[$f] ?? '')) !== '') $m[$f] = $ci[$f];
                    $clients[$i] = client_clean($m, $clients[$i]);
                }
                if (in_array($ci['lettre'] ?? '', ['oui', 'non'], true) && $clients[$i]['email'] !== '') client_lettre($clients[$i], $ci['lettre'] === 'oui', 'back-office · ' . $qui);
                if (in_array($ci['sms'] ?? '', ['oui', 'non'], true) && $clients[$i]['tel'] !== '') client_accord($clients[$i], 'sms', $ci['sms'] === 'oui', 'back-office · ' . $qui);
                $l = resas_charger(); $k = null;
                foreach ($l as $j => $r) if ($r['id'] === ($in['id'] ?? '')) $k = $j;
                $r = resa_clean(['client' => $clients[$i]['id']] + $in, $k === null ? null : $l[$k], $qui);
                if (!$r['lignes']) return [null, 'Ajoutez au moins un plat à la commande.'];
                if (!$r['num']) $r['num'] = resa_num_suivant($l);
                if ($k === null) $l[] = $r; else $l[$k] = $r;
                if (!clients_enregistrer($clients) || !resas_enregistrer($l)) return [null, 'Enregistrement impossible : le dossier data/ n’est pas modifiable.'];
                return [[$r, $clients], null];
            });
            if ($res[1]) repondre(400, ['erreur' => $res[1]]);
            repondre(200, ['ok' => true, 'resa' => $resaPublique($res[0][0], $res[0][1])]);
        }
        if ($api === 'resa-etat' || $api === 'resa-suppr') {
            $res = verrou(function () use ($api, $in, $qui) {
                $l = resas_charger(); $k = null;
                foreach ($l as $j => $r) if ($r['id'] === ($in['id'] ?? '')) $k = $j;
                if ($k === null) return [null, 'Cette réservation n’existe plus (supprimée par quelqu’un d’autre ?). Rechargez la page.'];
                if ($api === 'resa-suppr') { array_splice($l, $k, 1); resas_enregistrer($l); return [true, null]; }
                if (!in_array($in['champ'] ?? '', ['paye', 'recupere'], true)) return [null, 'Données invalides.'];
                resa_etat($l[$k], $in['champ'], !empty($in['valeur']), $qui);
                resas_enregistrer($l);
                return [$l[$k], null];
            });
            if ($res[1]) repondre(400, ['erreur' => $res[1]]);
            repondre(200, $api === 'resa-suppr' ? ['ok' => true] : ['ok' => true, 'resa' => $resaPublique($res[0], clients_charger())]);
        }
        if ($api === 'client') {
            $e = verrou(function () use ($in, $qui) {
                if (txt($in['prenom'] ?? '', 40) === '' && txt($in['nom'] ?? '', 60) === '') return 'Indiquez au moins le nom ou le prénom.';
                if (($in['email'] ?? '') !== '' && email_ok($in['email']) === '') return 'L’adresse e-mail n’est pas valide.';
                $l = clients_charger();
                $i = client_par_id($l, (string)($in['id'] ?? ''));
                $d = client_index($l, $in['tel'] ?? '', $in['email'] ?? '');
                if ($d !== null && $d !== $i) return 'Ce téléphone ou cet e-mail appartient déjà à ' . (client_nom($l[$d]) ?: 'un autre client') . '.';
                $c = client_clean($in, $i === null ? null : $l[$i]);
                if (in_array($in['lettre'] ?? '', ['oui', 'non'], true)) {
                    if ($in['lettre'] === 'oui' && $c['email'] === '') return 'Ajoutez une adresse e-mail pour l’inscrire au menu de la semaine.';
                    client_lettre($c, $in['lettre'] === 'oui', 'back-office · ' . $qui);
                }
                if (in_array($in['sms'] ?? '', ['oui', 'non'], true)) {
                    if ($in['sms'] === 'oui' && $c['tel'] === '') return 'Ajoutez un numéro de téléphone pour lui envoyer des SMS.';
                    client_accord($c, 'sms', $in['sms'] === 'oui', 'back-office · ' . $qui);
                }
                if ($i === null) $l[] = $c; else $l[$i] = $c;
                return clients_enregistrer($l) ? null : 'Enregistrement impossible : le dossier data/ n’est pas modifiable.';
            });
            if ($e) repondre(400, ['erreur' => $e]);
            repondre(200, $listeClients());
        }
        // Import d'une liste de contacts (fichier CSV lu dans le navigateur) : ajoutés aux clients,
        // inscrits au menu de la semaine si leur accord est déjà acquis (sauf s'ils l'avaient refusé)
        if ($api === 'clients-import') {
            $r = verrou(function () use ($in, $qui) { return clients_importer(liste($in['contacts'] ?? []), !empty($in['accord']), 'import · ' . $qui); });
            if (!$r) repondre(500, ['erreur' => 'Enregistrement impossible : le dossier data/ n’est pas modifiable.']);
            repondre(200, ['resultat' => $r] + $listeClients());
        }
        if ($api === 'client-suppr') {
            // Droit à l'effacement : le client disparaît, ses anciennes réservations restent (sans nom) pour l'historique
            verrou(function () use ($in) {
                clients_enregistrer(array_filter(clients_charger(), function ($c) use ($in) { return $c['id'] !== ($in['id'] ?? ''); }));
            });
            repondre(200, $listeClients());
        }
        if ($api === 'lettre-auto') {
            $c = lire_json(LETTRE); $c['auto'] = !empty($in['auto']);
            if (!json_save(LETTRE, $c)) repondre(500, ['erreur' => 'Enregistrement impossible : le dossier data/ n’est pas modifiable.']);
            repondre(200, $listeClients());
        }
        if ($api === 'lettre-test' || $api === 'lettre-envoyer') {
            $d = site_load();
            $s = lettre_semaine($d);
            if (!$s) {
                // pas de menu qui commence cette semaine : on prend celui affiché sur le site (ou la semaine suivante préparée)
                foreach (['semaine', 'semaine_suivante'] as $k) if (!$s && jours_visibles($d[$k]['jours']) && !$d[$k]['conges']['actif'] && $d[$k]['au'] >= date('Y-m-d')) $s = $d[$k];
            }
            if (!$s) repondre(400, ['erreur' => 'Aucun menu de la semaine à envoyer : remplissez d’abord l’onglet « Menu de la semaine ».']);
            if ($api === 'lettre-test') {
                $ok = mail_envoyer($moi['email'], '[Essai] ' . lettre_sujet($s), lettre_html($s, $moi['nom'], adresse_site() . '/lettre'), lettre_texte($s, $moi['nom'], adresse_site() . '/lettre'));
                if (!$ok) repondre(500, ['erreur' => 'L’e-mail n’a pas pu partir (' . ($GLOBALS['mail_erreur'] ?? '') . '). Connectez votre boîte mail dans Utilisateurs → Envoi des e-mails.']);
                lettre_noter(['du' => $s['du'], 'date' => date('Y-m-d H:i'), 'mode' => 'test', 'nb' => 1, 'ok' => 1, 'a' => $moi['email']]);
                repondre(200, ['ok' => true, 'a' => $moi['email']] + $listeClients());
            }
            [$n, $e] = lettre_envoyer($s, $qui);
            if (!$n && $e) repondre(500, ['erreur' => $e]);
            repondre(200, ['ok' => true, 'n' => $n, 'avert' => $e] + $listeClients());
        }
    }
    // ---- Boîte mail utilisée pour envoyer les e-mails du site (SMTP, OVH par défaut) ----
    if (strpos($api, 'smtp') === 0) {
        if (!$logged) repondre(401, ['erreur' => 'Votre session a expiré. Rechargez la page.']);
        $etatSmtp = function () { $c = smtp_config(); return ['ok' => true, 'pret' => $c['pret'], 'hote' => $c['hote'], 'port' => $c['port'], 'utilisateur' => $c['utilisateur']]; };
        if ($api === 'smtp') repondre(200, $etatSmtp());
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') repondre(405, ['erreur' => 'Méthode non autorisée.']);
        api_garde(true);
        $in = entree();
        if ($api === 'smtp-oublier') { @unlink(SMTP_CONFIG); repondre(200, $etatSmtp()); }
        if ($api === 'smtp-enregistrer') {
            $c = smtp_config();
            $n = ['hote' => preg_replace('/[^a-z0-9.-]/i', '', (string)($in['hote'] ?? '')) ?: 'ssl0.ovh.net', 'port' => (int)($in['port'] ?? 465) ?: 465,
                  'utilisateur' => email_ok($in['utilisateur'] ?? ''), 'mdp' => (string)($in['mdp'] ?? '') !== '' ? (string)$in['mdp'] : $c['mdp']];
            if ($n['utilisateur'] === '') repondre(400, ['erreur' => 'Indiquez l’adresse e-mail complète de la boîte (ex. info@lejardinparmentier.fr).']);
            if ($n['mdp'] === '') repondre(400, ['erreur' => 'Indiquez le mot de passe de cette boîte mail.']);
            $n['pret'] = true;
            // on essaie d'abord : rien n'est enregistré si l'envoi échoue
            $ok = mail_envoyer($moi['email'], 'Essai · les e-mails du site fonctionnent', '<div style="font:15px/1.6 Arial,sans-serif"><p>Bonne nouvelle : le site envoie bien ses e-mails depuis <b>' . h($n['utilisateur']) . '</b>.</p><p>Confirmations de réservation, menu de la semaine et mots de passe oubliés partiront désormais de cette adresse.</p></div>',
                "Bonne nouvelle : le site envoie bien ses e-mails depuis {$n['utilisateur']}.\n", '', $n);
            if (!$ok) repondre(400, ['erreur' => 'L’essai a échoué : ' . ($GLOBALS['mail_erreur'] ?? 'erreur inconnue') . '. Vérifiez l’adresse et le mot de passe de la boîte mail.']);
            unset($n['pret']);
            if (!json_save(SMTP_CONFIG, $n)) repondre(500, ['erreur' => 'Impossible d’enregistrer : le dossier data/ n’est pas modifiable.']);
            repondre(200, ['a' => $moi['email']] + $etatSmtp());
        }
    }
    // ---- Plat épuisé (bouton rapide de l'onglet Réservations) ----
    if ($api === 'epuise' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        api_garde();
        $in = entree(); $date = date_ok($in['date'] ?? '');
        $res = verrou(function () use ($date, $in) {
            $d = site_load(); $nom = JOURS[(int)date('N', strtotime($date)) - 1]; $v = !empty($in['valeur']);
            // produit d'un événement
            if (($in['quoi'] ?? '') === 'evenement') {
                $e = (int)($in['e'] ?? -1); $k = (int)($in['k'] ?? -1);
                if (!isset($d['evenements'][$e]['items'][$k])) return null;
                $d['evenements'][$e]['items'][$k]['epuise'] = $v;
                if ($v) epuise_noter($date, $d['evenements'][$e]['items'][$k]['nom']);
                sauvegarder(); json_save(DATA . '/site.json', site_clean($d));
                return ['evenements', site_load()['evenements']];
            }
            foreach (['semaine', 'semaine_suivante'] as $k) {
                $s = $d[$k];
                if ($s['du'] === '' || $date < $s['du'] || ($s['au'] !== '' && $date > $s['au'])) continue;
                if (($in['quoi'] ?? '') === 'special') {   // offre spéciale de la semaine
                    if ($s['special']['nom'] === '') continue;
                    $d[$k]['special']['epuise'] = $v;
                    if ($v) epuise_noter($date, $s['special']['nom']);
                    sauvegarder(); json_save(DATA . '/site.json', site_clean($d));
                    return [$k, site_load()[$k]];
                }
                foreach ($d[$k]['jours'] as $i => $j) if (norm($j['jour']) === norm($nom) && $j['nom'] !== '') {
                    $d[$k]['jours'][$i]['epuise'] = !empty($in['valeur']);
                    if (!empty($in['valeur'])) epuise_noter($date, $j['nom']);
                    sauvegarder();
                    json_save(DATA . '/site.json', site_clean($d));
                    return [$k, site_load()[$k]];
                }
            }
            return null;
        });
        if (!$res) repondre(400, ['erreur' => 'Rien de prévu ce jour-là dans le menu de la semaine ou les événements.']);
        repondre(200, ['ok' => true, 'part' => $res[0], 'data' => $res[1]]);
    }
    // ---- Notifications récentes (cloche en haut du back-office) ----
    if ($api === 'notifs') {
        if (!$logged) repondre(401, ['erreur' => 'Votre session a expiré. Rechargez la page.']);
        $moiNom = $moi['nom'] ?: $moi['identifiant']; $depuis = date('Y-m-d H:i', strtotime('-14 days'));
        $clients = clients_charger(); $n = [];
        $nomDe = function ($id) use ($clients) { $i = client_par_id($clients, $id); return $i === null ? 'Client supprimé' : (client_nom($clients[$i]) ?: 'Client'); };
        foreach (resas_charger() as $r) {
            if ($r['cree'] < $depuis) continue;
            $txt = ' n° ' . $r['num'] . ' · ' . $nomDe($r['client']) . ', pour ' . date_fr($r['date'], true);
            if (($r['source'] ?? '') === 'site') $n[] = ['t' => $r['cree'], 'type' => 'web', 'txt' => 'Nouvelle réservation en ligne' . $txt, 'date' => $r['date']];
            elseif ($r['par'] !== $moiNom && $r['cree'] >= date('Y-m-d H:i', strtotime('-3 days'))) $n[] = ['t' => $r['cree'], 'type' => 'resa', 'txt' => $r['par'] . ' a pris la réservation' . $txt, 'date' => $r['date']];
        }
        foreach ($clients as $c) foreach ($c['lettre']['historique'] ?? [] as $h) {
            if ($h['date'] < $depuis || !in_array($h['source'], ['site', 'désinscription'], true)) continue;
            $n[] = ['t' => $h['date'], 'type' => $h['statut'] === 'oui' ? 'lettre' : 'desabo', 'txt' => (client_nom($c) ?: $c['email']) . ($h['statut'] === 'oui' ? ' s’est inscrit(e) au menu par e-mail' : ' s’est désinscrit(e) du menu par e-mail'), 'onglet' => 'clients'];
        }
        foreach (lettre_config()['envois'] as $e) if (($e['mode'] ?? '') !== 'test' && ($e['date'] ?? '') >= $depuis)
            $n[] = ['t' => $e['date'], 'type' => 'envoi', 'txt' => 'Menu de la semaine envoyé par e-mail à ' . (int)$e['ok'] . ' inscrit(s)' . (($e['mode'] ?? '') === 'auto' ? ' (automatique)' : ''), 'onglet' => 'clients'];
        foreach (sms_config()['envois'] as $e) if (strpos($e['mode'], 'essai') !== 0 && $e['date'] >= $depuis && ($e['ok'] || $e['erreur']))
            $n[] = ['t' => $e['date'], 'type' => $e['erreur'] && !$e['ok'] ? 'erreur' : 'sms', 'txt' => $e['ok'] ? (int)$e['ok'] . ' SMS de rappel envoyé(s)' . ($e['mode'] === 'automatique' ? ' (automatique)' : ' par ' . $e['mode']) : 'SMS non envoyés : ' . $e['erreur'], 'onglet' => 'clients'];
        usort($n, function ($a, $b) { return strcmp($b['t'], $a['t']); });
        repondre(200, ['ok' => true, 'notifs' => array_slice($n, 0, 40)]);
    }
    // ---- Réservation en ligne : ouverte ou fermée, heure limite la veille ----
    if ($api === 'commande-options' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        api_garde();
        $in = entree(); $c = lire_json(COMMANDE);
        if (array_key_exists('actif', $in)) $c['actif'] = !empty($in['actif']);
        if (isset($in['limite']) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$in['limite'])) $c['limite'] = $in['limite'];
        if (!json_save(COMMANDE, $c)) repondre(500, ['erreur' => 'Impossible d’enregistrer : le dossier data/ n’est pas modifiable.']);
        repondre(200, ['ok' => true, 'commande' => commande_config(), 'jours' => count(commande_jours(site_load()))]);
    }
    // ---- SMS (service SMS d'OVH) ----
    if (strpos($api, 'sms') === 0) {
        if (!$logged) repondre(401, ['erreur' => 'Votre session a expiré. Rechargez la page.']);
        $etatSms = function () {
            $c = sms_config(); $pret = sms_pret($c); $credits = null; $err = '';
            if ($pret) { [$j, $e] = ovh_appel('GET', '/sms/' . rawurlencode($c['service']), null, $c); if ($e) $err = $e; else $credits = $j['creditsLeft'] ?? null; }
            return ['ok' => true, 'pret' => $pret, 'cles' => $c['app_key'] !== '', 'service' => $c['service'], 'expediteur' => $c['expediteur'], 'credits' => $credits, 'erreur' => $err,
                    'auto' => $c['auto'], 'heure' => $c['heure'], 'cible' => $c['cible'], 'message' => $c['message'], 'defaut' => SMS_MESSAGE, 'tel_essai' => $c['tel_essai'],
                    'envois' => array_reverse($c['envois']), 'fait' => $c['fait'] === date('Y-m-d'),
                    'parti' => (bool)array_filter($c['envois'], function ($e) { return $e['mode'] === 'automatique' && substr($e['date'], 0, 10) === date('Y-m-d'); }), 'a_rappeler' => count(sms_a_rappeler($c)),
                    'cron' => $pret ? adresse_site() . '/rappels?cle=' . sms_cle_cron() : ''];
        };
        if ($api === 'sms') repondre(200, $etatSms());
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') repondre(405, ['erreur' => 'Méthode non autorisée.']);
        $in = entree();
        $qui = $moi['nom'] ?: $moi['identifiant'];
        if ($api === 'sms-test') {
            api_garde();
            $tel = (string)($in['tel'] ?? '');
            if (tel_international($tel) === '') repondre(400, ['erreur' => 'Indiquez un numéro de portable (06 ou 07) pour l’essai.']);
            sms_modifier(['tel_essai' => tel_fr($tel)]);
            $c = sms_config();
            $exemple = ['lignes' => [['qte' => 2, 'nom' => 'Blanquette de veau']]];
            [, $e] = sms_envoyer($tel, sms_texte($c['message'], $exemple, ['prenom' => $moi['nom'] ?: 'Valentin', 'nom' => '']), $c);
            if ($e) repondre(400, ['erreur' => $e]);
            sms_noter(['date' => date('Y-m-d H:i'), 'mode' => 'essai · ' . $qui, 'ok' => 1, 'erreur' => '']);
            repondre(200, $etatSms());
        }
        if ($api === 'sms-rappels') {
            api_garde();
            [$n, $e] = sms_rappels($qui);
            if (!$n && $e) repondre(400, ['erreur' => $e]);
            repondre(200, ['n' => $n, 'avert' => $e] + $etatSms());
        }
        if ($api === 'sms-une') {
            api_garde();
            $l = resas_charger(); $r = null;
            foreach ($l as $x) if ($x['id'] === ($in['id'] ?? '')) $r = $x;
            if (!$r) repondre(400, ['erreur' => 'Cette réservation n’existe plus.']);
            $clients = clients_charger(); $i = client_par_id($clients, $r['client']);
            if ($i === null || tel_international($clients[$i]['tel']) === '') repondre(400, ['erreur' => 'Pas de numéro de portable pour ce client.']);
            if (($clients[$i]['sms']['statut'] ?? '') === 'non') repondre(400, ['erreur' => 'Ce client a refusé les SMS.']);
            $c = sms_config();
            [, $e] = sms_envoyer($clients[$i]['tel'], sms_texte($c['message'], $r, $clients[$i]), $c);
            if ($e) repondre(400, ['erreur' => $e]);
            verrou(function () use ($r, $qui) { $l = resas_charger(); foreach ($l as &$x) if ($x['id'] === $r['id']) $x['rappel'] = date('Y-m-d H:i') . ' · ' . $qui; unset($x); resas_enregistrer($l); });
            sms_noter(['date' => date('Y-m-d H:i'), 'mode' => $qui, 'ok' => 1, 'erreur' => '']);
            foreach (resas_charger() as $x) if ($x['id'] === $r['id']) $r = $x;
            $ri = $clients[$i]; unset($ri['jeton']); $r['c'] = $ri;
            repondre(200, ['ok' => true, 'resa' => $r]);
        }
        api_garde(true);   // réglages : administrateurs seulement
        if ($api === 'sms-cles') {
            $k = ['app_key' => trim((string)($in['app_key'] ?? '')), 'app_secret' => trim((string)($in['app_secret'] ?? '')), 'consumer_key' => trim((string)($in['consumer_key'] ?? ''))];
            foreach ($k as $v) if (!preg_match('/^[A-Za-z0-9]{8,64}$/', $v)) repondre(400, ['erreur' => 'Les trois clés doivent être copiées en entier (lettres et chiffres, sans espace).']);
            [$j, $e] = ovh_appel('GET', '/sms', null, $k + sms_config());
            if ($e) repondre(400, ['erreur' => $e]);
            if (!is_array($j) || !$j) repondre(400, ['erreur' => 'Les clés fonctionnent, mais aucun compte SMS n’est ouvert chez OVH : commandez d’abord des crédits SMS (Télécom → SMS).']);
            if (!sms_modifier($k + ['service' => (string)$j[0]])) repondre(500, ['erreur' => 'Impossible d’enregistrer : le dossier data/ n’est pas modifiable.']);
            repondre(200, $etatSms());
        }
        if ($api === 'sms-options') {
            $m = [];
            if (array_key_exists('auto', $in)) $m['auto'] = !empty($in['auto']);
            if (isset($in['heure']) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$in['heure'])) {
                $m['heure'] = $in['heure'];
                if ($in['heure'] > date('H:i') && sms_config()['fait'] === date('Y-m-d')) $m['fait'] = '';   // heure repoussée : l'envoi du jour reste à faire
            }
            if (isset($in['cible'])) $m['cible'] = $in['cible'] === 'accord' ? 'accord' : 'tous';
            if (isset($in['message'])) $m['message'] = cut(trim((string)$in['message']), 600);
            if (isset($in['expediteur'])) $m['expediteur'] = preg_replace('/[^A-Za-z0-9 ]/', '', cut((string)$in['expediteur'], 11));
            // activation dans la journée après l'heure : on ne renvoie pas pour aujourd'hui sans le vouloir
            if (!empty($m['auto']) && !sms_config()['auto'] && date('H:i') >= ($m['heure'] ?? sms_config()['heure'])) $m['fait'] = date('Y-m-d');
            sms_modifier($m);
            repondre(200, $etatSms());
        }
        if ($api === 'sms-oublier') { @unlink(SMS_CONFIG); repondre(200, $etatSms()); }
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
    } elseif ($action === 'oubli') {
        // Mot de passe oublié : lien valable 1 heure envoyé à l'adresse du compte. Même réponse que le compte existe ou non.
        $u = compte_par_login($_POST['login'] ?? '');
        if (($_POST['site_web'] ?? '') === '' && !limite_ip('oubli', 5) && $u && $u['email'] !== '') {
            $jeton = bin2hex(random_bytes(24));
            $l = comptes_charger();
            foreach ($l as &$x) if ($x['id'] === $u['id']) { $x['reinit'] = hash('sha256', $jeton); $x['reinit_fin'] = time() + 3600; }
            unset($x);
            comptes_enregistrer($l);
            $lien = adresse_site() . '/' . ESPACE . '/?reinit=' . $jeton;
            mail_envoyer($u['email'], 'Nouveau mot de passe · Gestion du site Au Jardin Parmentier',
                '<div style="font:15px/1.6 Arial,sans-serif;color:#1d171b;max-width:520px"><p>Bonjour ' . h($u['nom'] ?: $u['identifiant']) . ',</p><p>Pour choisir un nouveau mot de passe pour la gestion du site, cliquez sur ce bouton (valable 1 heure) :</p>'
                . '<p><a href="' . h($lien) . '" style="display:inline-block;background:#700954;color:#fff;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:10px">Choisir un nouveau mot de passe</a></p>'
                . '<p style="font-size:13px;color:#6b6369">Vous n’avez rien demandé ? Ignorez cet e-mail : votre mot de passe actuel reste valable.<br>Votre identifiant : ' . h($u['identifiant']) . '</p></div>',
                "Bonjour,\n\nPour choisir un nouveau mot de passe (lien valable 1 heure) :\n$lien\n\nVous n'avez rien demandé ? Ignorez cet e-mail.\nVotre identifiant : {$u['identifiant']}\n");
        }
        usleep(400000);
        $info = 'Si un compte correspond, un e-mail avec un lien pour choisir un nouveau mot de passe vient d’être envoyé. Pensez à regarder dans les courriers indésirables.';
    } elseif ($action === 'reinit') {
        $u = compte_reinit($_POST['jeton'] ?? '');
        if (!$u) $err = 'Ce lien a expiré ou a déjà servi. Refaites une demande avec « Mot de passe oublié ».';
        elseif ($e = mdp_faible((string)($_POST['pwd'] ?? ''))) $err = $e;
        elseif ((string)$_POST['pwd'] !== (string)($_POST['pwd2'] ?? '')) $err = 'Les deux mots de passe ne sont pas identiques.';
        else {
            $l = comptes_charger();
            foreach ($l as &$x) if ($x['id'] === $u['id']) { $x['hash'] = password_hash((string)$_POST['pwd'], PASSWORD_DEFAULT); unset($x['reinit'], $x['reinit_fin']); }
            unset($x);
            comptes_enregistrer($l);
            tentatives(false);
            header('Location: ./?change=1'); exit;
        }
    } elseif ($action === 'logout') {
        $_SESSION = []; session_destroy(); header('Location: ./'); exit;
    }
}

$etat = null;
if ($logged) {
    lettre_auto(site_load());
    sms_auto();
    $photos = [];
    foreach (photos_dispo() as $p) $photos[] = ['nom' => $p, 'url' => photo($p)];
    $etat = ['site' => site_load(), 'photos' => $photos, 'csrf' => $_SESSION['csrf'], 'aujourdhui' => date('Y-m-d'),
             'api' => ESPACE . '/', 'moi' => compte_public($moi), 'comptes' => $estAdmin ? array_map('compte_public', comptes_charger()) : [], 'roles' => ROLES, 'qui' => $moi['nom'] ?: $moi['identifiant'], 'forte' => FORTE_DEMANDE, 'forteOffre' => FORTE_OFFRE,
             'commande' => commande_config() + ['jours' => count(commande_jours(site_load()))]];
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
<link rel="preload" href="fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="fonts/baloo2-latin.woff2" as="font" type="font/woff2" crossorigin>
<link href="fonts/fonts.css" rel="stylesheet">
<link href="admin.css?v=31" rel="stylesheet">
</head>
<body>

<?php if (!$logged): $setup = !$comptes; $_SESSION['form_t'] = time();
  $jetonReinit = (string)($_GET['reinit'] ?? $_POST['jeton'] ?? '');
  $mode = $setup ? 'setup' : ($jetonReinit !== '' ? 'reinit' : (isset($_GET['oubli']) || ($_POST['action'] ?? '') === 'oubli' ? 'oubli' : 'login'));
  if ($mode === 'reinit' && !compte_reinit($jetonReinit) && !$err) $err = 'Ce lien a expiré ou a déjà servi. Refaites une demande avec « Mot de passe oublié ».';
  if (isset($_GET['change'])) $info = 'Mot de passe changé. Vous pouvez vous connecter.'; ?>
<main class="auth">
  <form method="post" class="auth-carte">
    <img src="favicon.svg" alt="" width="56" height="56">
    <h1><?= ['setup' => 'Créer le compte administrateur', 'oubli' => 'Mot de passe oublié', 'reinit' => 'Nouveau mot de passe', 'login' => 'Gestion du site'][$mode] ?></h1>
    <p class="aide"><?= ['setup' => 'Première connexion : créez votre compte. Vous pourrez ensuite ajouter d’autres utilisateurs.', 'oubli' => 'Indiquez votre identifiant ou votre adresse e-mail : vous recevrez un lien pour choisir un nouveau mot de passe.', 'reinit' => 'Choisissez votre nouveau mot de passe.', 'login' => 'Au Jardin Parmentier'][$mode] ?></p>
<?php if ($info): ?>    <p class="info" role="status"><?= h($info) ?></p>
<?php endif; ?>
<?php if ($err): ?>    <p class="alerte" role="alert"><?= h($err) ?></p>
<?php endif; ?>
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="<?= $mode ?>">
<?php if ($mode === 'reinit'): ?>    <input type="hidden" name="jeton" value="<?= h($jetonReinit) ?>">
<?php endif; ?>
    <div class="piege" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"><label>Site web <input name="site_web" tabindex="-1" autocomplete="off"></label></div>
<?php if ($mode === 'setup'): ?>
    <label class="champ"><span>Identifiant</span><input name="identifiant" required pattern="[A-Za-z0-9._\-]{3,30}" autocomplete="username" value="<?= h($_POST['identifiant'] ?? '') ?>"></label>
    <label class="champ"><span>Adresse e-mail</span><input type="email" name="email" required autocomplete="email" value="<?= h($_POST['email'] ?? '') ?>"></label>
    <label class="champ"><span>Mot de passe</span><input type="password" name="pwd" minlength="10" required autocomplete="new-password"><small>10 caractères minimum, avec au moins une lettre et un chiffre.</small></label>
    <label class="champ"><span>Confirmation</span><input type="password" name="pwd2" minlength="10" required autocomplete="new-password"></label>
    <button class="btn btn-violet btn-plein">Créer le compte</button>
<?php elseif ($mode === 'oubli'): ?>
    <label class="champ"><span>Identifiant ou adresse e-mail</span><input name="login" required autocomplete="username" autofocus value="<?= h($_POST['login'] ?? '') ?>"></label>
    <button class="btn btn-violet btn-plein">Recevoir le lien par e-mail</button>
    <p class="aide petit-aide"><a href="<?= ESPACE ?>/">← Retour à la connexion</a></p>
<?php elseif ($mode === 'reinit'): ?>
    <label class="champ"><span>Nouveau mot de passe</span><input type="password" name="pwd" minlength="10" required autocomplete="new-password" autofocus><small>10 caractères minimum, avec au moins une lettre et un chiffre.</small></label>
    <label class="champ"><span>Confirmation</span><input type="password" name="pwd2" minlength="10" required autocomplete="new-password"></label>
    <button class="btn btn-violet btn-plein">Enregistrer le mot de passe</button>
<?php else: ?>
    <label class="champ"><span>Identifiant ou adresse e-mail</span><input name="login" required autocomplete="username" autofocus value="<?= h($_POST['login'] ?? '') ?>"></label>
    <label class="champ"><span>Mot de passe</span>
      <span class="mdp-zone"><input type="password" name="pwd" required autocomplete="current-password"><button type="button" class="voir-mdp" aria-label="Afficher le mot de passe" onclick="var i=this.previousElementSibling;i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'Afficher':'Masquer'">Afficher</button></span></label>
    <button class="btn btn-violet btn-plein">Se connecter</button>
    <p class="aide petit-aide"><a href="<?= ESPACE ?>/?oubli">Mot de passe oublié ?</a></p>
<?php endif; ?>
    <a class="lien-site" href="./">← Retour au site</a>
    <p class="version">Version <?= h(VERSION) ?></p>
  </form>
</main>

<?php else: ?>
<header class="barre-haut">
  <a class="logo" href="<?= ESPACE ?>/"><img src="favicon.svg" alt="" width="34" height="34"><span>Gestion du site</span></a>
  <nav class="onglets" role="tablist" aria-label="Rubriques">
    <!-- Rangées par usage : la boutique, puis les menus du site, puis le reste dans « Plus » -->
    <button role="tab" data-onglet="resas" aria-selected="false"><svg class="ico"><use href="#i-resa"/></svg>Réservations</button>
    <button role="tab" data-onglet="clients" aria-selected="false"><svg class="ico"><use href="#i-clients"/></svg>Clients</button>
    <span class="sep" aria-hidden="true"></span>
    <button role="tab" data-onglet="semaine" aria-selected="false"><svg class="ico"><use href="#i-cal"/></svg>Menu de la semaine</button>
    <button role="tab" data-onglet="menus" aria-selected="false"><svg class="ico"><use href="#i-livre"/></svg>Cartes traiteur</button>
    <button role="tab" data-onglet="evenements" aria-selected="false"><svg class="ico"><use href="#i-fete"/></svg>Événements</button>
    <span class="sep" aria-hidden="true"></span>
    <button type="button" class="plus-btn" id="plus-btn" aria-haspopup="menu" aria-expanded="false" aria-controls="plus-liste"><span id="plus-txt">Plus</span><svg class="ico fleche"><use href="#i-bas"/></svg></button>
  </nav>
  <div class="haut-actions">
    <button type="button" class="cloche" id="cloche" aria-haspopup="true" aria-expanded="false" aria-controls="notifs" title="Notifications récentes"><svg class="ico"><use href="#i-cloche"/></svg><span class="sr-only">Notifications</span><span class="cloche-nb" id="cloche-nb" hidden></span></button>
    <button type="button" class="qui" data-onglet="comptes" aria-selected="false" title="<?= $estAdmin ? 'Utilisateurs et mon compte' : 'Mon compte' ?> (<?= h($moi['email']) ?>)"><span class="avatar"><?= h(strtoupper(substr($moi['nom'] ?: $moi['identifiant'], 0, 1))) ?></span><span class="qui-nom"><?= h($moi['nom'] ?: $moi['identifiant']) ?></span></button>
    <a class="btn btn-discret" href="./" target="_blank" rel="noopener"><svg class="ico"><use href="#i-oeil"/></svg><span>Voir le site</span></a>
    <form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="logout"><button class="btn btn-discret" title="Se déconnecter"><svg class="ico"><use href="#i-sortie"/></svg><span>Quitter</span></button></form>
  </div>
</header>
<div class="notifs" id="notifs" role="dialog" aria-label="Notifications récentes" hidden></div>
<div class="plus-liste" id="plus-liste" role="menu" aria-labelledby="plus-btn" hidden>
  <button role="menuitem" data-onglet="avis" aria-selected="false"><svg class="ico"><use href="#i-etoile"/></svg>Avis</button>
  <button role="menuitem" data-onglet="faq" aria-selected="false"><svg class="ico"><use href="#i-question"/></svg>Questions fréquentes</button>
  <button role="menuitem" data-onglet="mots" aria-selected="false"><svg class="ico"><use href="#i-mots"/></svg>Mots défilants</button>
  <button role="menuitem" data-onglet="stats" aria-selected="false"><svg class="ico"><use href="#i-stats"/></svg>Statistiques</button>
</div>

<main id="app" class="app" aria-live="polite"></main>

<div class="enreg" id="enreg" hidden>
  <p id="enreg-txt">Modifications non enregistrées</p>
  <button class="btn btn-discret" id="annuler" type="button">Annuler</button>
  <button class="btn btn-violet" id="enregistrer" type="button">Enregistrer</button>
</div>
<div class="toast" id="toast" role="status" hidden></div>

<script type="application/json" id="etat"><?= json_encode($etat, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="visuels.js?v=2"></script>
<script src="admin.js?v=32"></script>
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
    <symbol id="i-resa" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="m9 14 2 2 4-4"/></g></symbol>
    <symbol id="i-clients" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/></g></symbol>
    <symbol id="i-ok" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M20 6 9 17l-5-5"/></symbol>
    <symbol id="i-note" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></g></symbol>
    <symbol id="i-tel" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></symbol>
    <symbol id="i-mail" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></g></symbol>
    <symbol id="i-envoyer" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/></g></symbol>
    <symbol id="i-telecharger" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></g></symbol>
    <symbol id="i-sac" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></g></symbol>
    <symbol id="i-question" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"/></g></symbol>
    <symbol id="i-cloche" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></g></symbol>
  </defs>
</svg>
</body>
</html>
