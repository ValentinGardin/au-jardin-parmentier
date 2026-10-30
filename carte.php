<?php
// Cartes traiteur à imprimer ou à enregistrer en PDF (bouton « Imprimer » de l'onglet Cartes traiteur du back-office)
// /carte-traiteur?c=0 : une carte · /carte-traiteur?c=tout : toutes les cartes, une par page
require __DIR__ . '/lib.php';
header('X-Robots-Tag: noindex');
$menus = site_load()['menus'];
$c = $_GET['c'] ?? 'tout';
$cartes = $c === 'tout' ? $menus : (isset($menus[(int)$c]) ? [$menus[(int)$c]] : []);
$titrePage = count($cartes) === 1 ? $cartes[0]['titre'] : 'Cartes traiteur';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($titrePage) ?> · Au Jardin Parmentier</title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="preload" href="fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="fonts/baloo2-latin.woff2" as="font" type="font/woff2" crossorigin>
<link href="fonts/fonts.css" rel="stylesheet">
<style>
  @page{size:A4;margin:11mm 12mm}
  :root{--violet:#700954;--violet-pale:#f6ecf2;--sable:#f2ece4;--encre:#1d171b;--gris:#5f575d;--trait:#e2d9cf}
  *{box-sizing:border-box}
  body{margin:0;background:#e9e5e0;font:400 9pt/1.35 Inter,system-ui,sans-serif;color:var(--encre);-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .outils{position:sticky;top:0;z-index:2;display:flex;gap:.6rem;justify-content:center;align-items:center;flex-wrap:wrap;padding:.8rem;background:#fff;border-bottom:1px solid #ddd;font:500 14px Inter,sans-serif}
  .outils a,.outils button{font:600 15px Inter,sans-serif;padding:.7rem 1.2rem;border-radius:10px;border:1px solid #ddd;background:#fff;color:var(--encre);text-decoration:none;cursor:pointer}
  .outils button{background:var(--violet);border-color:var(--violet);color:#fff}
  .outils span{color:var(--gris)}
  .page{width:210mm;min-height:297mm;margin:1.5rem auto;background:#fff;padding:13mm 14mm 11mm;display:flex;flex-direction:column;box-shadow:0 10px 40px rgba(0,0,0,.15)}
  .tete{display:grid;grid-template-columns:1fr auto;gap:8mm;align-items:center;padding-bottom:4.5mm;border-bottom:.8mm solid var(--violet)}
  .tete.avec-photo{grid-template-columns:1fr 46mm}
  .marque{display:flex;align-items:center;gap:3mm;font:700 9pt Inter;letter-spacing:.12em;text-transform:uppercase;color:var(--violet);margin-bottom:3mm}
  .marque img{height:11mm;width:auto}
  h1{margin:0 0 1.5mm;font:800 27pt/1 "Baloo 2",sans-serif;color:var(--violet)}
  .intro{margin:0 0 2.5mm;color:var(--gris);font-size:10pt;max-width:120mm}
  .prix{display:inline-block;margin:0 0 2mm;padding:1.5mm 4mm;border-radius:3mm;background:var(--violet-pale);color:var(--violet);font:700 14pt "Baloo 2",sans-serif}
  .note{margin:0;font-size:9pt;font-weight:600}
  .photo{width:46mm;height:34mm;object-fit:cover;border-radius:4mm}
  .cats{columns:2;column-gap:9mm;margin-top:5mm;flex:1}
  .cat{break-inside:avoid;margin-bottom:4mm}
  .cat-tete{display:flex;justify-content:space-between;align-items:baseline;gap:3mm;border-bottom:.4mm solid var(--encre);padding-bottom:1.2mm;margin-bottom:1mm}
  h2{margin:0;font:700 14pt/1.1 "Baloo 2",sans-serif}
  .cat-px{font:700 11pt "Baloo 2",sans-serif;color:var(--violet);white-space:nowrap}
  .cat-desc{margin:1mm 0;color:var(--gris);font-size:8.8pt}
  ul{list-style:none;margin:0;padding:0}
  li{display:flex;align-items:baseline;gap:2mm;padding:.8mm 0;border-bottom:.2mm dashed var(--trait)}
  li:last-child{border-bottom:0}
  li .txt{flex:1}
  li strong{font-weight:600}
  li .desc{color:var(--gris);font-size:8.4pt}
  li .px{font-weight:700;color:var(--violet);white-space:nowrap;font-variant-numeric:tabular-nums}
  .encart{margin-top:2mm;padding:3mm 4mm;border-radius:3mm;background:var(--sable);font-size:9.2pt}
  .encart p{margin:.4mm 0}
  .pied{display:flex;justify-content:space-between;align-items:center;gap:6mm;margin-top:3mm;padding-top:3mm;border-top:.3mm solid var(--trait);font-size:9pt;color:var(--gris)}
  .pied strong{color:var(--violet);font:700 12pt "Baloo 2",sans-serif}
  .vide{max-width:600px;margin:3rem auto;text-align:center;font:500 16px Inter,sans-serif}
  @media print{body{background:#fff}.outils{display:none}.page{width:auto;min-height:0;margin:0;padding:0;box-shadow:none;break-after:page}.page:last-child{break-after:auto}.pied{break-inside:avoid}}
  @media screen and (max-width:820px){.page{zoom:.46;margin:1rem auto}}
</style>
</head>
<body>
<div class="outils">
  <a href="<?= ESPACE ?>/">← Retour</a>
  <button type="button" onclick="window.print()">Imprimer ou enregistrer en PDF</button>
  <span>Pour un PDF : choisissez « Enregistrer au format PDF » comme imprimante.</span>
</div>
<?php if (!$cartes): ?>
<p class="vide">Aucune carte à imprimer.</p>
<?php endif; foreach ($cartes as $k): $ph = photo($k['photo']); ?>
<main class="page">
  <header class="tete<?= $ph ? ' avec-photo' : '' ?>">
    <div>
      <p class="marque"><img src="img/logo/symbole-violet.svg" alt="">L’Atelier gourmand · Au Jardin Parmentier</p>
      <h1><?= h($k['titre']) ?></h1>
<?php if ($k['intro'] !== ''): ?>      <p class="intro"><?= h($k['intro']) ?></p>
<?php endif; if ($k['prix'] !== ''): ?>      <p class="prix"><?= h($k['prix']) ?></p>
<?php endif; if ($k['note'] !== ''): ?>      <p class="note"><?= h($k['note']) ?></p>
<?php endif; ?>
    </div>
<?php if ($ph): ?>    <img class="photo" src="<?= h($ph) ?>" alt="">
<?php endif; ?>
  </header>
  <div class="cats">
<?php foreach ($k['cats'] as $cat): ?>
    <section class="cat">
      <div class="cat-tete"><h2><?= h($cat['titre']) ?></h2><?php if ($cat['prix'] !== ''): ?><span class="cat-px"><?= h($cat['prix']) ?></span><?php endif; ?></div>
<?php if ($cat['desc'] !== ''): ?>      <p class="cat-desc"><?= h($cat['desc']) ?></p>
<?php endif; ?>      <ul>
<?php foreach ($cat['plats'] as $p): ?>        <li><span class="txt"><strong><?= h($p['nom']) ?></strong><?php if ($p['desc'] !== ''): ?> <span class="desc">· <?= h($p['desc']) ?></span><?php endif; ?></span><?php if ($p['prix'] !== ''): ?><span class="px"><?= h($p['prix']) ?></span><?php endif; ?></li>
<?php endforeach; ?>      </ul>
    </section>
<?php endforeach; ?>
<?php if ($k['encart']): ?>    <div class="encart cat"><?php foreach ($k['encart'] as $l): ?><p><?= h($l) ?></p><?php endforeach; ?></div>
<?php endif; ?>
  </div>
  <footer class="pied">
    <span>9, place Parmentier – 80500 Montdidier<br>info@lejardinparmentier.fr · lejardinparmentier.fr</span>
    <span>Commandes au <strong>09 50 17 39 62</strong></span>
  </footer>
</main>
<?php endforeach; ?>
</body>
</html>
