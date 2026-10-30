<?php require __DIR__ . '/lib.php';
// Informations légales (source : avis de situation INSEE et extrait RNE).
$editeur = [
    'nom'       => 'Au Jardin Parmentier',
    'societe'   => 'SARL GARDIN',
    'forme'     => 'Société à responsabilité limitée',
    'capital'   => '10 000 €',
    'adresse'   => '9, place Parmentier – 80500 Montdidier',
    'tel'       => '09 50 17 39 62',
    'email'     => 'info@lejardinparmentier.fr',
    'siren'     => '813 853 561',
    'siret'     => '813 853 561 00013',
    'rne'       => 'Immatriculée au Registre national des entreprises (RNE) le 2 octobre 2015',
    'tva'       => 'FR79 813 853 561',
    'naf'       => '47.11C – Supérettes',
    'directeur' => 'Fabrice Gardin et Angélique Gardin, cogérants',
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
<link rel="canonical" href="https://www.lejardinparmentier.fr/mentions-legales">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="icon" href="favicon.ico" sizes="32x32">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<link rel="preload" href="fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="fonts/baloo2-latin.woff2" as="font" type="font/woff2" crossorigin>
<link href="style.css?v=8" rel="stylesheet">
</head>
<body>

<header class="nav plein">
  <div class="wrap nav-in">
    <a class="brand" href="./" aria-label="Au Jardin Parmentier, accueil">
      <img src="favicon.svg" alt="" width="40" height="40">
      <span>Au Jardin Parmentier</span>
    </a>
    <a class="btn btn-ligne btn-sm" href="./">← Retour au site</a>
  </div>
</header>

<main class="legal wrap">
  <p class="eyebrow">Informations</p>
  <h1>Mentions légales</h1>

  <section>
    <h2>Éditeur du site</h2>
    <p>Le site <strong><?= h($editeur['nom']) ?></strong> est édité par :</p>
    <p><strong><?= h($editeur['societe']) ?></strong><br>
      <?= h($editeur['forme']) ?><?= $editeur['capital'] !== '' ? ' au capital de ' . h($editeur['capital']) : '' ?><br>
      Siège social : <?= h($editeur['adresse']) ?><br>
      Téléphone : <a href="tel:+33950173962"><?= h($editeur['tel']) ?></a><br>
      E-mail : <a href="mailto:<?= h($editeur['email']) ?>"><?= h($editeur['email']) ?></a></p>
    <p>SIREN : <?= h($editeur['siren']) ?> · SIRET : <?= h($editeur['siret']) ?><br>
      <?= h($editeur['rne']) ?><br>
      N° de TVA intracommunautaire : <?= h($editeur['tva']) ?><br>
      Code NAF/APE : <?= h($editeur['naf']) ?></p>
    <p>Directeur de la publication : <?= h($editeur['directeur'] !== '' ? $editeur['directeur'] : 'le gérant de la ' . $editeur['societe']) ?></p>
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

  <section id="cookies">
    <h2>Cookies et services tiers</h2>
    <p>Le site ne dépose aucun cookie. Pour connaître sa fréquentation, il utilise une mesure d’audience interne et anonyme : aucune adresse IP n’est conservée, seule une empreinte temporaire (renouvelée chaque jour) permet de compter les visiteurs. Ces statistiques restent sur notre hébergement, ne sont partagées avec personne et sont effacées au bout de 13 mois.</p>
    <p>Le plan d’accès est fourni par Google Maps, qui peut déposer des cookies : il n’est affiché qu’après votre accord, donné dans le bandeau « cookies ». Vous pouvez changer d’avis à tout moment avec le lien « Gérer les cookies » en bas de la page d’accueil ; votre choix est conservé 6 mois sur votre appareil.</p>
    <p>Les polices de caractères sont hébergées sur notre propre serveur : aucune donnée n’est transmise à un service tiers lors de l’affichage des pages, à l’exception de Google Maps si vous l’acceptez, soumis à la politique de confidentialité de Google.</p>
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
    <p class="pied-liens"><a href="./">Retour au site</a></p>
  </div>
</footer>
</body>
</html>
