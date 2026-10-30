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
        $l = ['type' => in_array($l['type'] ?? '', ['plat', 'special', 'autre'], true) ? $l['type'] : 'autre',
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
function mail_envoyer($a, $sujet, $html, $texte, $desabo = '') {
    $b = 'jp' . bin2hex(random_bytes(8));
    $entetes = "From: Au Jardin Parmentier <" . EXPEDITEUR . ">\r\nReply-To: " . EXPEDITEUR . "\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$b\"";
    if ($desabo !== '') $entetes .= "\r\nList-Unsubscribe: <$desabo>\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click";
    $corps = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texte))
           . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$b--";
    return @mail($a, '=?UTF-8?B?' . base64_encode($sujet) . '?=', $corps, $entetes);
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
                $out[$date] = ['date' => $date, 'jour' => $nomJour, 'forte' => ($parts[$date] ?? 0) >= FORTE_DEMANDE, 'libelle' => date_fr($date, true), 'plat' => ['nom' => $j['nom'], 'desc' => $j['desc'], 'prix' => $j['prix']],
                               'special' => special_le_jour($s['special'], $nomJour) ? ['nom' => $s['special']['nom'], 'prix' => $s['special']['prix'], 'titre' => $s['special']['titre'], 'lignes' => $s['special']['lignes']] : null];
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
