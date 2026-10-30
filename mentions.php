<?php require __DIR__ . '/lib.php';
// Informations légales : complétez les valeurs entre crochets (Kbis / avis de situation INSEE).
$editeur = [
    'nom'       => 'Au Jardin Parmentier',
    'forme'     => '[Forme juridique et capital, ex. SARL au capital de 5 000 €]',
    'adresse'   => '9, place Parmentier – 80500 Montdidier',
    'tel'       => '09 50 17 39 62',
    'email'     => 'info@lejardinparmentier.fr',
    'siret'     => '[N° SIRET]',
    'rcs'       => '[RCS Amiens + numéro]',
    'tva'       => '[N° de TVA intracommunautaire]',
    'directeur' => '[Prénom Nom du directeur de la publication]',
];
$realisation = 'G’agency';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mentions légales · Au Jardin Parmentier</title>
<meta name="robots" content="noindex">
<meta name="theme-color" content="#ffffff">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="icon" href="favicon.ico" sizes="32x32">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="style.css?v=4" rel="stylesheet">
</head>
<body>

<header class="nav plein">
  <div class="wrap nav-in">
    <a class="brand" href="index.php" aria-label="Au Jardin Parmentier, accueil">
      <img src="favicon.svg" alt="" width="40" height="40">
      <span>Au Jardin Parmentier</span>
    </a>
    <a class="btn btn-ligne btn-sm" href="index.php">← Retour au site</a>
  </div>
</header>

<main class="legal wrap">
  <p class="eyebrow">Informations</p>
  <h1>Mentions légales</h1>

  <section>
    <h2>Éditeur du site</h2>
    <p><strong><?= h($editeur['nom']) ?></strong><br>
      <?= h($editeur['forme']) ?><br>
      <?= h($editeur['adresse']) ?><br>
      Téléphone : <a href="tel:+33950173962"><?= h($editeur['tel']) ?></a><br>
      E-mail : <a href="mailto:<?= h($editeur['email']) ?>"><?= h($editeur['email']) ?></a></p>
    <p>SIRET : <?= h($editeur['siret']) ?><br>
      <?= h($editeur['rcs']) ?><br>
      TVA intracommunautaire : <?= h($editeur['tva']) ?></p>
    <p>Directeur de la publication : <?= h($editeur['directeur']) ?></p>
  </section>

  <section>
    <h2>Conception et réalisation</h2>
    <p>Le site a été conçu et réalisé par <strong><?= h($realisation) ?></strong>.</p>
  </section>

  <section>
    <h2>Hébergement</h2>
    <p><strong>OVH SAS</strong><br>
      2, rue Kellermann – 59100 Roubaix – France<br>
      <a href="https://www.ovhcloud.com" target="_blank" rel="noopener">www.ovhcloud.com</a></p>
  </section>

  <section>
    <h2>Propriété intellectuelle</h2>
    <p>L’ensemble des contenus de ce site (textes, photographies, logo, menus) est la propriété d’Au Jardin Parmentier, sauf mention contraire. Toute reproduction, totale ou partielle, sans autorisation écrite préalable est interdite.</p>
  </section>

  <section>
    <h2>Données personnelles</h2>
    <p>Ce site ne comporte aucun formulaire et ne collecte aucune donnée personnelle. Lorsque vous nous écrivez par e-mail ou nous appelez, vos coordonnées servent uniquement à répondre à votre demande et à préparer votre commande ; elles ne sont jamais transmises à des tiers.</p>
    <p>Conformément au Règlement général sur la protection des données (RGPD), vous pouvez demander l’accès, la rectification ou la suppression de vos données en écrivant à <a href="mailto:<?= h($editeur['email']) ?>"><?= h($editeur['email']) ?></a>.</p>
  </section>

  <section>
    <h2>Cookies et services tiers</h2>
    <p>Le site ne dépose aucun cookie. Pour connaître sa fréquentation, il utilise une mesure d’audience interne et anonyme : aucune adresse IP n’est conservée, seule une empreinte temporaire (renouvelée chaque jour) permet de compter les visiteurs. Ces statistiques restent sur notre hébergement, ne sont partagées avec personne et sont effacées au bout de 13 mois.</p>
    <p>Il utilise des services externes qui peuvent recevoir votre adresse IP lors de l’affichage de la page : Google Fonts (polices de caractères) et Google Maps (plan d’accès). Ces services sont soumis à la politique de confidentialité de Google.</p>
  </section>

  <section>
    <h2>Crédits</h2>
    <p>Photographies : Au Jardin Parmentier.</p>
  </section>
</main>

<footer class="pied">
  <div class="wrap pied-in">
    <img src="img/logo/logo-blanc.svg" alt="Au Jardin Parmentier" width="150" height="95">
    <p>Épicerie fine · Traiteur<br>9, place Parmentier – 80500 Montdidier</p>
    <p class="pied-liens"><a href="index.php">Retour au site</a></p>
  </div>
</footer>
</body>
</html>
