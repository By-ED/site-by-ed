// Robot de création des expéditions chez les transporteurs (pour l'instant : XPO, Station Chargeur).
// Il demande au site transport les expéditions à créer, les saisit sur le site du transporteur comme le ferait
// la secrétaire, puis renvoie le résultat. Il ne transmet pas les pré-annonces : la secrétaire le fait après
// avoir imprimé les étiquettes (Encours). Identifiants dans .env, jamais dans le code ni dans les journaux.
//
// Lancer : node robot.js            (tourne en continu, vérifie toutes les INTERVALLE secondes)
//          node robot.js --une-fois (un seul passage, pour les essais)
//          node robot.js --essai=BMV (connexion + expédition inventée, remplie puis annulée ; XPO par défaut)
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
for (const k of ['SITE_URL', 'JETON_ROBOT']) if (!ENV[k]) throw new Error(`.env : ${k} manquant`);
// Identifiants d'un transporteur : exigés seulement quand le robot travaille pour lui.
function identifiants(prefixe) {
  const id = ENV[`${prefixe}_IDENTIFIANT`], mdp = ENV[`${prefixe}_MOT_DE_PASSE`];
  if (!id || !mdp) throw new Error(`.env : ${prefixe}_IDENTIFIANT ou ${prefixe}_MOT_DE_PASSE manquant`);
  return { id, mdp };
}
const MODE = ENV.MODE === 'reel' ? 'reel' : 'repetition';
const INTERVALLE = Math.max(30, Number(ENV.INTERVALLE || 60)) * 1000;
const XPO_URL = ENV.XPO_URL || 'https://xpo.station-chargeur.com/XPO/';
const BMV_URL = ENV.BMV_URL || 'https://besoftweb.groupebmv.com';
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

// ---------- XPO et KUEHNE NAGEL : Station Chargeur (éditeur Teliae, même formulaire) ----------

const KN_URL = ENV.KN_URL || 'https://kn-overland.station-chargeur.com/kn-overland/';

// L'application est dans un cadre nommé « Appli ».
const appli = (page) => page.frame({ name: 'Appli' }) || page.mainFrame();

async function connexionStation(page, url, prefixe) {
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  const f = appli(page);
  const mdp = f.locator('input[type=password]').first();
  if (!(await mdp.count())) return; // déjà connecté
  const { id, mdp: motDePasse } = identifiants(prefixe);
  // L'identifiant est le premier champ texte de la page de connexion.
  await f.locator('input[type=text], input[type=email], input:not([type])').first().fill(id);
  await mdp.fill(motDePasse);
  await mdp.press('Enter');
  await page.waitForTimeout(4000);
  if (await appli(page).locator('input[type=password]').count()) throw new Error(`connexion ${prefixe} refusée (identifiants ?)`);
}

const coupe = (s, n) => String(s || '').slice(0, n);
const dateXpo = (iso) => (iso ? `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(2, 4)}` : '');

// Saisit une expédition ; en répétition, remplit puis annule. Renvoie {statut, numero, message}.
// XPO compte en places (« Unités taxables ») ; KUEHNE n'a pas ce champ mais une case « Prendre RDV ».
async function creerStation(page, x, nom) {
  if (nom === 'XPO' && x.places == null) return { statut: 'erreur', message: 'format de palette non accepté par XPO' };
  await appli(page).getByText('Saisie', { exact: true }).first().click();
  await page.waitForTimeout(2500);
  const f = appli(page);
  await f.locator('#Nom').waitFor({ timeout: 15000 });

  const remplir = async (sel, val) => { const c = f.locator(sel); await c.fill(''); if (val !== '') await c.fill(String(val)); };
  const caseRdv = f.locator('#bPrendreRDV');
  const aCaseRdv = (await caseRdv.count()) > 0;
  // Consignes : 70 caractères en « Instructions », la suite en « Remarques ».
  const consignes = (x.consignes + (x.rdv && !aCaseRdv && !/RDV|RENDEZ/i.test(x.consignes) ? ' PRENDRE RDV' : '')).trim();
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
  if (await f.locator('#nbUnitesTaxables').isVisible()) await remplir('#nbUnitesTaxables', x.places);
  await remplir('#Poids', x.poids);
  await remplir('#Instructions', coupe(consignes, 70));
  await remplir('#Remarques', coupe(consignes.slice(70), 70));
  if (aCaseRdv && x.rdv) await caseRdv.check();
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

  const capture = path.join(CAPTURES, `${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}-${nom}-${x.num}.png`);
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
    return { statut: 'erreur', message: coupe(motif ? motif[0] : `refusé par ${nom}`, 280) };
  }
  return { statut: 'cree', numero: '', message: 'créée dans Station Chargeur (Encours)' };
}

// ---------- BMV : Besoft Web ----------

// La page de connexion renvoie vers http : on repasse toujours par l'adresse https.
async function connexionBmv(page) {
  await page.goto(`${BMV_URL}/POSITION/CREATE`, { waitUntil: 'domcontentloaded' });
  if (!(await page.locator('#PASSWORD').count())) return; // déjà connecté
  const { id, mdp } = identifiants('BMV');
  await page.goto(`${BMV_URL}/Authentication/Login?ReturnUrl=%2FPOSITION%2FCREATE`, { waitUntil: 'domcontentloaded' });
  await page.locator('#LOGIN').fill(id);
  await page.locator('#PASSWORD').fill(mdp);
  await page.locator('#BtnConnexion').click();
  await page.waitForTimeout(4000);
  if (await page.locator('#PASSWORD').count()) throw new Error('connexion BMV refusée (identifiants ?)');
}

const dateBmv = (iso) => (iso ? `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}` : '');

// Saisit une expédition ; en répétition, remplit puis ferme sans enregistrer. Produit MES (Messagerie), choix de la secrétaire.
async function creerBmv(page, x) {
  await page.goto(`${BMV_URL}/POSITION/CREATE`, { waitUntil: 'domcontentloaded' });
  await page.locator('#NOM_DES').waitFor({ timeout: 15000 });
  const remplir = async (sel, val) => { const c = page.locator(sel); await c.fill(''); if (val !== '') await c.fill(String(val)); };
  const consignes = x.consignes.trim();

  await remplir('#DATE_CONTRAT', dateBmv(x.date));
  await page.locator('#DropDown_IDPRODUIT').selectOption('MES');
  await remplir('#REF', coupe(x.num, 35));
  await remplir('#NOM_DES', coupe(x.dest.nom, 35));
  await remplir('#AD1_DES', coupe(x.dest.adresse1, 35));
  await remplir('#AD2_DES', coupe(x.dest.adresse2, 35));
  await remplir('#AD3_DES', coupe(x.dest.complement, 35));
  await remplir('#IDPAYS_DES', 'FR');
  await remplir('#DPT_DES', String(x.dest.cp).slice(0, 2));
  await remplir('#CP_DES', x.dest.cp);
  await remplir('#VILLE_DES', coupe(x.dest.ville, 35));
  await page.locator('#VILLE_DES').press('Escape');
  await remplir('#INSTRUCTION_LIV', consignes);
  if (x.rdv) await page.locator('#LST_OPTION').selectOption('RDV');
  await remplir('#NBCOLIS', x.palettes);
  await remplir('#POIDS', x.poids);
  await page.locator('#POIDS').press('Tab');

  const capture = path.join(CAPTURES, `${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}-BMV-${x.num}.png`);
  await page.screenshot({ path: capture, fullPage: true });

  if (MODE !== 'reel') {
    page.once('dialog', (d) => d.accept().catch(() => {}));
    await page.locator('#BtnFermer').click();
    return { statut: 'erreur', message: `répétition : formulaire rempli puis fermé sans enregistrer (capture ${path.basename(capture)})` };
  }

  await page.locator('#BtnEnregistrer_pos').click();
  await page.waitForTimeout(4000);
  // Toujours sur la page de création avec un message = refus.
  const texte = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
  if (/POSITION\/CREATE/i.test(page.url()) && /erreur|obligatoire|invalide|incorrect/i.test(texte)) {
    await page.screenshot({ path: capture.replace('.png', '-refus.png'), fullPage: true });
    const motif = texte.match(/[^.]*(erreur|obligatoire|invalide|incorrect)[^.]*/i);
    return { statut: 'erreur', message: coupe(motif ? motif[0] : 'refusé par BMV', 280) };
  }
  return { statut: 'cree', numero: '', message: 'créée dans Besoft Web' };
}

const TRANSPORTEURS = {
  XPO: { connexion: (page) => connexionStation(page, XPO_URL, 'XPO'), creer: (page, x) => creerStation(page, x, 'XPO') },
  KUEHNE: { connexion: (page) => connexionStation(page, KN_URL, 'KN'), creer: (page, x) => creerStation(page, x, 'KUEHNE') },
  BMV: { connexion: connexionBmv, creer: creerBmv },
};

// ---------- Boucle ----------

const nouvellePage = async (navigateur) =>
  (await navigateur.newContext({ locale: 'fr-FR', viewport: { width: 1500, height: 900 } })).newPage();

// Expéditions d'un transporteur, sur une seule session.
async function traiter(navigateur, nom, liste) {
  journal(`${liste.length} expédition(s) ${nom} à créer (mode ${MODE})`);
  const t = TRANSPORTEURS[nom];
  try {
    const page = await nouvellePage(navigateur);
    await t.connexion(page);
    for (const x of liste) {
      let res;
      try { res = await t.creer(page, x); } catch (e) { res = { statut: 'erreur', message: 'robot : ' + coupe(e.message, 250) }; }
      journal(x.num, res.statut, res.message || '');
      await site('robot_resultat', { num: x.num, ...res });
    }
  } catch (e) {
    // Échec général (connexion…) : chaque expédition prise est signalée, pour être relancée depuis le site.
    journal('échec', nom, e.message);
    for (const x of liste) await site('robot_resultat', { num: x.num, statut: 'erreur', message: 'robot : ' + coupe(e.message, 250) }).catch(() => {});
  }
}

async function passage() {
  const { expeditions } = await site('robot_a_faire');
  if (!expeditions.length) return;
  const navigateur = await chromium.launch({ headless: ENV.VISIBLE !== 'oui' });
  try {
    for (const x of expeditions.filter((e) => !TRANSPORTEURS[e.transporteur])) {
      await site('robot_resultat', { num: x.num, statut: 'erreur', message: `robot : ${x.transporteur} pas encore pris en charge` });
    }
    for (const nom of Object.keys(TRANSPORTEURS)) {
      const liste = expeditions.filter((x) => x.transporteur === nom);
      if (liste.length) await traiter(navigateur, nom, liste);
    }
  } finally {
    await navigateur.close();
  }
}

// Essai à blanc (node robot.js --essai=BMV) : connexion et saisie d'une expédition inventée, toujours annulée.
async function essai(nom) {
  if (MODE === 'reel') throw new Error('essai refusé : repasser MODE=repetition dans .env');
  const t = TRANSPORTEURS[nom];
  if (!t) throw new Error(`transporteur inconnu : ${nom}`);
  const navigateur = await chromium.launch({ headless: ENV.VISIBLE !== 'oui' });
  try {
    const page = await nouvellePage(navigateur);
    await t.connexion(page);
    journal(`connexion ${nom} réussie`);
    const res = await t.creer(page, {
      num: 'ESSAI-ROBOT', transporteur: nom, date: new Date().toLocaleDateString('sv-SE'),
      dest: { nom: 'ESSAI NE PAS LIVRER', complement: '', adresse1: '1 RUE DE L ESSAI', adresse2: '', cp: '71800', ville: 'LA CLAYETTE', pays: 'FR' },
      palettes: 1, places: 2, poids: 100, consignes: 'ESSAI ROBOT', rdv: false,
    });
    journal('essai :', res.message);
  } finally {
    await navigateur.close();
  }
}

(async () => {
  const optionEssai = process.argv.find((a) => a.startsWith('--essai'));
  if (optionEssai) {
    const nom = (optionEssai.split('=')[1] || 'XPO').toUpperCase();
    try { await essai(nom); } catch (e) { journal('échec essai', e.message); process.exitCode = 1; }
    return;
  }
  journal(`robot démarré (mode ${MODE})`);
  do {
    try { await passage(); } catch (e) { journal('erreur', e.message); }
    if (process.argv.includes('--une-fois')) break;
    await new Promise((ok) => setTimeout(ok, INTERVALLE));
  } while (true);
})();
