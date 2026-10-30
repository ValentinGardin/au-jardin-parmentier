<?php
// Affiche A4 du menu de la semaine, à imprimer depuis le back-office (bouton « Imprimer l'affiche »)
require __DIR__ . '/lib.php';
header('X-Robots-Tag: noindex');
$sem = site_load()['semaine'];
$sem['jours'] = jours_visibles($sem['jours']);
$conges = $sem['conges']['actif'];
$sp = $sem['special'];
$mots = preg_split('/\s+/u', trim($sem['titre']), 2);
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Affiche · <?= h($sem['titre']) ?></title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="preload" href="fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="fonts/baloo2-latin.woff2" as="font" type="font/woff2" crossorigin>
<link href="fonts/fonts.css" rel="stylesheet">
<style>
  @page{size:A4;margin:0}
  :root{--violet:#700954;--orange:#d9641e;--encre:#1d171b;--gris:#5f575d}
  *{box-sizing:border-box}
  body{margin:0;background:#e9e5e0;font-family:Inter,system-ui,sans-serif;color:var(--encre);-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .outils{position:sticky;top:0;display:flex;gap:.6rem;justify-content:center;padding:.8rem;background:#fff;border-bottom:1px solid #ddd}
  .outils button,.outils a{font:600 .95rem Inter,sans-serif;padding:.7rem 1.2rem;border-radius:10px;border:1px solid #ddd;background:#fff;color:var(--encre);text-decoration:none;cursor:pointer}
  .outils button{background:var(--violet);border-color:var(--violet);color:#fff}
  .page{width:210mm;min-height:297mm;margin:1.5rem auto;background:#fff;padding:16mm 16mm 12mm;display:flex;flex-direction:column;box-shadow:0 10px 40px rgba(0,0,0,.15)}
  header{text-align:center}
  header img{width:22mm;margin:0 auto 4mm;display:block}
  h1{margin:0;font:800 23mm/0.85 "Baloo 2",sans-serif;color:var(--violet);text-transform:uppercase;letter-spacing:-.01em}
  h1 span{display:block;font-weight:600;font-size:11mm;line-height:1.1;margin-top:1mm}
  .adr{margin:4mm 0 0;font-size:3.6mm}
  .adr b{color:var(--violet)}
  .quand{margin:3mm 0 0;font-size:4.4mm;font-weight:500}
  .jours{flex:1;display:flex;flex-direction:column;justify-content:center;margin:6mm 0 4mm}
  .jour{display:grid;grid-template-columns:52mm 1fr auto;gap:6mm;align-items:center;padding:6mm 2mm;border-top:.9mm solid var(--violet)}
  .jour:last-child{border-bottom:.9mm solid var(--violet)}
  .jour b{font:800 10mm/1 "Baloo 2",sans-serif;color:var(--violet);text-transform:uppercase}
  .jour strong{display:block;font-size:4.4mm;line-height:1.3}
  .jour span span{font-size:4mm;color:var(--gris)}
  .jour em{font:700 6mm "Baloo 2",sans-serif;font-style:normal;color:var(--violet);white-space:nowrap}
  .jour.ferme b{color:var(--gris)}
  .jour.ferme strong{color:var(--violet)}
  .jour em.badge{font:600 3.2mm Inter,sans-serif;text-transform:uppercase;letter-spacing:.06em;color:var(--gris);border:.3mm solid var(--gris);border-radius:5mm;padding:1.2mm 3mm}
  .conges{flex:1;display:flex;flex-direction:column;justify-content:center;text-align:center;margin:8mm 0;padding:14mm 12mm;border-radius:6mm;background:#f6ecf2}
  .conges h2{margin:0 0 5mm;font:800 14mm/1.05 "Baloo 2",sans-serif;color:var(--violet)}
  .conges p{margin:0;font-size:5.2mm;line-height:1.5;color:var(--gris)}
  .special{text-align:center}
  .special h2{margin:0;font:800 9mm/1 "Baloo 2",sans-serif;color:var(--orange);text-transform:uppercase}
  .special .nom{margin:1.5mm 0 2.5mm;font-weight:700;font-style:italic;color:var(--orange);font-size:4.4mm}
  .special ul{list-style:none;margin:0;padding:0;font-weight:600;font-size:4.2mm;line-height:1.5;color:#3a3338}
  .note{text-align:center;font-size:3.6mm;color:var(--gris);margin:4mm 0 0}
  footer{text-align:center;font-size:3.4mm;color:var(--gris);margin-top:5mm;padding-top:3mm;border-top:1px solid #eee}
  @media print{body{background:#fff}.outils{display:none}.page{margin:0;box-shadow:none;height:297mm}}
  @media screen and (max-width:820px){.page{transform-origin:top left;transform:scale(calc(100vw / 220mm));margin:1rem 0 0}}
</style>
</head>
<body>
<div class="outils"><a href="<?= ESPACE ?>/">← Retour</a><button type="button" onclick="window.print()">Imprimer ou enregistrer en PDF</button></div>
<main class="page">
  <header>
    <img src="img/logo/symbole-violet.svg" alt="">
    <h1><?= h($mots[0] ?? '') ?><?php if (!empty($mots[1])): ?><span><?= h($mots[1]) ?></span><?php endif; ?></h1>
    <p class="adr">9 place Parmentier – 80500 Montdidier – <b>Au Jardin Parmentier</b></p>
<?php if ($sem['du']): ?>    <p class="quand">semaine <?= h(periode_fr($sem['du'], $sem['au'])) ?></p>
<?php endif; ?>
  </header>
<?php if ($conges): ?>
  <section class="conges">
    <h2><?= h($sem['conges']['titre'] ?: 'Fermeture pour congés') ?></h2>
<?php if ($sem['conges']['message'] !== ''): ?>    <p><?= nl2br(h($sem['conges']['message'])) ?></p>
<?php endif; ?>
  </section>
<?php else: ?>
  <section class="jours">
<?php foreach ($sem['jours'] as $j): ?>
<?php if ($j['ferme']): ?>
    <div class="jour ferme"><b><?= h($j['jour']) ?></b><span><strong><?= h($j['motif'] ?: 'Fermé') ?></strong><?php if ($j['message'] !== ''): ?><span><?= h($j['message']) ?></span><?php endif; ?></span><em class="badge">Fermé</em></div>
<?php else: ?>
    <div class="jour"><b><?= h($j['jour']) ?></b><span><strong><?= h($j['nom']) ?></strong><?php if ($j['desc'] !== ''): ?><span><?= h($j['desc']) ?></span><?php endif; ?></span><em><?= h($j['prix']) ?></em></div>
<?php endif; endforeach; ?>
  </section>
<?php endif; ?>
<?php if (!$conges && ($sp['nom'] !== '' || $sp['lignes'])): ?>
  <section class="special">
<?php if ($sp['titre'] !== ''): ?>    <h2><?= h($sp['titre']) ?></h2>
<?php endif; ?>    <p class="nom"><?= h($sp['nom']) ?><?= $sp['prix'] !== '' ? '&nbsp;&nbsp;&nbsp;' . h($sp['prix']) : '' ?></p>
    <ul><?php foreach ($sp['lignes'] as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ul>
  </section>
<?php endif; if ($sem['note'] !== ''): ?>
  <p class="note"><?= h($sem['note']) ?></p>
<?php endif; ?>
  <footer>Réservations au 09 50 17 39 62 · lejardinparmentier.fr</footer>
</main>
</body>
</html>
