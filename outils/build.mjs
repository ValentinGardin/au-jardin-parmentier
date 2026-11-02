// Compilation : crée les versions compactes (.min) des feuilles de style et des scripts, servies aux visiteurs.
// Les fichiers sources (lisibles) restent ceux qu'on modifie. Lancer après chaque modification :
//   cd outils && npm install && npm run build
import { transform } from 'esbuild';
import { readFile, writeFile, stat } from 'node:fs/promises';

const racine = new URL('../', import.meta.url);
const fichiers = ['main.js', 'admin.js', 'visuels.js', 'style.css', 'admin.css', 'fonts/fonts.css'];
// Navigateurs visés : les 5 dernières années (le site reste en JavaScript simple, sans transformation lourde)
const cibles = ['chrome80', 'edge80', 'firefox78', 'safari13', 'ios13'];

let avant = 0, apres = 0;
for (const f of fichiers) {
  const src = await readFile(new URL(f, racine), 'utf8');
  const loader = f.endsWith('.css') ? 'css' : 'js';
  const { code } = await transform(src, { loader, minify: true, target: cibles, legalComments: 'none', charset: 'utf8' });
  const sortie = f.replace(/\.(js|css)$/, '.min.$1');
  await writeFile(new URL(sortie, racine), code);
  avant += (await stat(new URL(f, racine))).size; apres += Buffer.byteLength(code);
  console.log(`${f.padEnd(16)} ${String(src.length).padStart(7)} → ${String(Buffer.byteLength(code)).padStart(7)} octets  (${sortie})`);
}
console.log(`Total : ${Math.round(avant / 1024)} Ko → ${Math.round(apres / 1024)} Ko (${Math.round((1 - apres / avant) * 100)} % de moins)`);
