<?php
// Rappels SMS sans attendre une visite : à lancer par une tâche planifiée (cron) vers l'heure choisie.
// Chez OVH : Hébergement → Tâches planifiées → Cron → script « JARDINPARMENTIER/rappels.php ».
// Ou par un service en ligne qui appelle https://…/rappels?cle=… (la clé est affichée dans le back-office).
require __DIR__ . '/lib.php';
require __DIR__ . '/clients.php';
require __DIR__ . '/sms.php';
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $cle = (string)(lire_json(SMS_CONFIG)['cle_cron'] ?? '');
    if ($cle === '' || !hash_equals($cle, (string)($_GET['cle'] ?? ''))) { http_response_code(404); exit('Introuvable.'); }
}
lettre_auto(site_load());
sms_auto();
echo "ok\n";
