# Au Jardin Parmentier — notes techniques

Site + back-office d'une épicerie fine / traiteur (9 place Parmentier, 80500 Montdidier). Propriétaire : Valentin
(échanges en français, ton simple, pas de jargon). PHP 8 sans framework, JavaScript sans bibliothèque, données en JSON.

## Déploiement
- Hébergement OVH, déploiement Git automatique depuis GitHub `ValentinGardin/au-jardin-parmentier`, branche `main`
  (webhook OVH ; parfois en retard : la version est affichée en bas de la page de connexion du back-office).
- À chaque livraison : incrémenter `VERSION` dans `lib.php`, `bash outils/verifier.sh`, commit, push sur `main`.
- Sauvegardes : branches `sauvegarde-vX.YY-AAAA-MM-JJ` sur GitHub + zip dans `livrables/` (non suivi).

## Fichiers
- `lib.php` : constantes, `site_load()` (mémo par requête, vidé par `json_save` de site.json), nettoyage des données
  (`site_clean`, `carte_clean`, `evenement_clean`, `produits_clean`, `commande_speciale_clean`…), photos (`photo()`,
  `img_attrs()` avec variantes 400/600/800 WebP), prix des produits d'événement (`evt_prix`, `evt_prix_cat`, `UNITES`).
- `clients.php` : clients, réservations (`resa_clean`, numéros uniques via data/numeros.json), lettre du lundi,
  SMTP (`mail_envoyer`), réservation en ligne (`commande_jours`, `commande_evts`, `evt_variantes`, `evt_ligne`,
  `evt_simulation`), stats de ventes, e-mails de commande (`mail_commande`).
- `index.php` : page d'accueil (tout le site public). `main.js` : menu, cookies, fenêtre de réservation (`#rs`).
- `commander.php` : POST de la réservation en ligne. `lettre.php` : inscription / désinscription newsletter.
- `back-office-jardin/index.php` : connexion + toutes les API (`?api=…`, POST JSON, en-tête X-CSRF).
  `admin.js` : application du back-office (une vue par onglet dans `VUES`, liaison des champs par `data-p="chemin.du.champ"`,
  actions par `data-act`, enregistrement par partie : `PARTS` = evenements, semaine, semaine_suivante, menus, commandes, mots, avis, faq).
- `comptes.php` (utilisateurs, rôles admin / editeur / visiteur), `sms.php` (OVH SMS), `rappels.php`, `stats.php`,
  `track.php`, `google.php` (avis Google), `affiche.php` / `carte.php` (impressions), `visuels.js` (visuels du menu).
- Données : `data/*.json`, **jamais dans Git** (voir .gitignore), écrites avec `json_save()` sous `verrou()` (flock).
  Seuls `data/.htaccess` et `data/site.exemple.json` sont suivis.

## Compilation
Les visiteurs reçoivent les `.min` (`asset()` dans lib.php sert le .min s'il est à jour, avec `?v=` = date du fichier).
Après toute modification de `main.js`, `admin.js`, `visuels.js`, `style.css`, `admin.css` ou `fonts/fonts.css` :
`cd outils && node build.mjs` (esbuild 0.24.2) puis committer les `.min`. `bash outils/verifier.sh` fait tout.

## Tester en local
Apache sur le port 8091 → `/var/www/jp-perf` (copie du site ; y recopier les fichiers modifiés). Si Apache ne répond plus :
`pkill -9 apache2; rm -f /var/run/apache2/*.pid; apachectl start`. Les e-mails partent dans `/var/www/mails/*.eml`.
Playwright est préinstallé (`require('playwright')`). Penser à remettre les données de test en place après un essai.

## Règles à respecter
- Ne jamais mettre dans Git de données personnelles (clients, e-mails, réservations) ni de mots de passe ou clés.
- Ne jamais demander de mot de passe ou de clé dans la conversation : la clé Google, le SMTP et le SMS se saisissent dans le back-office.
- Événements : une catégorie avec un prix = formule tout compris (le contenu n'est pas commandable seul) ;
  formats avec unité (unité, kg, 500 g, portion, personne ; kg et 500 g = le client choisit un poids par 100 g).
- Nouvelles cartes traiteur et offres « Sur commande » : hors ligne par défaut (interrupteur « En ligne »).
- Garder le site responsive (téléphone d'abord, pas de défilement horizontal, champs à 16 px sur iPhone).
