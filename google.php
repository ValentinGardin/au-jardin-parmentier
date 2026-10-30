<?php
// Avis Google : note moyenne, nombre d'avis et jusqu'à 5 avis, récupérés auprès de Google (Places API « New »)
// une fois par jour au maximum. La clé d'accès reste sur le serveur (data/google.json), jamais dans le navigateur.
const GOOGLE_CONFIG = DATA . '/google.json';
const GOOGLE_CACHE = DATA . '/google-avis.json';
const GOOGLE_DUREE = 86400;   // une mise à jour par jour

function google_config() {
    $c = is_file(GOOGLE_CONFIG) ? json_decode((string)file_get_contents(GOOGLE_CONFIG), true) : null;
    $c = is_array($c) ? $c : [];
    return [
        'cle' => (string)($c['cle'] ?? ''),
        'place_id' => (string)($c['place_id'] ?? ''),
        'nom' => (string)($c['nom'] ?? ''),
        'adresse' => (string)($c['adresse'] ?? ''),
        'afficher_note' => !array_key_exists('afficher_note', $c) || !empty($c['afficher_note']),
        'afficher_avis' => !empty($c['afficher_avis']),
        'note_min' => max(1, min(5, (int)($c['note_min'] ?? 4))),
        'base' => (string)($c['base'] ?? 'https://places.googleapis.com/v1'),   // modifiable pour les tests uniquement
    ];
}

// Appel à l'API Google ; renvoie [données, erreur]
function google_appel($methode, $chemin, $champs, $corps = null) {
    $c = google_config();
    if ($c['cle'] === '') return [null, 'Aucune clé Google enregistrée.'];
    $entetes = ['X-Goog-Api-Key: ' . $c['cle'], 'X-Goog-FieldMask: ' . $champs, 'Content-Type: application/json'];
    $url = rtrim($c['base'], '/') . $chemin;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => $entetes, CURLOPT_CUSTOMREQUEST => $methode]);
        if ($corps !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corps));
        $rep = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => $methode, 'header' => implode("\r\n", $entetes), 'content' => $corps !== null ? json_encode($corps) : null, 'timeout' => 8, 'ignore_errors' => true]]);
        $rep = @file_get_contents($url, false, $ctx);
        $code = 0; $err = $rep === false ? 'connexion impossible' : '';
        foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) $code = (int)$m[1];
    }
    if ($rep === false || $rep === null) return [null, 'Google ne répond pas (' . $err . '). Réessayez plus tard.'];
    $j = json_decode((string)$rep, true);
    if ($code >= 400 || !is_array($j)) {
        $msg = is_array($j) ? (string)($j['error']['message'] ?? '') : '';
        if ($code === 403 || $code === 401 || stripos($msg, 'API key') !== false) return [null, 'Google refuse la clé : vérifiez qu’elle est bien copiée, que « Places API (New) » est activée et que la facturation est configurée. (' . $msg . ')'];
        return [null, 'Erreur Google ' . $code . ($msg ? ' : ' . $msg : '')];
    }
    return [$j, null];
}

// Recherche du commerce par son nom (pour trouver son identifiant Google)
function google_recherche($texte) {
    [$j, $e] = google_appel('POST', '/places:searchText', 'places.id,places.displayName,places.formattedAddress,places.rating,places.userRatingCount',
        ['textQuery' => (string)$texte, 'languageCode' => 'fr', 'regionCode' => 'FR']);
    if ($e) return [null, $e];
    $out = [];
    foreach ($j['places'] ?? [] as $p) $out[] = ['id' => $p['id'] ?? '', 'nom' => $p['displayName']['text'] ?? '', 'adresse' => $p['formattedAddress'] ?? '', 'note' => $p['rating'] ?? null, 'nombre' => $p['userRatingCount'] ?? 0];
    return [$out, null];
}

// Récupère la note et les avis, et les garde en mémoire 24 h
function google_actualiser() {
    $c = google_config();
    if ($c['place_id'] === '') return [null, 'Choisissez d’abord votre commerce.'];
    [$j, $e] = google_appel('GET', '/places/' . rawurlencode($c['place_id']) . '?languageCode=fr&regionCode=FR', 'displayName,rating,userRatingCount,reviews,googleMapsUri,googleMapsLinks');
    $ancien = google_cache();
    if ($e) {
        $ancien['erreur'] = $e; $ancien['essai'] = time();
        json_save(GOOGLE_CACHE, $ancien);
        return [null, $e];
    }
    $avis = [];
    foreach ($j['reviews'] ?? [] as $r) {
        $texte = trim((string)($r['text']['text'] ?? $r['originalText']['text'] ?? ''));
        $avis[] = [
            'auteur' => (string)($r['authorAttribution']['displayName'] ?? 'Client Google'),
            'lien' => (string)($r['authorAttribution']['uri'] ?? ''),
            'note' => (int)($r['rating'] ?? 0),
            'texte' => $texte,
            'quand' => (string)($r['relativePublishTimeDescription'] ?? ''),
            'date' => substr((string)($r['publishTime'] ?? ''), 0, 10),
        ];
    }
    $d = [
        'nom' => (string)($j['displayName']['text'] ?? ''),
        'note' => isset($j['rating']) ? round((float)$j['rating'], 1) : null,
        'nombre' => (int)($j['userRatingCount'] ?? 0),
        'lien' => (string)($j['googleMapsLinks']['reviewsUri'] ?? $j['googleMapsUri'] ?? ''),
        'lien_avis' => (string)($j['googleMapsLinks']['writeAReviewUri'] ?? ''),
        'avis' => $avis,
        'maj' => time(), 'essai' => time(), 'erreur' => '',
    ];
    json_save(GOOGLE_CACHE, $d);
    return [$d, null];
}

function google_cache() {
    $d = is_file(GOOGLE_CACHE) ? json_decode((string)file_get_contents(GOOGLE_CACHE), true) : null;
    return is_array($d) ? $d : [];
}

// Données pour la page d'accueil : mises à jour au plus une fois par jour (une seule tentative par heure en cas d'erreur)
function google_avis_site() {
    $c = google_config();
    if ($c['cle'] === '' || $c['place_id'] === '' || (!$c['afficher_note'] && !$c['afficher_avis'])) return null;
    $d = google_cache();
    $vieux = time() - (int)($d['maj'] ?? 0) > GOOGLE_DUREE;
    $attente = time() - (int)($d['essai'] ?? 0) < 3600;
    if ($vieux && !$attente) {
        try { [$n] = google_actualiser(); if ($n) $d = $n; else $d = google_cache(); } catch (Throwable $e) { /* le site s'affiche quand même */ }
    }
    if (empty($d['nombre'])) return null;
    $d['avis'] = $c['afficher_avis'] ? array_values(array_filter($d['avis'] ?? [], function ($a) use ($c) { return $a['note'] >= $c['note_min'] && $a['texte'] !== ''; })) : [];
    $d['afficher_note'] = $c['afficher_note'];
    return $d;
}
