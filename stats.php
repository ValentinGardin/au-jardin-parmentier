<?php
// Mesure d'audience maison, sans cookie et anonyme :
// - aucune adresse IP n'est enregistrée, seulement une empreinte qui change chaque jour (on ne peut pas suivre quelqu'un d'un jour à l'autre) ;
// - les robots et vos propres visites quand vous êtes connecté au back-office ne sont pas comptés ;
// - un fichier par jour dans data/stats/, effacé automatiquement après 13 mois.
const STATS = DATA . '/stats';
const STATS_ACTIONS = ['tel', 'itin', 'mail', 'carte', 'evt', 'fb', 'insta', 'avis', 'lettre'];

function stats_dossier() {
    if (!is_dir(STATS) && !@mkdir(STATS, 0755, true)) return false;
    return true;
}
function stats_robot() {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return $ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|headless|lighthouse|pingdom|monitor|curl|wget|python|java\/|httpclient|scan/i', $ua);
}
function stats_visiteur() {
    $f = STATS . '/.secret';
    $s = is_file($f) ? (string)file_get_contents($f) : '';
    if (strlen($s) < 32) { $s = bin2hex(random_bytes(32)); @file_put_contents($f, $s); }
    return substr(hash('sha256', $s . date('Y-m-d') . ($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 12);
}
function stats_ecrire($ligne) {
    if (stats_robot() || isset($_COOKIE['jp_admin']) || !stats_dossier()) return;
    $ligne = ['h' => (int)date('G'), 'v' => stats_visiteur()] + $ligne;
    @file_put_contents(STATS . '/' . date('Y-m-d') . '.log', json_encode($ligne, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    if (mt_rand(1, 200) === 1) foreach (glob(STATS . '/*.log') ?: [] as $f) if (basename($f, '.log') < date('Y-m-d', strtotime('-400 days'))) @unlink($f);
}

// Visite de la page d'accueil (appelé par index.php)
function stats_vue() {
    try {
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $hote = strtolower((string)parse_url($ref, PHP_URL_HOST));
        $ici = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $utm = strtolower((string)($_GET['utm_source'] ?? ''));
        $src = $utm ?: $hote;
        if ($src === '' || $hote === $ici) $s = 'direct';
        elseif (preg_match('/google|bing|qwant|ecosia|duckduckgo|yahoo|lilo/', $src)) $s = 'recherche';
        elseif (preg_match('/facebook|fb\.|instagram|messenger/', $src)) $s = 'facebook';
        elseif (preg_match('/maps|goo\.gl|share\.google/', $src)) $s = 'maps';
        else $s = 'autre';
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $app = preg_match('/ipad|tablet/i', $ua) ? 'tablette' : (preg_match('/mobi|android|iphone/i', $ua) ? 'mobile' : 'ordinateur');
        stats_ecrire(['e' => 'vue', 's' => $s, 'a' => $app] + ($s === 'autre' ? ['r' => cut(preg_replace('/^www\./', '', $hote), 60)] : []));
    } catch (Throwable $e) { /* les statistiques ne doivent jamais bloquer le site */ }
}

// Synthèse pour le tableau de bord : $jours jours jusqu'à aujourd'hui, comparés à la période précédente
function stats_synthese($jours) {
    $jours = max(1, min(400, (int)$jours));
    $vide = function () { return ['visiteurs' => 0, 'vues' => 0] + array_fill_keys(STATS_ACTIONS, 0); };
    $out = ['jours' => [], 'total' => $vide(), 'avant' => $vide(), 'sources' => [], 'appareils' => [], 'sites' => [],
            'cartes' => [], 'evenements' => [], 'heures' => array_fill(0, 24, 0), 'semaine' => array_fill(0, 7, 0), 'premier' => null];
    $files = glob(STATS . '/*.log') ?: [];
    if ($files) { sort($files); $out['premier'] = basename($files[0], '.log'); }
    for ($i = 2 * $jours - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $actuel = $i < $jours;
        $j = $vide(); $vus = [];
        $f = STATS . '/' . $d . '.log';
        if (is_file($f)) {
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
                $x = json_decode($l, true);
                if (!is_array($x) || empty($x['e'])) continue;
                $e = $x['e'];
                if ($e === 'vue') {
                    $j['vues']++;
                    if (isset($vus[$x['v'] ?? ''])) continue;
                    $vus[$x['v'] ?? ''] = 1;
                    $j['visiteurs']++;
                    if ($actuel) {
                        $s = $x['s'] ?? 'direct'; $out['sources'][$s] = ($out['sources'][$s] ?? 0) + 1;
                        $a = $x['a'] ?? 'ordinateur'; $out['appareils'][$a] = ($out['appareils'][$a] ?? 0) + 1;
                        if (!empty($x['r'])) $out['sites'][$x['r']] = ($out['sites'][$x['r']] ?? 0) + 1;
                        $out['heures'][(int)($x['h'] ?? 0) % 24]++;
                        $out['semaine'][(int)date('N', strtotime($d)) - 1]++;
                    }
                } elseif (in_array($e, STATS_ACTIONS, true)) {
                    $j[$e]++;
                    if ($actuel && $e === 'carte' && !empty($x['x'])) $out['cartes'][$x['x']] = ($out['cartes'][$x['x']] ?? 0) + 1;
                    if ($actuel && $e === 'evt' && !empty($x['x'])) $out['evenements'][$x['x']] = ($out['evenements'][$x['x']] ?? 0) + 1;
                }
            }
        }
        foreach ($j as $k => $v) $out[$actuel ? 'total' : 'avant'][$k] += $v;
        if ($actuel) $out['jours'][] = ['d' => $d, 'visiteurs' => $j['visiteurs'], 'vues' => $j['vues'], 'actions' => $j['tel'] + $j['evt'] + $j['itin'] + $j['mail']];
    }
    foreach (['sources', 'appareils', 'sites', 'cartes', 'evenements'] as $k) arsort($out[$k]);
    $out['sites'] = array_slice($out['sites'], 0, 6, true);
    return $out;
}
