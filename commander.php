<?php
// Réservation en ligne depuis la page d'accueil (activée ou non dans le back-office, onglet Réservations).
// Uniquement à partir du lendemain : pour le jour même, les clients appellent la boutique.
require __DIR__ . '/lib.php';
require __DIR__ . '/clients.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
function fin($code, $d) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
const TEL_BOUTIQUE = '09 50 17 39 62';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fin(405, ['erreur' => 'Méthode non autorisée.']);
if (!commande_config()['actif']) fin(403, ['erreur' => 'La réservation en ligne est fermée pour le moment. Appelez-nous au ' . TEL_BOUTIQUE . '.']);
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) fin(400, ['erreur' => 'Demande invalide.']);

// Pièges à robots : champ invisible rempli, ou formulaire envoyé en moins de 3 secondes
if (($in['site_web'] ?? '') !== '' || time() - (int)($in['t'] ?? 0) < 3) fin(400, ['erreur' => 'La réservation n’a pas pu être enregistrée. Réessayez, ou appelez-nous au ' . TEL_BOUTIQUE . '.']);

if (isset($in['evt'])) {
    // Réservation pour un événement (Beaujolais nouveau, Noël…) : à récupérer le jour de l'événement
    $evt = null;
    foreach (commande_evts(site_load()) as $e) if ($e['i'] === (int)$in['evt']) $evt = $e;
    if (!$evt) fin(400, ['erreur' => 'Les réservations en ligne pour cet événement sont closes. Appelez-nous au ' . TEL_BOUTIQUE . '.']);
    // jour de retrait choisi parmi les jours de l'événement (ex. 24 ou 25 décembre)
    $jour = ['date' => in_array($in['date'] ?? '', array_column($evt['jours'], 'date'), true) ? $in['date'] : $evt['date']];
    $lignes = [];
    $choix = is_array($in['items'] ?? null) ? $in['items'] : [];
    foreach ($evt['items'] as $it) {
        foreach ($it['v'] as $v) {   // produits, et formules (catégories à prix) ; le contenu d'une formule n'a pas de variante
            $q = (int)($choix[$v['cle']] ?? 0);
            // au kilo : un poids en grammes (par 50 g, 5 kg au plus) ; sinon une quantité (20 au plus)
            $q = $v['kg'] ? max(0, min(5000, intdiv($q, 50) * 50)) : max(0, min(20, $q));
            if (!$q) continue;
            if ($it['epuise']) fin(400, ['erreur' => '« ' . $it['nom'] . ' » vient d’être épuisé, désolé ! Retirez-le de votre commande.']);
            $lignes[] = evt_ligne($it, $v, $q);
        }
    }
} else {
    // Le jour choisi doit encore être réservable
    $jour = null;
    foreach (commande_jours(site_load()) as $j) if ($j['date'] === ($in['date'] ?? '')) $jour = $j;
    if (!$jour) fin(400, ['erreur' => 'Ce jour n’est plus réservable en ligne (les réservations se font au plus tard la veille). Appelez-nous au ' . TEL_BOUTIQUE . ', nous ferons notre possible !', 'jours' => commande_jours(site_load())]);

    $lignes = [];
    $q = max(0, min(20, (int)($in['plat'] ?? 0)));
    if ($q) $lignes[] = ['type' => 'plat', 'nom' => $jour['plat']['nom'], 'prix' => $jour['plat']['prix'], 'qte' => $q];
    $q = max(0, min(20, (int)($in['special'] ?? 0)));
    if ($q && $jour['special']) $lignes[] = ['type' => 'special', 'nom' => $jour['special']['nom'], 'prix' => $jour['special']['prix'], 'qte' => $q];
    // Plats d'autres jours (avant le jour de retrait, eux aussi réservables en ligne) : mis de côté ce jour-là
    $dispo = [];
    foreach (commande_jours(site_load()) as $j) $dispo[$j['date']] = $j;
    foreach ((is_array($in['autres'] ?? null) ? $in['autres'] : []) as $dj => $q) {
        $q = max(0, min(20, (int)$q));
        if (!$q) continue;
        if (!isset($dispo[$dj]) || $dj >= $jour['date'] || $dj < date('Y-m-d', strtotime('monday this week', strtotime($jour['date'])))) fin(400, ['erreur' => 'Un des plats d’un autre jour n’est plus réservable. Retirez-le, ou appelez-nous au ' . TEL_BOUTIQUE . '.']);
        $lignes[] = ['type' => 'plat', 'nom' => $dispo[$dj]['plat']['nom'], 'prix' => $dispo[$dj]['plat']['prix'], 'qte' => $q, 'jour' => $dj];
    }
}
if (!$lignes) fin(400, ['erreur' => 'Choisissez au moins un plat.']);

$ci = ['prenom' => txt($in['prenom'] ?? '', 40), 'nom' => txt($in['nom'] ?? '', 60), 'tel' => (string)($in['tel'] ?? ''), 'email' => (string)($in['email'] ?? '')];
if ($ci['prenom'] === '' || $ci['nom'] === '') fin(400, ['erreur' => 'Indiquez votre prénom et votre nom.', 'champ' => $ci['prenom'] === '' ? 'prenom' : 'nom']);
// téléphone conseillé mais pas obligatoire ; s'il est donné, il doit être complet
if (trim($ci['tel']) !== '' && strlen(tel_chiffres($ci['tel'])) !== 10) fin(400, ['erreur' => 'Ce numéro de téléphone semble incomplet (10 chiffres).', 'champ' => 'tel']);
if (trim($ci['email']) === '') fin(400, ['erreur' => 'Indiquez votre adresse e-mail : vous y recevrez le récapitulatif de votre commande.', 'champ' => 'email']);
if (email_ok($ci['email']) === '') fin(400, ['erreur' => 'Cette adresse e-mail ne semble pas valide.', 'champ' => 'email']);
if (limite_ip('commande', 8)) fin(429, ['erreur' => 'Trop de réservations depuis cette connexion. Appelez-nous au ' . TEL_BOUTIQUE . '.']);

$res = verrou(function () use ($ci, $in, $jour, $lignes) {
    $clients = clients_charger();
    $i = client_index($clients, $ci['tel'], $ci['email']);
    if ($i === null) { $clients[] = client_clean($ci); $i = count($clients) - 1; }
    else { $m = $clients[$i]; foreach ($ci as $k => $v) if (trim($v) !== '') $m[$k] = $v; $clients[$i] = client_clean($m, $clients[$i]); }
    if (!empty($in['lettre']) && $clients[$i]['email'] !== '') client_lettre($clients[$i], true, 'site');
    $l = resas_charger();
    $r = resa_clean(['client' => $clients[$i]['id'], 'date' => $jour['date'], 'lignes' => $lignes, 'note' => $in['note'] ?? '', 'source' => 'site'], null, 'Site internet');
    $r['num'] = resa_num_suivant($l);
    $l[] = $r;
    if (!clients_enregistrer($clients) || !resas_enregistrer($l)) return null;
    return [$r, $clients[$i]];
});
if (!$res) fin(500, ['erreur' => 'La réservation n’a pas pu être enregistrée. Appelez-nous au ' . TEL_BOUTIQUE . '.']);
[$r, $c] = $res;

// E-mails : récapitulatif au client, et alerte à la boutique (info@…)
[$sujet, $html, $texte] = mail_commande($r, $c, 'client');
$mailOk = mail_envoyer($c['email'], $sujet, $html, $texte);
[$sujet, $html, $texte] = mail_commande($r, $c, 'boutique');
mail_envoyer(EXPEDITEUR, $sujet, $html, $texte);
$total = array_reduce($r['lignes'], function ($t, $l) { return $t + prix_nombre($l['prix']) * $l['qte']; }, 0);
$quand = date_fr($r['date'], true);

fin(200, ['ok' => true, 'num' => $r['num'], 'quand' => $quand, 'total' => $total, 'email' => $mailOk ? $c['email'] : '']);
