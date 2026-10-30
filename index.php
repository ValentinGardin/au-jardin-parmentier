<?php require __DIR__ . '/lib.php'; require __DIR__ . '/stats.php'; $s = site_load();
stats_vue();
$evts = evenements_visibles($s['evenements']);
$tel = '09 50 17 39 62'; $telHref = 'tel:+33950173962';
$maps = 'https://www.google.com/maps/search/?api=1&query=9+place+Parmentier+80500+Montdidier';
$sem = $s['semaine'];
// Seuls les jours avec un plat sont affichés ; sans aucun plat, la section est masquée
$sem['jours'] = array_values(array_filter($sem['jours'], function ($j) { return $j['nom'] !== ''; }));
$aSemaine = (bool)$sem['jours'];
$enCours = semaine_en_cours($sem);
$auj = jour_actuel();
$platDuJour = null;
if ($enCours) foreach ($sem['jours'] as $j) if (norm($j['jour']) === norm($auj) && $j['nom'] !== '') $platDuJour = $j;
$rayons = [
    ['traiteur',    'Traiteur',          'Plats cuisinés, buffets, plateaux et apéritifs préparés par nos soins.'],
    ['fromagerie',  'Fromagerie',        'Des fromages choisis, pour le plateau comme pour la cuisine.'],
    ['charcuterie', 'Charcuterie',       'Jambons, terrines, saucissons : le meilleur à la coupe.'],
    ['legumes',     'Fruits et légumes', 'Des produits frais, au fil des saisons, et nos corbeilles de fruits.'],
    ['epicerie',    'Épicerie fine',     'Conserves, condiments et douceurs sélectionnés.'],
    ['vins',        'Vins et champagnes', 'Une cave à partager, et les huîtres de Normandie.'],
];
$galerie = photos_galerie();
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Au Jardin Parmentier · épicerie fine et traiteur à Montdidier</title>
<meta name="description" content="Épicerie fine et traiteur à Montdidier (Somme) : menu de la semaine, plats cuisinés, buffets, plateaux repas, apéritifs, fromagerie, charcuterie, fruits et légumes.">
<meta name="theme-color" content="#ffffff">
<meta property="og:type" content="website">
<meta property="og:title" content="Au Jardin Parmentier · épicerie fine et traiteur">
<meta property="og:description" content="Épicerie fine et traiteur au cœur de Montdidier.">
<meta property="og:image" content="img/og-image.png">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="icon" href="favicon.ico" sizes="32x32">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<link rel="manifest" href="site.webmanifest">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="style.css?v=5" rel="stylesheet">
<script>document.documentElement.classList.add('js')</script>
</head>
<body>

<a class="skip" href="#contenu">Aller au contenu</a>

<header class="nav" id="nav">
  <div class="wrap nav-in">
    <a class="brand" href="#accueil" aria-label="Au Jardin Parmentier, accueil">
      <img src="favicon.svg" alt="" width="40" height="40">
      <span>Au Jardin Parmentier</span>
    </a>
    <nav id="menu" class="menu" aria-label="Navigation principale">
<?php if ($aSemaine): ?>      <a href="#semaine">Menu de la semaine</a>
<?php endif; ?>      <a href="#traiteur">Traiteur</a>
      <a href="#boutique">La boutique</a>
      <a href="#maison">Qui sommes-nous</a>
      <a href="#contact">Contact</a>
    </nav>
    <a class="btn btn-violet btn-sm nav-tel" href="<?= $telHref ?>"><svg class="ico" aria-hidden="true"><use href="#i-tel"/></svg><span><?= $tel ?></span></a>
    <button class="burger" type="button" aria-controls="menu" aria-expanded="false" aria-label="Ouvrir le menu"><span></span><span></span></button>
  </div>
</header>

<main id="contenu">

<!-- ============ Accueil ============ -->
<section id="accueil" class="hero">
  <div class="wrap hero-in">
    <div class="hero-txt">
<?php if ($evts): $e0 = $evts[0]; ?>
      <a class="pastille annonce anim" style="--d:0" href="#evenements"><span class="annonce-tag">À ne pas manquer</span><?= h($e0['titre']) ?><?= $e0['date'] ? ' · ' . h(date_fr($e0['date'], true)) : '' ?><span aria-hidden="true">→</span></a>
<?php else: ?>
      <p class="pastille anim" style="--d:0"><span class="point"></span>Épicerie fine &amp; traiteur · Montdidier</p>
<?php endif; ?>
      <h1 class="anim" style="--d:1">Bienvenue dans notre <span class="accent">atelier gourmand.</span></h1>
      <p class="lead anim" style="--d:2">De bons produits, et des repas préparés selon vos envies, par Angel &amp; Fab, au cœur de Montdidier.</p>
      <div class="ctas anim" style="--d:3">
<?php if ($aSemaine): ?>        <a class="btn btn-violet btn-lg" href="#semaine">Le menu de la semaine</a>
<?php endif; ?>        <a class="btn btn-ligne btn-lg" href="#traiteur">Nos cartes traiteur</a>
      </div>
<?php if ($platDuJour): ?>
      <a class="du-jour anim" style="--d:4" href="#semaine">
        <span class="du-jour-tag">Aujourd’hui</span>
        <span class="du-jour-nom"><?= h($platDuJour['nom']) ?></span>
        <?php if ($platDuJour['prix'] !== ''): ?><span class="du-jour-px"><?= h($platDuJour['prix']) ?></span><?php endif; ?>
      </a>
<?php endif; ?>
    </div>
    <div class="hero-visuel anim" style="--d:2">
      <figure class="hv hv-1"><img src="<?= h(photo('hero') ?? 'img/logo/logo-violet.svg') ?>" alt="Verrines et bouchées préparées par l’atelier" width="1100" height="1375" fetchpriority="high"></figure>
<?php if ($p = photo('legumes')): ?>      <figure class="hv hv-2"><img src="<?= h($p) ?>" alt="" width="500" height="375"></figure>
<?php endif; if ($p = photo('charcuterie')): ?>      <figure class="hv hv-3"><img src="<?= h($p) ?>" alt="" width="500" height="375"></figure>
<?php endif; ?>
      <div class="hv-badge" aria-hidden="true"><img src="img/logo/symbole-blanc.svg" alt=""></div>
    </div>
  </div>
</section>

<!-- Défilé de mots : les mots se changent dans le back-office -->
<section class="defile" aria-label="Nos spécialités">
  <ul class="sr-only"><?php foreach ($s['mots'] as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul>
  <div class="defile-piste" aria-hidden="true">
<?php for ($r = 0; $r < 2; $r++): ?>
    <div class="defile-groupe">
<?php for ($k = 0; $k < 2; $k++): foreach ($s['mots'] as $w): ?>
      <span class="mot"><?= h($w) ?></span><img class="sep" src="img/logo/symbole-blanc.svg" alt="">
<?php endforeach; endfor; ?>
    </div>
<?php endfor; ?>
  </div>
</section>

<?php if ($evts): ?>
<!-- ============ Événements et menus spéciaux (gérés dans le back-office) ============ -->
<section id="evenements" class="section evts" aria-label="Événements">
  <div class="wrap evts-in">
<?php foreach ($evts as $e):
    $ouvert = reservation_ouverte($e);
    $dans = $e['date'] ? (int)round((strtotime($e['date']) - strtotime(date('Y-m-d'))) / 86400) : null;
    $quand = $dans === null ? '' : ($dans < 0 ? '' : ($dans === 0 ? 'C’est aujourd’hui !' : ($dans === 1 ? 'C’est demain !' : 'Dans ' . $dans . ' jours')));
    $ph = photo($e['photo']); ?>
    <?php $cl = couleurs_evt($e['couleur']); ?>
    <article class="evt<?= $cl['clair'] ? ' evt-clair' : '' ?><?= $ph ? ' avec-photo' : '' ?> reveal" style="--fond-evt:<?= $cl['fond'] ?>;--texte-evt:<?= $cl['texte'] ?>;--accent-evt:<?= $cl['accent'] ?>">
      <img class="evt-sym" src="img/logo/symbole-<?= $cl['clair'] ? 'violet' : 'blanc' ?>.svg" alt="">
<?php if ($ph): ?>      <img class="evt-photo" src="<?= h($ph) ?>" alt="" loading="lazy" width="1000" height="750">
<?php endif; ?>
      <div class="evt-tete">
<?php if ($e['date']): ?>        <p class="evt-date"><span><?= h(ucfirst(date_fr($e['date'], true))) ?></span><?php if ($quand): ?><em><?= h($quand) ?></em><?php endif; ?></p>
<?php endif; ?>        <h2><?= h($e['titre']) ?></h2>
<?php if ($e['accroche'] !== ''): ?>        <p class="evt-accroche"><?= h($e['accroche']) ?></p>
<?php endif; ?>
      </div>
      <div class="evt-corps">
<?php if ($e['items']): ?>        <ul class="evt-items">
<?php foreach ($e['items'] as $it): ?>          <li><span><?= h($it['nom']) ?></span><?php if ($it['prix'] !== ''): ?><i aria-hidden="true"></i><b><?= h($it['prix']) ?></b><?php endif; ?></li>
<?php endforeach; ?>        </ul>
<?php endif; foreach ($e['lignes'] as $l): ?>        <p class="evt-ligne"><?= h($l) ?></p>
<?php endforeach; ?>
        <div class="evt-resa">
<?php if ($ouvert): ?>
<?php if ($e['cta'] !== ''): ?>          <p class="evt-cta"><?= h($e['cta']) ?></p>
<?php endif; ?>          <a class="btn btn-blanc btn-lg" href="<?= $telHref ?>" data-evt="<?= h($e['titre']) ?>">Réserver au <?= $tel ?></a>
<?php if ($e['reservation']['active'] && $e['reservation']['date']): ?>          <p class="evt-limite">Réservation jusqu’au <?= h(date_fr($e['reservation']['date'], true)) ?> inclus</p>
<?php endif; else: ?>          <p class="evt-clos">Les réservations sont closes. Merci à tous !</p>
<?php endif; ?>
        </div>
      </div>
    </article>
<?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($aSemaine): ?>
<!-- ============ Menu de la semaine (géré dans le back-office) ============ -->
<section id="semaine" class="section semaine">
  <div class="wrap">
    <div class="tete reveal">
      <div>
        <p class="eyebrow">Plats du jour</p>
        <h2><?= h($sem['titre']) ?></h2>
      </div>
<?php if ($sem['du']): ?>      <p class="periode">Semaine <?= h(periode_fr($sem['du'], $sem['au'])) ?></p>
<?php endif; ?>
    </div>
    <div class="semaine-in<?= $sem['special']['nom'] !== '' || $sem['special']['lignes'] ? ' avec-special' : '' ?>">
      <ol class="jours">
<?php foreach ($sem['jours'] as $i => $j): $estAuj = $enCours && norm($j['jour']) === norm($auj); ?>
        <li class="jour reveal<?= $estAuj ? ' auj' : '' ?>" style="--d:<?= $i ?>">
          <span class="jour-nom"><?= h($j['jour']) ?><?php if ($estAuj): ?> <em>Aujourd’hui</em><?php endif; ?></span>
          <span class="jour-plat"><strong><?= h($j['nom']) ?></strong><?php if ($j['desc'] !== ''): ?><span><?= h($j['desc']) ?></span><?php endif; ?></span>
          <span class="jour-px"><?= h($j['prix']) ?></span>
        </li>
<?php endforeach; ?>
      </ol>
<?php $sp = $sem['special']; if ($sp['nom'] !== '' || $sp['lignes']): ?>
      <aside class="special reveal" style="--d:2">
        <img class="special-sym" src="img/logo/symbole-blanc.svg" alt="">
<?php if ($sp['titre'] !== ''): ?>        <p class="special-quand"><?= h($sp['titre']) ?></p>
<?php endif; ?>        <h3><?= h($sp['nom']) ?></h3>
<?php if ($sp['prix'] !== ''): ?>        <p class="special-px"><?= h($sp['prix']) ?></p>
<?php endif; if ($sp['lignes']): ?>        <ul><?php foreach ($sp['lignes'] as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ul>
<?php endif; ?>        <a class="btn btn-blanc" href="<?= $telHref ?>">Réserver au <?= $tel ?></a>
      </aside>
<?php endif; ?>
    </div>
<?php if ($sem['note'] !== ''): ?>    <p class="semaine-note reveal"><?= h($sem['note']) ?></p>
<?php endif; ?>
    <p class="semaine-note reveal">À emporter, à réserver de préférence par téléphone au <a href="<?= $telHref ?>"><?= $tel ?></a>.</p>
  </div>
</section>
<?php endif; ?>

<!-- ============ Cartes traiteur (gérées dans le back-office) ============ -->
<section id="traiteur" class="section traiteur">
  <div class="wrap">
    <div class="tete reveal">
      <div>
        <p class="eyebrow">L’atelier traiteur</p>
        <h2>Nos cartes, pour toutes vos occasions.</h2>
      </div>
      <p class="tete-txt">Repas de famille, réunion, anniversaire… Choisissez une carte, nous nous occupons du reste.</p>
    </div>
<?php if (!$s['menus']): ?>
    <p class="vide-msg">Nos cartes arrivent bientôt. Appelez-nous au <a href="<?= $telHref ?>"><?= $tel ?></a>.</p>
<?php else: ?>
    <div class="onglets reveal" role="tablist" aria-label="Cartes traiteur">
<?php foreach ($s['menus'] as $i => $c): ?>
      <button type="button" role="tab" id="tab-<?= $i ?>" aria-controls="carte-<?= $i ?>" aria-selected="<?= $i ? 'false' : 'true' ?>"<?= $i ? ' tabindex="-1"' : '' ?>><?= h($c['titre']) ?></button>
<?php endforeach; ?>
    </div>
<?php foreach ($s['menus'] as $i => $c): $ph = photo($c['photo']); ?>
    <article class="carte" role="tabpanel" id="carte-<?= $i ?>" aria-labelledby="tab-<?= $i ?>"<?= $i ? ' hidden' : '' ?>>
      <header class="carte-tete<?= $ph ? '' : ' sans-photo' ?>">
<?php if ($ph): ?>        <img src="<?= h($ph) ?>" alt="" loading="lazy" width="1000" height="750">
<?php endif; ?>        <div class="carte-info">
          <h3><?= h($c['titre']) ?></h3>
<?php if ($c['intro'] !== ''): ?>          <p><?= h($c['intro']) ?></p>
<?php endif; if ($c['prix'] !== ''): ?>          <p class="carte-px"><?= h($c['prix']) ?></p>
<?php endif; if ($c['note'] !== ''): ?>          <p class="carte-note"><svg class="ico" aria-hidden="true"><use href="#i-info"/></svg><?= h($c['note']) ?></p>
<?php endif; ?>
        </div>
      </header>
      <div class="cats">
<?php foreach ($c['cats'] as $k): ?>
        <section class="cat">
          <div class="cat-tete">
            <h4><?= h($k['titre']) ?></h4>
<?php if ($k['prix'] !== ''): ?>            <span class="cat-px"><?= h($k['prix']) ?></span>
<?php endif; ?>
          </div>
<?php if ($k['desc'] !== ''): ?>          <p class="cat-desc"><?= h($k['desc']) ?></p>
<?php endif; ?>          <ul class="plats">
<?php foreach ($k['plats'] as $p): ?>
            <li><span class="plat-txt"><strong><?= h($p['nom']) ?></strong><?php if ($p['desc'] !== ''): ?> <span><?= h($p['desc']) ?></span><?php endif; ?></span><?php if ($p['prix'] !== ''): ?><span class="plat-px"><?= h($p['prix']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
          </ul>
        </section>
<?php endforeach; ?>
      </div>
<?php if ($c['encart']): ?>      <div class="encart"><?php foreach ($c['encart'] as $l): ?><p><?= h($l) ?></p><?php endforeach; ?></div>
<?php endif; ?>
      <div class="carte-cta">
        <p>Pour commander ou composer votre menu&nbsp;:</p>
        <a class="btn btn-violet" href="<?= $telHref ?>">Appeler le <?= $tel ?></a>
        <a class="btn btn-ligne" href="mailto:info@lejardinparmentier.fr?subject=<?= rawurlencode('Commande traiteur : ' . $c['titre']) ?>">Écrire un e-mail</a>
      </div>
    </article>
<?php endforeach; endif; ?>
  </div>
</section>

<!-- ============ La boutique ============ -->
<section id="boutique" class="section boutique">
  <div class="wrap">
    <div class="tete reveal">
      <div>
        <p class="eyebrow">La boutique</p>
        <h2>De quoi simplifier la vie, et faire plaisir.</h2>
      </div>
      <p class="tete-txt">9, place Parmentier : une épicerie fine où l’on trouve de bons produits, et les conseils qui vont avec.</p>
    </div>
    <div class="rayons">
<?php foreach ($rayons as $i => [$slug, $nom, $txt]): $p = photo($slug); ?>
      <article class="rayon reveal<?= $p ? '' : ' sans-photo' ?>" style="--d:<?= $i % 3 ?>">
<?php if ($p): ?>        <img src="<?= h($p) ?>" alt="" loading="lazy" width="1000" height="750">
<?php else: ?>        <img class="rayon-sym" src="img/logo/symbole-violet.svg" alt="">
<?php endif; ?>        <div class="rayon-txt">
          <h3><?= h($nom) ?></h3>
          <p><?= h($txt) ?></p>
        </div>
      </article>
<?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============ Qui sommes-nous ============ -->
<section id="maison" class="section maison">
  <div class="wrap maison-in">
    <figure class="maison-img reveal">
<?php if ($p = photo('equipe')): ?>      <img src="<?= h($p) ?>" alt="Angel et Fab dans leur boutique, devant la cave à vins" loading="lazy" width="900" height="1125">
<?php endif; ?>      <figcaption>Angel &amp; Fab</figcaption>
    </figure>
    <div class="maison-txt">
      <p class="eyebrow reveal">Qui sommes-nous</p>
      <h2 class="reveal">Faire plaisir, et rendre service.</h2>
      <p class="reveal">Issus tous les deux du monde de la restauration, nous avons appris à faire plaisir et à rendre service : être à l’écoute des envies gustatives de chacun.</p>
      <p class="reveal">Un repas en famille, entre amis… nous le réalisons selon vos envies. N’hésitez pas à venir nous voir : nous trouverons toujours une idée à développer ensemble !</p>
      <ul class="atouts reveal">
        <li><strong>Fait maison</strong><span>Tout est préparé dans notre atelier</span></li>
        <li><strong>Sur mesure</strong><span>Des menus selon vos envies</span></li>
        <li><strong>Jusqu’à 50</strong><span>personnes pour vos événements</span></li>
      </ul>
    </div>
  </div>
</section>

<?php if ($galerie): ?>
<section class="galerie" aria-label="Nos réalisations en images">
  <div class="galerie-piste">
<?php foreach (array_merge($galerie, $galerie) as $i => $g): ?>
    <img src="<?= h($g) ?>" alt="" loading="lazy" width="640" height="800"<?= $i >= count($galerie) ? ' aria-hidden="true"' : '' ?>>
<?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- ============ Avis ============ -->
<section id="avis" class="section avis">
  <div class="wrap">
    <div class="tete reveal">
      <div>
        <p class="eyebrow">Avis</p>
        <h2>Ils en parlent mieux que nous.</h2>
      </div>
      <a class="btn btn-ligne" href="https://share.google/8WpV5ZY07xXf0V1E9" target="_blank" rel="noopener">Lire et laisser un avis Google</a>
    </div>
<?php if ($s['avis']): ?>
    <div class="avis-grille">
<?php foreach ($s['avis'] as $i => $a): $n = $a['note']; ?>
      <figure class="avis-carte reveal" style="--d:<?= $i % 3 ?>">
<?php if ($n): ?>        <div class="etoiles" aria-label="<?= $n ?> sur 5"><?= str_repeat('★', $n) ?><span><?= str_repeat('★', 5 - $n) ?></span></div>
<?php endif; ?>        <blockquote><?= h($a['texte']) ?></blockquote>
<?php if ($a['nom'] !== ''): ?>        <figcaption><?= h($a['nom']) ?></figcaption>
<?php endif; ?>      </figure>
<?php endforeach; ?>
    </div>
<?php endif; ?>
  </div>
</section>

<!-- ============ Contact ============ -->
<section id="contact" class="section contact">
  <div class="wrap contact-in">
    <div class="contact-txt">
      <p class="eyebrow reveal">Contact</p>
      <h2 class="reveal">Passez nous voir.</h2>
      <ul class="coord reveal">
        <li><svg class="ico" aria-hidden="true"><use href="#i-pin"/></svg><span>9, place Parmentier<br>80500 Montdidier</span></li>
        <li><svg class="ico" aria-hidden="true"><use href="#i-tel"/></svg><a href="<?= $telHref ?>"><?= $tel ?></a></li>
        <li><svg class="ico" aria-hidden="true"><use href="#i-mail"/></svg><a href="mailto:info@lejardinparmentier.fr">info@lejardinparmentier.fr</a></li>
      </ul>
      <div class="ctas reveal">
        <a class="btn btn-violet" href="<?= h($maps) ?>" target="_blank" rel="noopener">Itinéraire</a>
        <a class="btn btn-ligne" href="https://www.facebook.com/Au-Jardin-Parmentier-1641585952790089/?fref=ts" target="_blank" rel="noopener">Facebook</a>
      </div>
    </div>
    <div class="plan reveal">
      <!-- La carte Google n'est chargée qu'après accord pour les cookies -->
      <iframe title="Plan d’accès : 9 place Parmentier, Montdidier" data-src="https://www.google.com/maps?q=9+place+Parmentier+80500+Montdidier&amp;output=embed" loading="lazy" referrerpolicy="no-referrer-when-downgrade" hidden></iframe>
      <div class="plan-attente">
        <svg class="ico" aria-hidden="true"><use href="#i-pin"/></svg>
        <p>La carte est fournie par Google Maps, qui peut déposer des cookies.</p>
        <button type="button" class="btn btn-violet btn-sm" data-cookies="accepter">Accepter et afficher la carte</button>
        <a href="<?= h($maps) ?>" target="_blank" rel="noopener">Ouvrir dans Google Maps</a>
      </div>
    </div>
  </div>
</section>

</main>

<footer class="pied">
  <div class="wrap pied-in">
    <img src="img/logo/logo-blanc.svg" alt="Au Jardin Parmentier" width="150" height="95">
    <p>Épicerie fine · Traiteur<br>9, place Parmentier – 80500 Montdidier<br><a href="<?= $telHref ?>"><?= $tel ?></a></p>
    <p class="pied-liens"><a href="mentions.php">Mentions légales</a> · <button type="button" class="lien-cookies" data-cookies="gerer">Gérer les cookies</button> · Réalisation G’agency</p>
  </div>
</footer>

<div class="barre" aria-label="Actions rapides">
  <a class="btn btn-violet" href="<?= $telHref ?>"><svg class="ico" aria-hidden="true"><use href="#i-tel"/></svg>Appeler</a>
  <a class="btn btn-ligne" href="<?= h($maps) ?>" target="_blank" rel="noopener"><svg class="ico" aria-hidden="true"><use href="#i-pin"/></svg>Itinéraire</a>
</div>


<?php $pop = null; foreach ($evts as $e) if ($e['popup']) { $pop = $e; break; }
if ($pop): $cl = couleurs_evt($pop['couleur']); $ph = photo($pop['photo']); ?>
<!-- Fenêtre d'accueil pour l'événement du moment (option « pop-up » dans le back-office) -->
<div class="pop" id="pop" hidden data-cle="<?= h(substr(md5($pop['titre'] . $pop['date']), 0, 10)) ?>">
  <div class="pop-carte<?= $cl['clair'] ? ' evt-clair' : '' ?>" role="dialog" aria-modal="true" aria-labelledby="pop-titre" style="--fond-evt:<?= $cl['fond'] ?>;--texte-evt:<?= $cl['texte'] ?>;--accent-evt:<?= $cl['accent'] ?>">
    <button type="button" class="pop-fermer" data-pop-fermer aria-label="Fermer">×</button>
<?php if ($ph): ?>    <img class="pop-img" src="<?= h($ph) ?>" alt="" width="1000" height="750">
<?php endif; ?>
    <div class="pop-corps">
<?php if ($pop['date']): ?>      <p class="evt-date"><span><?= h(ucfirst(date_fr($pop['date'], true))) ?></span></p>
<?php endif; ?>      <h2 id="pop-titre"><?= h($pop['titre']) ?></h2>
<?php if ($pop['accroche'] !== ''): ?>      <p class="pop-accroche"><?= h($pop['accroche']) ?></p>
<?php endif; if ($pop['items']): ?>      <ul class="evt-items">
<?php foreach (array_slice($pop['items'], 0, 4) as $it): ?>        <li><span><?= h($it['nom']) ?></span><?php if ($it['prix'] !== ''): ?><i aria-hidden="true"></i><b><?= h($it['prix']) ?></b><?php endif; ?></li>
<?php endforeach; ?>      </ul>
<?php endif; ?>
      <div class="pop-actions">
<?php if (reservation_ouverte($pop)): ?>        <a class="btn btn-blanc" href="<?= $telHref ?>" data-evt="<?= h($pop['titre']) ?>">Réserver au <?= $tel ?></a>
<?php endif; ?>        <a class="btn pop-detail" href="#evenements" data-pop-fermer>Voir le détail</a>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Bandeau cookies : seule la carte Google Maps en dépose ; les statistiques du site sont anonymes et sans cookie -->
<div class="cookies" id="cookies" hidden role="region" aria-label="Cookies">
  <p><strong>Les cookies, en bref.</strong> Nos statistiques de visite sont anonymes et sans cookie. Seule la carte Google Maps peut en déposer : acceptez-vous de l’afficher ? <a href="mentions.php#cookies">En savoir plus</a></p>
  <div class="cookies-actions">
    <button type="button" class="btn btn-ligne btn-sm" data-cookies="refuser">Refuser</button>
    <button type="button" class="btn btn-violet btn-sm" data-cookies="accepter">Accepter</button>
  </div>
</div>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="i-tel" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></symbol>
  <symbol id="i-pin" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></g></symbol>
  <symbol id="i-mail" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></g></symbol>
  <symbol id="i-info" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></g></symbol>
</svg>
<script src="main.js?v=5" defer></script>
</body>
</html>
