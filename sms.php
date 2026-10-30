<?php
// SMS par le service SMS d'OVH : rappel « votre commande vous attend » pour les commandes du jour non récupérées.
// Les clés d'accès restent sur le serveur (data/sms.json), jamais dans le navigateur.
const SMS_CONFIG = DATA . '/sms.json';
const SMS_MESSAGE = "Bonjour {prenom}, petit rappel : votre commande ({plats}) vous attend au Jardin Parmentier, 9 place Parmentier. Merci et à tout de suite ! 09 50 17 39 62";

function sms_config() {
    $c = lire_json(SMS_CONFIG);
    return [
        'app_key' => (string)($c['app_key'] ?? ''), 'app_secret' => (string)($c['app_secret'] ?? ''), 'consumer_key' => (string)($c['consumer_key'] ?? ''),
        'service' => (string)($c['service'] ?? ''),
        'expediteur' => (string)($c['expediteur'] ?? ''),          // nom d'expéditeur validé chez OVH ; vide = un numéro OVH
        'auto' => !empty($c['auto']),
        'heure' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($c['heure'] ?? '')) ? $c['heure'] : '17:30',
        'cible' => ($c['cible'] ?? '') === 'accord' ? 'accord' : 'tous',   // tous (sauf ceux qui refusent) ou seulement ceux qui ont accepté
        'message' => trim((string)($c['message'] ?? '')) !== '' ? (string)$c['message'] : SMS_MESSAGE,
        'tel_essai' => (string)($c['tel_essai'] ?? ''),
        'envois' => liste($c['envois'] ?? []),
        'fait' => (string)($c['fait'] ?? ''),                      // dernier jour où l'envoi automatique a eu lieu
        'base' => (string)($c['base'] ?? 'https://eu.api.ovh.com/1.0'),   // modifiable pour les tests uniquement
    ];
}
function sms_modifier($modifs) { return json_save(SMS_CONFIG, array_merge(lire_json(SMS_CONFIG), $modifs)); }
function sms_pret($c) { return $c['app_key'] !== '' && $c['app_secret'] !== '' && $c['consumer_key'] !== '' && $c['service'] !== ''; }
function sms_noter($e) { $c = lire_json(SMS_CONFIG); $c['envois'] = array_slice(array_merge(liste($c['envois'] ?? []), [$e]), -30); json_save(SMS_CONFIG, $c); }

// Appel signé à l'API OVH ; renvoie [données, erreur]
function ovh_appel($methode, $chemin, $corps = null, $c = null) {
    $c = $c ?: sms_config();
    $url = rtrim($c['base'], '/') . $chemin;
    $body = $corps === null ? '' : json_encode($corps, JSON_UNESCAPED_UNICODE);
    $t = (string)time();
    $sig = '$1$' . sha1($c['app_secret'] . '+' . $c['consumer_key'] . '+' . $methode . '+' . $url . '+' . $body . '+' . $t);
    $entetes = ['X-Ovh-Application: ' . $c['app_key'], 'X-Ovh-Consumer: ' . $c['consumer_key'], 'X-Ovh-Timestamp: ' . $t, 'X-Ovh-Signature: ' . $sig, 'Content-Type: application/json'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $entetes, CURLOPT_CUSTOMREQUEST => $methode]);
        if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $rep = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => $methode, 'header' => implode("\r\n", $entetes), 'content' => $body, 'timeout' => 10, 'ignore_errors' => true]]);
        $rep = @file_get_contents($url, false, $ctx); $code = 0;
        foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) $code = (int)$m[1];
    }
    if ($rep === false || $rep === null) return [null, 'OVH ne répond pas. Réessayez dans un instant.'];
    $j = json_decode((string)$rep, true);
    if ($code >= 400) {
        $msg = is_array($j) ? (string)($j['message'] ?? '') : '';
        if ($code === 403 || $code === 401) return [null, 'OVH refuse les clés : vérifiez qu’elles sont bien copiées et qu’elles ont les droits sur /sms. (' . $msg . ')'];
        return [null, 'Erreur OVH ' . $code . ($msg ? ' : ' . $msg : '')];
    }
    return [$j, null];
}

// Numéro au format international : 06 12 34 56 78 → +33612345678 (portables uniquement : 06 / 07)
function tel_international($t) {
    $d = tel_chiffres($t);
    return preg_match('/^0[67]\d{8}$/', $d) ? '+33' . substr($d, 1) : '';
}
// Le texte n'utilise-t-il que l'alphabet SMS standard (160 caractères par SMS, sinon 70) ?
function sms_gsm($t) {
    return (bool)preg_match('/^[@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,\-.\/0-9:;<=>?¡A-ZÄÖÑÜ§¿a-zäöñüà^{}\\\[~\]|€]*$/u', $t);
}
function sms_texte($modele, $r, $c) {
    $plats = implode(', ', array_map(function ($l) { return ($l['qte'] > 1 ? $l['qte'] . ' ' : '') . $l['nom']; }, $r['lignes']));
    return trim(strtr($modele, ['{prenom}' => $c['prenom'] ?: ($c['nom'] ?: ''), '{nom}' => $c['nom'], '{plats}' => $plats]));
}
function sms_envoyer($tel, $texte, $c = null) {
    $c = $c ?: sms_config();
    if (!sms_pret($c)) return [null, 'Le service SMS n’est pas encore connecté.'];
    $n = tel_international($tel);
    if ($n === '') return [null, 'Ce numéro n’est pas un portable français (06 ou 07).'];
    // message lié à une commande (pas de publicité) : pas de mention « STOP »
    $corps = ['message' => $texte, 'receivers' => [$n], 'charset' => 'UTF-8', 'coding' => sms_gsm($texte) ? '7bit' : '8bit', 'noStopClause' => true, 'priority' => 'high'];
    if ($c['expediteur'] !== '') $corps['sender'] = $c['expediteur']; else $corps['senderForResponse'] = true;
    [$j, $e] = ovh_appel('POST', '/sms/' . rawurlencode($c['service']) . '/jobs', $corps, $c);
    if ($e) return [null, $e];
    if (!empty($j['invalidReceivers'])) return [null, 'Numéro refusé par OVH.'];
    return [$j, null];
}

// Commandes à rappeler : celles du jour, pas récupérées, pas encore rappelées, avec un portable, et dont le client n'a pas refusé
function sms_a_rappeler($c, $date = null) {
    $date = $date ?: date('Y-m-d');
    $clients = clients_charger(); $out = [];
    foreach (resas_charger() as $r) {
        if ($r['date'] !== $date || $r['recupere'] || !empty($r['rappel'])) continue;
        $i = client_par_id($clients, $r['client']); if ($i === null) continue;
        $cl = $clients[$i]; $acc = $cl['sms']['statut'] ?? '';
        if ($acc === 'non' || ($c['cible'] === 'accord' && $acc !== 'oui') || tel_international($cl['tel']) === '') continue;
        $out[] = [$r, $cl];
    }
    return $out;
}
// Envoie les rappels du jour ; $qui : « automatique » ou le nom de la personne. Renvoie [nombre envoyé, erreur]
function sms_rappels($qui) {
    $c = sms_config();
    $ok = 0; $err = null;
    foreach (sms_a_rappeler($c) as [$r, $cl]) {
        [, $e] = sms_envoyer($cl['tel'], sms_texte($c['message'], $r, $cl), $c);
        if ($e) { $err = $e; continue; }
        $ok++;
        verrou(function () use ($r, $qui) {
            $l = resas_charger();
            foreach ($l as &$x) if ($x['id'] === $r['id']) $x['rappel'] = date('Y-m-d H:i') . ' · ' . $qui;
            unset($x);
            resas_enregistrer($l);
        });
    }
    if ($ok || $err) sms_noter(['date' => date('Y-m-d H:i'), 'mode' => $qui, 'ok' => $ok, 'erreur' => (string)$err]);
    return [$ok, $err];
}
// Envoi automatique : à partir de l'heure choisie, une fois par jour, dès qu'une page du site ou du back-office est vue
function sms_auto() {
    $c = sms_config();
    if (!$c['auto'] || !sms_pret($c) || date('H:i') < $c['heure'] || $c['fait'] === date('Y-m-d')) return;
    $go = verrou(function () {
        if (sms_config()['fait'] === date('Y-m-d')) return false;
        sms_modifier(['fait' => date('Y-m-d')]);
        return true;
    });
    if (!$go) return;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        @set_time_limit(120); ignore_user_abort(true);
        sms_rappels('automatique');
    });
}
// Clé secrète de l'adresse /rappels (créée au premier besoin)
function sms_cle_cron() {
    $c = lire_json(SMS_CONFIG);
    if (empty($c['cle_cron'])) { $c['cle_cron'] = bin2hex(random_bytes(16)); json_save(SMS_CONFIG, $c); }
    return $c['cle_cron'];
}
