<?php
// Reçoit les clics utiles du site (appel, itinéraire, e-mail, carte consultée…) pour le tableau de bord
require __DIR__ . '/lib.php';
require __DIR__ . '/stats.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $e = (string)($_POST['e'] ?? '');
    if (in_array($e, STATS_ACTIONS, true)) {
        $x = txt($_POST['x'] ?? '', 80);
        stats_ecrire(['e' => $e] + ($x !== '' ? ['x' => $x] : []));
    }
}
http_response_code(204);
