<?php
// Clients, réservations des plats du jour et lettre « menu de la semaine ».
// data/clients.json, data/reservations.json et data/lettre.json : jamais dans Git, jamais lisibles depuis le navigateur.
const CLIENTS = DATA . '/clients.json';
const RESAS = DATA . '/reservations.json';
const LETTRE = DATA . '/lettre.json';
const EXPEDITEUR = 'info@lejardinparmentier.fr';
const SITE_URL = 'https://www.lejardinparmentier.fr';

function lire_json($f) { $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null; return is_array($d) ? $d : []; }

// Lecture puis écriture protégées : deux personnes qui enregistrent en même temps ne s'écrasent pas
function verrou($fn) {
    $h = @fopen(DATA . '/.verrou', 'c');
    if ($h) flock($h, LOCK_EX);
    try { return $fn(); } finally { if ($h) { flock($h, LOCK_UN); fclose($h); } }
}

// ---- Téléphone : « 0612345678 », « +33 6 12… » → « 06 12 34 56 78 » ----
function tel_chiffres($t) {
    $d = preg_replace('/\D/', '', (string)$t);
    if (strlen($d) === 11 && strpos($d, '33') === 0) $d = '0' . substr($d, 2);
    return $d;
}
function tel_fr($t) { $d = tel_chiffres($t); return strlen($d) === 10 ? trim(chunk_split($d, 2, ' ')) : txt($t, 20); }
function email_ok($e) { $e = strtolower(trim((string)$e)); return filter_var($e, FILTER_VALIDATE_EMAIL) ? cut($e, 120) : ''; }

// ---- Clients ----
function clients_charger() {
    $vide = ['statut' => '', 'date' => '', 'source' => '', 'historique' => []];
    return array_map(function ($c) use ($vide) { return $c + ['lettre' => $vide, 'sms' => $vide, 'note' => '']; }, array_values(array_filter(lire_json(CLIENTS), 'is_array')));
}
function clients_enregistrer($l) { return json_save(CLIENTS, array_values($l)); }

function client_clean($in, $ancien = null) {
    return [
        'id' => $ancien['id'] ?? bin2hex(random_bytes(6)),
        'prenom' => txt($in['prenom'] ?? '', 40),
        'nom' => txt($in['nom'] ?? '', 60),
        'tel' => tel_fr($in['tel'] ?? ''),
        'email' => email_ok($in['email'] ?? ''),
        'note' => txt($in['note'] ?? ($ancien['note'] ?? ''), 300),
        // Lettre : statut « oui » / « non » / '' (jamais demandé) ; l'historique garde la preuve du consentement
        'lettre' => $ancien['lettre'] ?? ['statut' => '', 'date' => '', 'source' => '', 'historique' => []],
        // SMS : même principe, pour recevoir des messages sur son téléphone (menu, rappel de commande…)
        'sms' => $ancien['sms'] ?? ['statut' => '', 'date' => '', 'source' => '', 'historique' => []],
        'jeton' => $ancien['jeton'] ?? bin2hex(random_bytes(12)),
        'cree' => $ancien['cree'] ?? date('Y-m-d H:i'),
    ];
}
// Change un accord (« lettre » = e-mail, « sms » = téléphone) et le note dans l'historique (preuve du consentement)
function client_accord(&$c, $quoi, $oui, $source) {
    $statut = $oui ? 'oui' : 'non';
    $a = $c[$quoi] ?? ['statut' => '', 'historique' => []];
    if ($a['statut'] === $statut) return;
    $a = ['statut' => $statut, 'date' => date('Y-m-d H:i'), 'source' => $source, 'historique' => array_slice(array_merge($a['historique'] ?? [], [['statut' => $statut, 'date' => date('Y-m-d H:i'), 'source' => $source]]), -10)];
    $c[$quoi] = $a;
}
function client_lettre(&$c, $oui, $source) { client_accord($c, 'lettre', $oui, $source); }
function client_nom($c) { return trim(($c['prenom'] ?? '') . ' ' . ($c['nom'] ?? '')); }
// Retrouve un client existant par son téléphone ou son e-mail
function client_index($l, $tel, $email) {
    $t = tel_chiffres($tel); $e = email_ok($email);
    foreach ($l as $i => $c) {
        if (strlen($t) >= 10 && tel_chiffres($c['tel']) === $t) return $i;
        if ($e !== '' && $c['email'] === $e) return $i;
    }
    return null;
}
function client_par_id($l, $id) { foreach ($l as $i => $c) if ($c['id'] === $id) return $i; return null; }

// Compte « visiteur » : les coordonnées des clients sont masquées
function client_masque($c) {
    $c['nom'] = $c['nom'] !== '' ? cut($c['nom'], 1) . '.' : '';
    $d = tel_chiffres($c['tel']);
    $c['tel'] = $d !== '' ? substr($d, 0, 2) . ' •• •• •• ' . substr($d, -2) : '';
    $c['email'] = $c['email'] !== '' ? substr($c['email'], 0, 1) . '•••@' . substr(strrchr($c['email'], '@'), 1) : '';
    $c['note'] = $c['note'] !== '' ? '•••' : '';
    return $c;
}
function client_public($c, $masquer) {
    unset($c['jeton']);
    return $masquer ? client_masque($c) : $c;
}

// ---- Réservations ----
function resas_charger() { return array_values(array_filter(lire_json(RESAS), 'is_array')); }
function resas_enregistrer($l) { return json_save(RESAS, array_values($l)); }
// Numéros de commande : un compteur qui ne recule jamais (data/numeros.json), même si une réservation est supprimée.
// Un numéro n'est donc jamais attribué deux fois. À appeler sous verrou().
const NUMEROS = DATA . '/numeros.json';
function resa_num_suivant($l) {
    $n = max((int)(lire_json(NUMEROS)['dernier'] ?? 0), array_reduce($l, function ($m, $r) { return max($m, (int)($r['num'] ?? 0)); }, 0)) + 1;
    json_save(NUMEROS, ['dernier' => $n]);
    return $n;
}
// Vérification à chaque chargement : les réservations sans numéro (créées avant les numéros) ou en double en reçoivent un nouveau
function resas_numeroter() {
    verrou(function () {
        $l = resas_charger();
        $ordre = array_keys($l);
        usort($ordre, function ($a, $b) use ($l) { return strcmp($l[$a]['cree'], $l[$b]['cree']); });
        $vus = []; $change = false;
        foreach ($ordre as $k) {
            $n = (int)($l[$k]['num'] ?? 0);
            if ($n < 1 || isset($vus[$n])) { $n = resa_num_suivant($l); $l[$k]['num'] = $n; $change = true; }
            $vus[$n] = true;
        }
        if ($change) resas_enregistrer($l);
    });
}

function resa_clean($in, $ancien, $qui) {
    $lignes = [];
    foreach (liste($in['lignes'] ?? []) as $l) {
        $l = ['type' => in_array($l['type'] ?? '', ['plat', 'special', 'evenement', 'autre'], true) ? $l['type'] : 'autre',
              'nom' => txt($l['nom'] ?? '', 120), 'prix' => txt($l['prix'] ?? '', 20), 'qte' => max(1, min(99, (int)($l['qte'] ?? 1))),
              // plat d'un autre jour (ex. le plat de mercredi réservé pour vendredi) : à mettre de côté ce jour-là
              'jour' => date_ok($l['jour'] ?? '')];
        if ($l['nom'] !== '') $lignes[] = $l;
    }
    $r = [
        'id' => $ancien['id'] ?? bin2hex(random_bytes(6)),
        'num' => (int)($ancien['num'] ?? 0),   // numéro de commande, attribué à la création
        'date' => date_ok($in['date'] ?? '') ?: date('Y-m-d'),
        'client' => (string)($in['client'] ?? ''),
        'lignes' => $lignes,
        'note' => txt($in['note'] ?? '', 500),
        'paye' => $ancien['paye'] ?? false, 'paye_le' => $ancien['paye_le'] ?? '',
        'recupere' => $ancien['recupere'] ?? false, 'recupere_le' => $ancien['recupere_le'] ?? '',
        'par' => $ancien['par'] ?? $qui, 'cree' => $ancien['cree'] ?? date('Y-m-d H:i'),
        'modifie' => $ancien ? date('Y-m-d H:i') . ' · ' . $qui : '',
        'rappel' => $ancien['rappel'] ?? '',
        'source' => $ancien['source'] ?? (($in['source'] ?? '') === 'site' ? 'site' : 'back-office'),   // « site » : commande passée sur le site   // SMS « votre commande vous attend » déjà envoyé (date · par qui)
    ];
    if (array_key_exists('paye', $in)) resa_etat($r, 'paye', !empty($in['paye']), $qui);
    return $r;
}
function resa_etat(&$r, $champ, $valeur, $qui) {
    if ($r[$champ] === $valeur) return;
    $r[$champ] = $valeur;
    $r[$champ . '_le'] = $valeur ? date('Y-m-d H:i') . ' · ' . $qui : '';
}

// ---- Lettre du menu de la semaine ----
function lettre_config() {
    $c = lire_json(LETTRE);
    return ['auto' => !empty($c['auto']), 'envois' => liste($c['envois'] ?? [])];
}
function lettre_noter($envoi) {
    $c = lire_json(LETTRE);
    $c['envois'] = array_slice(array_merge(liste($c['envois'] ?? []), [$envoi]), -30);
    return json_save(LETTRE, $c);
}
function lettre_inscrits($clients) { return array_values(array_filter($clients, function ($c) { return $c['lettre']['statut'] === 'oui' && $c['email'] !== ''; })); }

// Menu à envoyer cette semaine : celui qui commence entre lundi et dimanche (semaine en cours ou semaine suivante préparée)
function lettre_semaine($d) {
    $lundi = date('Y-m-d', strtotime('monday this week'));
    $dimanche = date('Y-m-d', strtotime($lundi . ' +6 days'));
    foreach (['semaine', 'semaine_suivante'] as $k) {
        $s = $d[$k];
        if ($s['du'] >= $lundi && $s['du'] <= $dimanche && !$s['conges']['actif'] && jours_visibles($s['jours'])) return $s;
    }
    return null;
}
// Le menu est-il complet (chaque jour a un plat ou est marqué fermé) ? Évite d'envoyer un menu à moitié saisi.
function semaine_complete($s) {
    foreach ($s['jours'] as $j) if ($j['nom'] === '' && !$j['ferme']) return false;
    return (bool)$s['jours'];
}
function lettre_deja($du) { foreach (lettre_config()['envois'] as $e) if (($e['du'] ?? '') === $du && ($e['mode'] ?? '') !== 'test') return $e; return null; }

function adresse_site() {
    $h = $_SERVER['HTTP_HOST'] ?? '';
    return preg_match('/^[a-z0-9.-]+(:\d+)?$/i', $h) ? (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . $h : SITE_URL;
}

function lettre_html($s, $prenom, $lienDesabo) {
    $base = adresse_site();
    $e = function ($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); };
    $jours = '';
    foreach (jours_visibles($s['jours']) as $j) {
        $jours .= '<tr><td style="padding:12px 0;border-bottom:1px solid #eee5dc;width:92px;vertical-align:top;font:700 13px Arial,sans-serif;color:#700954;text-transform:uppercase;letter-spacing:.06em">' . $e($j['jour']) . '</td>'
            . '<td style="padding:12px 0;border-bottom:1px solid #eee5dc;vertical-align:top;font:15px/1.4 Arial,sans-serif;color:#1d171b">'
            . ($j['ferme'] ? '<strong>' . $e($j['motif'] ?: 'Fermé') . '</strong>' . ($j['message'] !== '' ? '<br><span style="color:#6b6369">' . $e($j['message']) . '</span>' : '')
                           : '<strong>' . $e($j['nom']) . '</strong>' . ($j['desc'] !== '' ? '<br><span style="color:#6b6369">' . $e($j['desc']) . '</span>' : ''))
            . '</td><td style="padding:12px 0 12px 10px;border-bottom:1px solid #eee5dc;vertical-align:top;text-align:right;white-space:nowrap;font:700 15px Arial,sans-serif;color:#700954">' . ($j['ferme'] ? '' : $e($j['prix'])) . '</td></tr>';
    }
    $sp = $s['special'];
    $special = $sp['nom'] !== '' || $sp['lignes'] ? '<div style="margin:22px 0 0;padding:18px 20px;border-radius:14px;background:#700954;color:#fff;font:14px/1.5 Arial,sans-serif">'
        . ($sp['titre'] !== '' ? '<div style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;opacity:.8">' . $e($sp['titre']) . '</div>' : '')
        . '<div style="font:700 18px Arial,sans-serif;margin:2px 0 4px">' . $e($sp['nom']) . ($sp['prix'] !== '' ? ' · ' . $e($sp['prix']) : '') . '</div>'
        . implode('<br>', array_map($e, $sp['lignes'])) . '</div>' : '';
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
        . '<body style="margin:0;background:#f2ece4;padding:24px 12px"><div style="max-width:560px;margin:0 auto;background:#fff;border-radius:18px;padding:28px 26px">'
        . '<p style="margin:0 0 4px;font:700 12px Arial,sans-serif;letter-spacing:.12em;text-transform:uppercase;color:#700954">Au Jardin Parmentier · plats du jour</p>'
        . '<h1 style="margin:0 0 4px;font:800 26px/1.15 Arial,sans-serif;color:#1d171b">' . $e($s['titre'] ?: 'Le menu de la semaine') . '</h1>'
        . '<p style="margin:0 0 16px;font:15px Arial,sans-serif;color:#6b6369">' . ($prenom !== '' ? 'Bonjour ' . $e($prenom) . ', voici' : 'Voici') . ' nos plats de la semaine ' . $e(periode_fr($s['du'], $s['au'])) . '.</p>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse">' . $jours . '</table>' . $special
        . ($s['note'] !== '' ? '<p style="margin:18px 0 0;font:14px Arial,sans-serif;color:#6b6369">' . $e($s['note']) . '</p>' : '')
        . '<p style="margin:24px 0 0;text-align:center"><a href="tel:+33950173962" style="display:inline-block;background:#700954;color:#fff;text-decoration:none;font:700 15px Arial,sans-serif;padding:13px 22px;border-radius:12px">Réserver au 09 50 17 39 62</a></p>'
        . '<p style="margin:22px 0 0;font:13px/1.5 Arial,sans-serif;color:#6b6369;text-align:center">À emporter · 9, place Parmentier – 80500 Montdidier<br><a href="' . $e($base) . '/" style="color:#700954">lejardinparmentier.fr</a></p>'
        . '</div><p style="max-width:560px;margin:14px auto 0;font:12px Arial,sans-serif;color:#8b8288;text-align:center">Vous recevez ce message car vous êtes inscrit(e) au menu de la semaine. '
        . '<a href="' . $e($lienDesabo) . '" style="color:#8b8288">Se désinscrire en un clic</a></p></body></html>';
}
function lettre_texte($s, $prenom, $lienDesabo) {
    $t = ($prenom !== '' ? "Bonjour $prenom,\n\n" : '') . 'Nos plats de la semaine ' . periode_fr($s['du'], $s['au']) . " :\n\n";
    foreach (jours_visibles($s['jours']) as $j) $t .= $j['jour'] . ' : ' . ($j['ferme'] ? ($j['motif'] ?: 'Fermé') : $j['nom'] . ($j['desc'] !== '' ? ', ' . $j['desc'] : '') . ($j['prix'] !== '' ? ' (' . $j['prix'] . ')' : '')) . "\n";
    if ($s['special']['nom'] !== '') $t .= "\n" . $s['special']['nom'] . ($s['special']['prix'] !== '' ? ' · ' . $s['special']['prix'] : '') . "\n" . implode("\n", $s['special']['lignes']) . "\n";
    return $t . "\nRéservation au 09 50 17 39 62\nAu Jardin Parmentier, 9 place Parmentier, 80500 Montdidier\n\nSe désinscrire : $lienDesabo\n";
}

// Envoi d'un e-mail HTML (avec version texte) depuis l'adresse de la boutique
// Envoi d'un e-mail HTML (avec version texte). Si une boîte mail est connectée dans le back-office
// (Utilisateurs → Envoi des e-mails), l'envoi passe par elle en SMTP (plus fiable), sinon par la fonction mail() de l'hébergement.
function mail_envoyer($a, $sujet, $html, $texte, $desabo = '', $smtp = null) {
    $smtp = $smtp ?: smtp_config();
    $de = $smtp['pret'] ? $smtp['utilisateur'] : EXPEDITEUR;
    $b = 'jp' . bin2hex(random_bytes(8));
    $entetes = "From: Au Jardin Parmentier <$de>\r\nReply-To: $de\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$b\"";
    if ($desabo !== '') $entetes .= "\r\nList-Unsubscribe: <$desabo>\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click";
    $corps = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texte))
           . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$b--";
    $sujet = '=?UTF-8?B?' . base64_encode($sujet) . '?=';
    if ($smtp['pret']) {
        [$ok, $e] = smtp_envoyer($smtp, $a, $sujet, $corps, $entetes);
        $GLOBALS['mail_erreur'] = $e;
        return $ok;
    }
    $ok = @mail($a, $sujet, $corps, $entetes);
    $GLOBALS['mail_erreur'] = $ok ? '' : 'l’hébergement a refusé l’envoi';
    return $ok;
}

// ---- Boîte mail connectée (SMTP), par défaut celle d'OVH : ssl0.ovh.net, port 465 ----
const SMTP_CONFIG = DATA . '/smtp.json';
function smtp_config() {
    $c = lire_json(SMTP_CONFIG);
    $c = ['hote' => (string)($c['hote'] ?? 'ssl0.ovh.net'), 'port' => (int)($c['port'] ?? 465), 'utilisateur' => (string)($c['utilisateur'] ?? ''), 'mdp' => (string)($c['mdp'] ?? '')];
    $c['pret'] = $c['utilisateur'] !== '' && $c['mdp'] !== '';
    return $c;
}
// Dialogue SMTP minimal : SSL direct (465), STARTTLS (587) ou en clair (tests). Renvoie [succès, erreur]
function smtp_envoyer($c, $a, $sujet, $corps, $entetes) {
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $f = @stream_socket_client(($c['port'] === 465 ? 'ssl://' : 'tcp://') . $c['hote'] . ':' . $c['port'], $no, $msg, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$f) return [false, 'impossible de joindre ' . $c['hote'] . ' (' . ($msg ?: 'connexion refusée') . ')'];
    stream_set_timeout($f, 20);
    $lire = function () use ($f) { $r = ''; while (($l = fgets($f, 1024)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
    $cmd = function ($ligne, $attendus) use ($f, $lire) {
        if ($ligne !== null) fwrite($f, $ligne . "\r\n");
        $r = $lire();
        if (!in_array((int)substr($r, 0, 3), (array)$attendus, true)) throw new RuntimeException(trim($r) ?: 'le serveur ne répond plus');
        return $r;
    };
    $nom = preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['HTTP_HOST'] ?? '') ?: 'lejardinparmentier.fr';
    try {
        $cmd(null, 220);
        $cmd('EHLO ' . $nom, 250);
        if ($c['port'] === 587) {
            $cmd('STARTTLS', 220);
            if (!stream_socket_enable_crypto($f, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('chiffrement impossible');
            $cmd('EHLO ' . $nom, 250);
        }
        $cmd('AUTH LOGIN', 334);
        $cmd(base64_encode($c['utilisateur']), 334);
        try { $cmd(base64_encode($c['mdp']), 235); } catch (RuntimeException $e) { throw new RuntimeException('adresse e-mail ou mot de passe refusé par le serveur'); }
        $cmd('MAIL FROM:<' . $c['utilisateur'] . '>', 250);
        $cmd('RCPT TO:<' . $a . '>', [250, 251]);
        $cmd('DATA', 354);
        $domaine = substr(strrchr($c['utilisateur'], '@'), 1) ?: $nom;
        $msg = "To: <$a>\r\nSubject: $sujet\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . "@$domaine>\r\n$entetes\r\n\r\n$corps";
        $msg = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $msg));
        $cmd($msg . "\r\n.", 250);
        @fwrite($f, "QUIT\r\n");
        fclose($f);
        return [true, ''];
    } catch (RuntimeException $e) {
        @fclose($f);
        return [false, $e->getMessage()];
    }
}

function lettre_sujet($s) { return 'Le menu de la semaine ' . periode_fr($s['du'], $s['au']) . ' · Au Jardin Parmentier'; }
function lien_desabo($c) { return adresse_site() . '/lettre?d=' . $c['jeton']; }

// Envoie le menu à tous les inscrits. $mode : « auto » ou « manuel » (le nom de la personne). Renvoie [nombre envoyé, erreur]
function lettre_envoyer($s, $mode) {
    if (!$s) return [0, 'Aucun menu à envoyer cette semaine.'];
    @set_time_limit(300); ignore_user_abort(true);
    $l = lettre_inscrits(clients_charger());
    $ok = 0;
    foreach ($l as $c) {
        $lien = lien_desabo($c);
        if (mail_envoyer($c['email'], lettre_sujet($s), lettre_html($s, $c['prenom'], $lien), lettre_texte($s, $c['prenom'], $lien), $lien)) $ok++;
        if (count($l) > 20) usleep(150000);   // pas trop vite, pour ne pas être pris pour du spam
    }
    lettre_noter(['du' => $s['du'], 'date' => date('Y-m-d H:i'), 'mode' => $mode, 'nb' => count($l), 'ok' => $ok]);
    return [$ok, $ok < count($l) ? (count($l) - $ok) . ' envoi(s) n’ont pas pu partir.' : null];
}

// Envoi automatique : à partir du lundi 8 h, dès qu'une page est vue, si le menu de la semaine est complet et pas encore envoyé
function lettre_auto($d) {
    if ((int)date('N') > 3 || ((int)date('N') === 1 && (int)date('G') < 8) || !lettre_config()['auto']) return;
    $s = lettre_semaine($d);
    if (!$s || !semaine_complete($s) || lettre_deja($s['du'])) return;
    // on réserve l'envoi tout de suite (sous verrou) pour qu'il ne parte jamais deux fois
    $go = verrou(function () use ($s) {
        if (lettre_deja($s['du'])) return false;
        lettre_noter(['du' => $s['du'], 'date' => date('Y-m-d H:i'), 'mode' => 'auto', 'nb' => 0, 'ok' => 0, 'en_cours' => true]);
        return true;
    });
    if (!$go) return;
    // l'envoi se fait après l'affichage de la page, pour ne pas ralentir le visiteur
    register_shutdown_function(function () use ($s) {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        @set_time_limit(300); ignore_user_abort(true);
        $l = lettre_inscrits(clients_charger()); $ok = 0;
        foreach ($l as $c) {
            $lien = lien_desabo($c);
            if (mail_envoyer($c['email'], lettre_sujet($s), lettre_html($s, $c['prenom'], $lien), lettre_texte($s, $c['prenom'], $lien), $lien)) $ok++;
            if (count($l) > 20) usleep(150000);
        }
        verrou(function () use ($s, $l, $ok) {
            $c = lire_json(LETTRE);
            foreach ($c['envois'] as &$e) if (($e['du'] ?? '') === $s['du'] && !empty($e['en_cours'])) { $e = ['du' => $s['du'], 'date' => $e['date'], 'mode' => 'auto', 'nb' => count($l), 'ok' => $ok]; }
            unset($e);
            json_save(LETTRE, $c);
        });
    });
}

// ---- Réservation en ligne (page d'accueil) ----
const COMMANDE = DATA . '/commande.json';
function commande_config() {
    $c = lire_json(COMMANDE);
    return ['actif' => !empty($c['actif']),
            'limite' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($c['limite'] ?? '')) ? $c['limite'] : '20:00'];   // la veille, jusqu'à cette heure
}
// L'offre spéciale est-elle proposée ce jour-là ? (« Vendredi et samedi » → seulement ces jours ; sans jour indiqué → toute la semaine)
function special_le_jour($sp, $jour) {
    if ($sp['nom'] === '') return false;
    $t = norm($sp['titre']); $cites = array_filter(JOURS, function ($j) use ($t) { return strpos($t, norm($j)) !== false; });
    return !$cites || strpos($t, norm($jour)) !== false;
}
// Jours réservables en ligne : à partir du lendemain (jusqu'à la veille à l'heure limite), uniquement les jours avec un plat
function commande_jours($d) {
    $c = commande_config(); $out = []; $parts = parts_reservees();
    $demain = date('Y-m-d', strtotime('tomorrow'));
    $semaines = [$d['semaine']];
    if ($d['semaine_suivante']['publie'] && semaine_remplie($d['semaine_suivante'])) $semaines[] = $d['semaine_suivante'];
    foreach ($semaines as $s) {
        if ($s['du'] === '' || $s['conges']['actif']) continue;
        $fin = $s['au'] ?: date('Y-m-d', strtotime($s['du'] . ' +6 days'));
        for ($date = max($s['du'], $demain); $date <= $fin; $date = date('Y-m-d', strtotime($date . ' +1 day'))) {
            if (isset($out[$date]) || ($date === $demain && date('H:i') >= $c['limite'])) continue;
            $nomJour = JOURS[(int)date('N', strtotime($date)) - 1];
            foreach ($s['jours'] as $j) {
                if (norm($j['jour']) !== norm($nomJour) || $j['nom'] === '' || $j['ferme']) continue;
                if ($j['epuise']) break;   // épuisé : plus réservable
                $spDispo = special_le_jour($s['special'], $nomJour) && !$s['special']['epuise'];
                $out[$date] = ['date' => $date, 'jour' => $nomJour, 'forte' => ($parts[$date] ?? 0) >= FORTE_DEMANDE, 'libelle' => date_fr($date, true), 'plat' => ['nom' => $j['nom'], 'desc' => $j['desc'], 'prix' => $j['prix']],
                               'special' => $spDispo ? ['nom' => $s['special']['nom'], 'prix' => $s['special']['prix'], 'titre' => $s['special']['titre'], 'lignes' => $s['special']['lignes'],
                                   'forte' => offre_parts('special', $s['special']['nom'], $s['du'], $fin) >= FORTE_OFFRE] : null];
                break;
            }
        }
    }
    ksort($out);
    return array_slice(array_values($out), 0, 12);
}
// Pas plus de $max envois par heure depuis une même connexion
function limite_ip($quoi, $max) {
    $f = DATA . '/limites.json';
    $t = array_filter(lire_json($f), function ($x) { return time() - (int)$x < 3600; });
    $k = substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . $quoi), 0, 16);
    if (count(array_filter(array_keys($t), function ($x) use ($k) { return strpos($x, $k) === 0; })) >= $max) return true;
    $t[$k . '-' . bin2hex(random_bytes(3))] = time();
    json_save($f, $t);
    return false;
}

// Parts réservées par jour pour le plat du jour (y compris les plats mis de côté pour un autre jour) : [date => nombre]
function parts_reservees() {
    $n = [];
    foreach (resas_charger() as $r) foreach ($r['lignes'] as $l) {
        if ($l['type'] !== 'plat') continue;
        $d = ($l['jour'] ?? '') !== '' ? $l['jour'] : $r['date'];
        $n[$d] = ($n[$d] ?? 0) + (int)$l['qte'];
    }
    return $n;
}

// ---- Mémoire des plats épuisés (pour les statistiques : un plat épuisé est un plat qui a marché) ----
const EPUISES = DATA . '/epuises.json';
function epuise_noter($date, $plat) {
    if ($date === '' || $plat === '') return;
    $l = liste(lire_json(EPUISES));
    foreach ($l as $x) if ($x['date'] === $date && $x['plat'] === $plat) return;
    $l[] = ['date' => $date, 'plat' => $plat, 't' => date('Y-m-d H:i')];
    json_save(EPUISES, array_slice($l, -1000));
}
// Date d'un jour du menu (« Mardi » de la semaine du 27 au 31 octobre → 2026-10-27)
function date_du_jour($s, $nom) {
    if ($s['du'] === '') return '';
    for ($k = 0; $k < 7; $k++) { $d = date('Y-m-d', strtotime($s['du'] . " +$k day")); if (norm(JOURS[(int)date('N', strtotime($d)) - 1]) === norm($nom)) return $d; }
    return '';
}

// ---- Statistiques des ventes (réservations) sur $n jours jusqu'à aujourd'hui, comparées à la période d'avant ----
function prix_nombre($p) { return preg_match('/\d+(?:[.,]\d+)?/', (string)$p, $m) ? (float)str_replace(',', '.', $m[0]) : 0.0; }
function ventes_synthese($n) {
    $n = max(1, min(400, (int)$n));
    $auj = date('Y-m-d'); $debut = date('Y-m-d', strtotime('-' . ($n - 1) . ' days')); $debutAvant = date('Y-m-d', strtotime('-' . (2 * $n - 1) . ' days'));
    $resas = resas_charger(); $clients = clients_charger();
    $vide = ['commandes' => 0, 'parts' => 0, 'ca' => 0.0, 'en_ligne' => 0, 'payees' => 0, 'recup' => 0, 'passees' => 0];
    $T = $vide; $A = $vide; $jours = []; $plats = []; $semaine = array_fill(0, 7, 0); $parClient = []; $premiere = []; $avenir = ['commandes' => 0, 'ca' => 0.0];
    $toujours = [];
    for ($i = $n - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $jours[$d] = ['d' => $d, 'commandes' => 0, 'ca' => 0, 'parts' => 0]; }
    foreach ($resas as $r) {
        $ca = 0; $parts = 0;
        foreach ($r['lignes'] as $l) { $ca += prix_nombre($l['prix']) * $l['qte']; $parts += $l['qte']; }
        $premiere[$r['client']] = min($premiere[$r['client']] ?? '9999', $r['date']);
        foreach ($r['lignes'] as $l) if ($r['date'] <= $auj) { $k = trim($l['nom']); $toujours[$k] = ($toujours[$k] ?? 0) + $l['qte']; }
        if ($r['date'] > $auj) { $avenir['commandes']++; $avenir['ca'] += $ca; continue; }
        if ($r['date'] < $debutAvant) continue;
        $actuel = $r['date'] >= $debut;
        $X = &${$actuel ? 'T' : 'A'};
        $X['commandes']++; $X['parts'] += $parts; $X['ca'] += $ca;
        if (($r['source'] ?? '') === 'site') $X['en_ligne']++;
        if ($r['paye']) $X['payees']++;
        if ($r['date'] < $auj) { $X['passees']++; if ($r['recupere']) $X['recup']++; }
        unset($X);
        if (!$actuel) continue;
        $jours[$r['date']]['commandes']++; $jours[$r['date']]['ca'] += $ca; $jours[$r['date']]['parts'] += $parts;
        $semaine[(int)date('N', strtotime($r['date'])) - 1] += $parts;
        foreach ($r['lignes'] as $l) {
            $k = trim($l['nom']) ?: 'Autre';
            if (!isset($plats[$k])) $plats[$k] = ['nom' => $k, 'parts' => 0, 'ca' => 0, 'commandes' => 0, 'jours' => [], 'epuise' => 0, 'type' => $l['type']];
            $plats[$k]['parts'] += $l['qte']; $plats[$k]['ca'] += prix_nombre($l['prix']) * $l['qte']; $plats[$k]['commandes']++;
            $plats[$k]['jours'][($l['jour'] ?? '') !== '' ? $l['jour'] : $r['date']] = 1;
        }
        $c = $r['client']; $parClient[$c] = ['n' => ($parClient[$c]['n'] ?? 0) + 1, 'ca' => ($parClient[$c]['ca'] ?? 0) + $ca];
    }
    foreach (liste(lire_json(EPUISES)) as $e) if ($e['date'] >= $debut && $e['date'] <= $auj && isset($plats[$e['plat']])) $plats[$e['plat']]['epuise']++;
    foreach ($plats as &$p) { $p['jours'] = count($p['jours']); $p['ca'] = round($p['ca'], 2); } unset($p);
    usort($plats, function ($a, $b) { return $b['parts'] <=> $a['parts'] ?: $b['ca'] <=> $a['ca']; });
    arsort($toujours);
    // Clients : nouveaux (première commande sur la période), fidèles (déjà venus avant), meilleurs clients
    $nouveaux = 0; $fideles = 0; $top = [];
    foreach ($parClient as $id => $x) {
        if (($premiere[$id] ?? '') >= $debut) $nouveaux++; else $fideles++;
        $i = client_par_id($clients, $id);
        $top[] = ['nom' => $i === null ? 'Client supprimé' : (client_nom($clients[$i]) ?: 'Client'), 'n' => $x['n'], 'ca' => round($x['ca'], 2)];
    }
    usort($top, function ($a, $b) { return $b['n'] <=> $a['n'] ?: $b['ca'] <=> $a['ca']; });
    // Messages : inscriptions et désinscriptions sur la période, SMS envoyés
    $lettre = ['inscrits' => count(lettre_inscrits($clients)), 'nouveaux' => 0, 'desabo' => 0, 'sms_ok' => 0, 'clients' => count($clients)];
    foreach ($clients as $c) foreach ($c['lettre']['historique'] ?? [] as $h) if (substr($h['date'], 0, 10) >= $debut) $lettre[$h['statut'] === 'oui' ? 'nouveaux' : 'desabo']++;
    if (function_exists('sms_config')) foreach (sms_config()['envois'] as $e) if (substr($e['date'], 0, 10) >= $debut && strpos($e['mode'], 'essai') !== 0) $lettre['sms_ok'] += (int)$e['ok'];
    $T['ca'] = round($T['ca'], 2); $A['ca'] = round($A['ca'], 2);
    return ['total' => $T, 'avant' => $A, 'jours' => array_values($jours), 'plats' => array_slice($plats, 0, 12), 'semaine' => $semaine,
            'clients' => ['uniques' => count($parClient), 'nouveaux' => $nouveaux, 'fideles' => $fideles, 'top' => array_slice($top, 0, 5)],
            'toujours' => array_slice(array_map(function ($k, $v) { return ['nom' => $k, 'parts' => $v]; }, array_keys($toujours), $toujours), 0, 5),
            'avenir' => ['commandes' => $avenir['commandes'], 'ca' => round($avenir['ca'], 2)], 'lettre' => $lettre, 'premiere' => $resas ? min(array_column($resas, 'date')) : null];
}

// Parts réservées d'une offre (offre spéciale ou produit d'événement) entre deux dates
function offre_parts($type, $nom, $du, $au) {
    static $resas = null; $resas = $resas ?? resas_charger();
    $n = 0;
    foreach ($resas as $r) if ($r['date'] >= $du && $r['date'] <= $au) foreach ($r['lignes'] as $l) if ($l['type'] === $type && $l['nom'] === $nom) $n += (int)$l['qte'];
    return $n;
}
// Événements réservables en ligne : activés, avec une date à partir de demain (la veille jusqu'à l'heure limite),
// et avant la date limite de réservation s'il y en a une
function commande_evts($d) {
    $c = commande_config(); $out = [];
    $demain = date('Y-m-d', strtotime('tomorrow'));
    foreach ($d['evenements'] as $i => $e) {
        if (!$e['actif'] || $e['date'] === '' || !reservation_ouverte($e)) continue;
        if (!array_filter($e['items'], function ($it) { return !$it['cat']; })) continue;
        // jours de retrait possibles : ceux de l'événement, à partir de demain (la veille jusqu'à l'heure limite)
        $jours = [];
        foreach (evt_jours($e) as $j) if ($j > $demain || ($j === $demain && date('H:i') < $c['limite'])) $jours[] = ['date' => $j, 'libelle' => date_fr($j, true)];
        if (!$jours) continue;
        $items = [];
        foreach ($e['items'] as $k => $it) $items[] = ['k' => $k, 'nom' => $it['nom'], 'prix' => $it['prix'], 'epuise' => $it['epuise'], 'cat' => $it['cat'],
            'forte' => !$it['cat'] && !$it['epuise'] && offre_parts('evenement', $it['nom'], $e['date'], evt_fin($e)) >= FORTE_OFFRE];
        $out[] = ['i' => $i, 'titre' => $e['titre'], 'date' => $jours[0]['date'], 'libelle' => $jours[0]['libelle'], 'jours' => $jours, 'quand' => evt_quand($e), 'couleur' => $e['couleur'], 'items' => $items];
    }
    return $out;
}

// ---- Import de contacts (bouton Importer de l'onglet Clients) ----
// À appeler sous verrou(). Accord acquis : inscription au menu du lundi, jamais pour quelqu'un qui l'avait refusé.
function clients_importer($contacts, $accord, $source) {
    $l = clients_charger(); $n = ['ajoutes' => 0, 'maj' => 0, 'ignores' => 0, 'refus' => 0, 'inscrits' => 0];
    foreach (array_slice($contacts, 0, 2000) as $x) {
        $email = email_ok($x['email'] ?? '');
        if ($email === '') { $n['ignores']++; continue; }
        $ci = ['prenom' => txt($x['prenom'] ?? '', 40), 'nom' => txt($x['nom'] ?? '', 60), 'tel' => (string)($x['tel'] ?? ''), 'email' => $email];
        $i = client_index($l, $ci['tel'], $email);
        if ($i === null) { $l[] = client_clean($ci); $i = count($l) - 1; $n['ajoutes']++; }
        else {
            $m = $l[$i];
            foreach (['prenom', 'nom', 'tel'] as $k) if (trim((string)$m[$k]) === '' && trim($ci[$k]) !== '') $m[$k] = $ci[$k];
            $l[$i] = client_clean($m, $l[$i]); $n['maj']++;
        }
        if ($accord) {
            if ($l[$i]['lettre']['statut'] === 'non') $n['refus']++;
            elseif ($l[$i]['lettre']['statut'] !== 'oui') { client_lettre($l[$i], true, $source); $n['inscrits']++; }
        }
    }
    return clients_enregistrer($l) ? $n : null;
}
