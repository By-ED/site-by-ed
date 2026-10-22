// Robot de création des expéditions chez les transporteurs (pour l'instant : XPO, Station Chargeur).
// Il demande au site transport les expéditions à créer, les saisit sur le site du transporteur comme le ferait
// la secrétaire, puis renvoie le résultat. Il ne transmet pas les pré-annonces : la secrétaire le fait après
// avoir imprimé les étiquettes (Encours). Identifiants dans .env, jamais dans le code ni dans les journaux.
//
// Lancer : node robot.js            (tourne en continu, vérifie toutes les INTERVALLE secondes)
//          node robot.js --une-fois (un seul passage, pour les essais)
//          node robot.js --essai    (connexion XPO + expédition inventée, remplie puis annulée)
'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

// ---------- Configuration (.env à côté de ce fichier) ----------

function lireEnv(fichier) {
  const env = {};
  for (const ligne of fs.readFileSync(fichier, 'utf8').split(/\r?\n/)) {
    const m = /^\s*([A-Z_]+)\s*=\s*(.*?)\s*$/.exec(ligne);
    if (m) env[m[1]] = m[2].replace(/^"(.*)"$/, '$1');
  }
  return env;
}

const ENV = lireEnv(path.join(__dirname, '.env'));
for (const k of ['SITE_URL', 'JETON_ROBOT', 'XPO_IDENTIFIANT', 'XPO_MOT_DE_PASSE']) if (!ENV[k]) throw new Error(`.env : ${k} manquant`);
const MODE = ENV.MODE === 'reel' ? 'reel' : 'repetition';
const INTERVALLE = Math.max(30, Number(ENV.INTERVALLE || 60)) * 1000;
const XPO_URL = ENV.XPO_URL || 'https://xpo.station-chargeur.com/XPO/';
const CAPTURES = path.join(__dirname, 'captures');
fs.mkdirSync(CAPTURES, { recursive: true });

const journal = (...m) => console.log(new Date().toISOString(), ...m);

// ---------- Dialogue avec le site transport ----------

async function site(action, corps) {
  const r = await fetch(ENV.SITE_URL.replace(/\/$/, '') + '/api.php?a=' + action, {
    method: corps ? 'POST' : 'GET',
    headers: { 'X-Jeton-Robot': ENV.JETON_ROBOT, ...(corps ? { 'Content-Type': 'application/json' } : {}) },
    body: corps ? JSON.stringify(corps) : undefined,
  });
  const d = await r.json().catch(() => null);
  if (!r.ok || !d) throw new Error(`site ${action} : HTTP ${r.status} ${d && d.message ? d.message : ''}`);
  return d;
}

// ---------- XPO : Station Chargeur ----------

// L'application est dans un cadre nommé « Appli ».
const appli = (page) => page.frame({ name: 'Appli' }) || page.mainFrame();

async function connexionXpo(page) {
  await page.goto(XPO_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  const f = appli(page);
  const mdp = f.locator('input[type=password]').first();
  if (!(await mdp.count())) return; // déjà connecté
  // L'identifiant est le premier champ texte de la page de connexion.
  await f.locator('input[type=text], input[type=email], input:not([type])').first().fill(ENV.XPO_IDENTIFIANT);
  await mdp.fill(ENV.XPO_MOT_DE_PASSE);
  await mdp.press('Enter');
  await page.waitForTimeout(4000);
  if (await appli(page).locator('input[type=password]').count()) throw new Error('connexion XPO refusée (identifiants ?)');
}

const coupe = (s, n) => String(s || '').slice(0, n);
const dateXpo = (iso) => (iso ? `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(2, 4)}` : '');

// Saisit une expédition ; en répétition, remplit puis annule. Renvoie {statut, numero, message}.
async function creerXpo(page, x) {
  if (x.places == null) return { statut: 'erreur', message: 'format de palette non accepté par XPO' };
  await appli(page).getByText('Saisie', { exact: true }).first().click();
  await page.waitForTimeout(2500);
  const f = appli(page);
  await f.locator('#Nom').waitFor({ timeout: 15000 });

  const remplir = async (sel, val) => { const c = f.locator(sel); await c.fill(''); if (val !== '') await c.fill(String(val)); };
  // Consignes : 70 caractères en « Instructions », la suite en « Remarques ».
  const consignes = (x.consignes + (x.rdv && !/RDV|RENDEZ/i.test(x.consignes) ? ' PRENDRE RDV' : '')).trim();
  await remplir('#DateExpe', dateXpo(x.date));
  await remplir('#Nom', coupe(x.dest.nom, 35));
  await remplir('#Adresse1', coupe(x.dest.adresse1, 35));
  await remplir('#Adresse2', coupe([x.dest.adresse2, x.dest.complement].filter(Boolean).join(' '), 35));
  await remplir('#CdPostal', x.dest.cp);
  await remplir('#Ville', coupe(x.dest.ville, 35));
  await remplir('#CdInseePays', 'FR');
  await f.locator('#quefairedecedestinataireT').check(); // destinataire temporaire : pas d'ajout au carnet
  await remplir('#NumBl', coupe(x.num, 35));
  await remplir('#nbPalette1', x.palettes);
  await remplir('#nbUnitesTaxables', x.places);
  await remplir('#Poids', x.poids);
  await remplir('#Instructions', coupe(consignes, 70));
  await remplir('#Remarques', coupe(consignes.slice(70), 70));
  // La saisie ouvre la liste du carnet d'adresses XPO, qui reste par-dessus le bouton Valider : on la masque.
  await f.locator('#Nom').press('Escape');
  await f.evaluate(() => {
    const valider = document.querySelector('input[name=FormExpedentCompletSubmitBt]');
    valider.scrollIntoView({ block: 'center' });
    const r = valider.getBoundingClientRect();
    let e = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
    // On ne masque que le cadre flottant (position absolue ou fixe) posé sur le bouton, jamais le formulaire.
    for (; e && e !== valider && !e.contains(valider); e = e.parentElement) {
      if (['absolute', 'fixed'].includes(getComputedStyle(e).position)) { e.style.display = 'none'; return; }
    }
  });

  const capture = path.join(CAPTURES, `${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}-${x.num}.png`);
  await page.screenshot({ path: capture, fullPage: true });

  if (MODE !== 'reel') {
    await f.locator('input[type=reset]').first().click();
    return { statut: 'erreur', message: `répétition : formulaire rempli puis annulé (capture ${path.basename(capture)})` };
  }

  await f.locator('input[name=FormExpedentCompletSubmitBt]').click();
  await page.waitForTimeout(4000);
  const g = appli(page);
  // Formulaire encore affiché avec un message = refus ; sinon l'expédition est dans les Encours.
  const texte = (await g.locator('body').innerText()).replace(/\s+/g, ' ');
  if ((await g.locator('#Nom').count()) && /erreur|obligatoire|invalide|incorrect/i.test(texte)) {
    await page.screenshot({ path: capture.replace('.png', '-refus.png'), fullPage: true });
    const motif = texte.match(/[^.]*(erreur|obligatoire|invalide|incorrect)[^.]*/i);
    return { statut: 'erreur', message: coupe(motif ? motif[0] : 'refusé par XPO', 280) };
  }
  return { statut: 'cree', numero: '', message: 'créée dans Station Chargeur (Encours)' };
}

// ---------- Boucle ----------

async function passage() {
  const { expeditions } = await site('robot_a_faire');
  const xpo = expeditions.filter((x) => x.transporteur === 'XPO');
  if (!xpo.length) return;
  journal(`${xpo.length} expédition(s) XPO à créer (mode ${MODE})`);
  const navigateur = await chromium.launch({ headless: ENV.VISIBLE !== 'oui' });
  try {
    const page = await (await navigateur.newContext({ locale: 'fr-FR', viewport: { width: 1500, height: 900 } })).newPage();
    await connexionXpo(page);
    for (const x of xpo) {
      let res;
      try { res = await creerXpo(page, x); } catch (e) { res = { statut: 'erreur', message: 'robot : ' + coupe(e.message, 250) }; }
      journal(x.num, res.statut, res.message || '');
      await site('robot_resultat', { num: x.num, ...res });
    }
  } catch (e) {
    // Échec général (connexion…) : chaque expédition prise est signalée, pour être relancée depuis le site.
    journal('échec', e.message);
    for (const x of xpo) await site('robot_resultat', { num: x.num, statut: 'erreur', message: 'robot : ' + coupe(e.message, 250) }).catch(() => {});
  } finally {
    await navigateur.close();
  }
}

// Essai à blanc : connexion XPO et saisie d'une expédition inventée, toujours annulée (jamais envoyée).
async function essai() {
  if (MODE === 'reel') throw new Error('essai refusé : repasser MODE=repetition dans .env');
  const navigateur = await chromium.launch({ headless: ENV.VISIBLE !== 'oui' });
  try {
    const page = await (await navigateur.newContext({ locale: 'fr-FR', viewport: { width: 1500, height: 900 } })).newPage();
    await connexionXpo(page);
    journal('connexion XPO réussie');
    const res = await creerXpo(page, {
      num: 'ESSAI-ROBOT', transporteur: 'XPO', date: new Date().toISOString().slice(0, 10),
      dest: { nom: 'ESSAI NE PAS LIVRER', complement: '', adresse1: '1 RUE DE L ESSAI', adresse2: '', cp: '71800', ville: 'LA CLAYETTE', pays: 'FR' },
      palettes: 1, places: 2, poids: 100, consignes: 'ESSAI ROBOT', rdv: false,
    });
    journal('essai :', res.message);
  } finally {
    await navigateur.close();
  }
}

(async () => {
  if (process.argv.includes('--essai')) {
    try { await essai(); } catch (e) { journal('échec essai', e.message); process.exitCode = 1; }
    return;
  }
  journal(`robot démarré (mode ${MODE})`);
  do {
    try { await passage(); } catch (e) { journal('erreur', e.message); }
    if (process.argv.includes('--une-fois')) break;
    await new Promise((ok) => setTimeout(ok, INTERVALLE));
  } while (true);
})();
