#!/usr/bin/env bash
# Vérification complète avant de publier : syntaxe PHP et JavaScript, compilation des .min,
# et contrôle qu'aucune donnée personnelle (data/*.json) n'est suivie par Git.
#   bash outils/verifier.sh
set -u
cd "$(dirname "$0")/.." || exit 1
ok=1

echo "1. Syntaxe PHP"
for f in *.php back-office-jardin/*.php; do
  php -l "$f" >/dev/null 2>&1 || { php -l "$f"; ok=0; }
done

echo "2. Syntaxe JavaScript"
for f in main.js admin.js visuels.js; do
  node --check "$f" 2>/dev/null || { node --check "$f"; ok=0; }
done

echo "3. Compilation (.min)"
[ -d outils/node_modules ] || (cd outils && npm install --silent >/dev/null 2>&1)
(cd outils && node build.mjs | tail -1) || ok=0

echo "4. Données personnelles hors de Git"
suivis=$(git ls-files data | grep -v '^data/\.htaccess$\|^data/site\.exemple\.json$')
[ -z "$suivis" ] || { echo "   ATTENTION, suivis par Git : $suivis"; ok=0; }

if [ "$ok" = 1 ]; then echo "Tout est bon."; else echo "Des erreurs sont à corriger (voir ci-dessus)."; exit 1; fi
