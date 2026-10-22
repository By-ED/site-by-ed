# QCM J7·J14·J28

Application de QCM de révision pour une étudiante en 3e année de médecine.
Elle ajoute elle-même ses cours ; Claude crée les questions.

## Version hébergée (principale) : `hebergement/`

Site PHP pour l'hébergement O2switch du propriétaire. L'étudiante n'a pas de compte Claude :
elle se connecte avec un code d'accès (lien `?code=...`, cookie signé valable 400 jours).

En ligne : https://qcm.galan-ga.com (domaine supplémentaire o2switch, racine `/home/reev5185/qcm`, certificat AutoSSL).
DNS chez Shopify (galan-ga.com) : enregistrement A `qcm` → 109.234.166.238.
Anciennes adresses abandonnées : qcm.reev5185.odns.fr (o2switch ne délivre pas de certificat pour odns.fr)
et qcm.by-ed.fr (cPanel refuse deux adresses sur le même dossier).
Ne jamais écrire le code d'accès ni la clé API dans ce dépôt.

- `index.html` : toute l'interface (HTML + CSS + JS, pdf.js et JSZip chargés à la demande depuis cdnjs).
- `api.php` : connexion, stockage en fichiers JSON dans `data/`, appels à Claude avec le SDK PHP officiel
  (`anthropic-ai/sdk` + Guzzle, via Composer). Les consignes envoyées à Claude sont écrites côté serveur :
  l'API ne sert qu'à créer des QCM, jamais de relais libre vers Claude.
- `config.php` (non versionné, modèle : `config.exemple.php`) : clé API, code d'accès, secret, modèle, effort, limite d'appels par jour.
  Le propriétaire a choisi Sonnet 5.5 en effort `medium` pour réduire le coût (Opus haut ≈ 0,85 $ pour 33 questions).
- `construire-zip.sh` : produit `qcm-o2switch.zip` (dépendances incluses) à téléverser ; `LISEZMOI.txt` = guide d'installation.
- Création d'un cours : le navigateur extrait le texte (PDF/Word/PowerPoint ; photos et PDF scannés → action `transcrire`).
  Les pages de PDF qui contiennent des images (schémas, tableaux) sont aussi envoyées à `transcrire` en mode `visuels` :
  le texte de ces images n'est pas dans la couche texte du PDF.
  Ensuite `cours_creer` → `plan` (notions + liste des questions, en lots de 6 au plus) → `lot` pour chaque lot, 2 à la fois.
  Les actions longues répondent en NDJSON (`{"progres":n}` puis `{"fin":...}` ou `{"erreur":...}`).
  Le texte du cours est un bloc système mis en cache ; sortie JSON garantie par `output_config.format` ;
  `fallbacks: "default"` (beta `server-side-fallback-2026-07-01`).
- Test local sans clé : un faux serveur Anthropic + `'url_api' => 'http://127.0.0.1:PORT'` dans config.php.

## Première version : artifact claude.ai (`qcm/index.html`)

https://claude.ai/artifact/7kdKqvAfSHsNMESThNSsRg : ne fonctionne que pour quelqu'un connecté à claude.ai
(capacités `db`, `user`, `sample`). Gardée pour mémoire ; l'étudiante utilise la version hébergée.

## Règles des QCM (demandées par l'étudiante, ne pas changer sans elle)

- 5 propositions (A à E) par question.
- Notation par question : 0 faute = 1 point ; 1 faute = 0,5 ; 2 fautes = 0,2 ; 3 fautes ou plus = 0.
  Une faute = une case cochée à tort ou une bonne réponse oubliée. Même barème pour QRU et QCM.
- Mélange de QRU (1 seule vraie) et de QCM ; dans les QCM, 2, 3, 4 ou 5 vraies, réparties au hasard.
- 3 niveaux, présentés dans l'ordre : facile, moyen, difficile. Environ 10 questions par niveau, plus si le cours est long ;
  pour un cours court, 6 par niveau, sans répétition (choix de l'étudiante).
- Les questions couvrent TOUT le cours (révisions espacées J7, J14, J28 sans rouvrir le cours).
- Contenu : uniquement le cours fourni. Les chiffres et valeurs précises sont interrogés.
- Pièges : inversions, chiffres modifiés, notions proches. Pas de pièges à base de « toujours / jamais ».
- Anecdotes du professeur : ignorées, aucune question dessus (choix de l'étudiante).
- Une même phrase du cours n'est jamais découpée en plusieurs propositions ; une notion ne revient que sous un angle nouveau.
- Correction de chaque proposition (vraie ou fausse, avec la formulation correcte) + « À retenir ».
- Modes : entraînement (correction après chaque question) et examen (correction à la fin).
- Ordre des questions et des propositions remélangé à chaque passage.
- Historique des scores par cours, bouton « Refaire mes erreurs ».
- « Nouvelles questions » : nouvelle série sur un cours déjà enregistré (plan avec la liste des angles déjà posés) ;
  l'étudiante choisit Série 1, Série 2… ou Toutes. Champ `serie` sur chaque lot et chaque question (absent = 1).

Matières : Appareil urinaire, Appareil respiratoire, Immunopathologie, Biostatistique, Génétique médicale, PIX (socle numérique).

## Données

Version hébergée (`hebergement/data/`) : `cours/<id>.json` (métadonnées + lots + `couts` : dollars par série, « 1 » = création), `questions/<id>.json`,
`sources/<id>.txt`, `progres.json` (une seule élève), `usage.json` (appels du jour, jetons du mois).

Artifact (base `db`) :

- `cours/<id>` : titre, matiere, creeLe (ISO), statut (`en_cours` | `incomplet` | `pret`), nbQuestions, parNiveau, notions[], lots[]
  (un lot = niveau + items `{angle, type, nbVraies}` + statut ; sert à compléter une création interrompue).
- `questions/<id>` : `{questions: [{id, niveau (1-3), type (QRU|QCM), enonce, propositions: [{id a-e, texte, vrai, explication}], a_retenir}]}`.
- `sources/<id>` : `{texte}` texte du cours, gardé pour « Compléter ».
- `data/users/<uid>/<coursId>` (privé) : `{tentatives: [{date, mode, portee, points, max, parNiveau}], dernier: {questionId: points}}`.

---

# Outil transport : choix du transporteur (`transport/`)

Pour Evan Renouard (Euro Distribution / BY ED, La Clayette). La secrétaire importe l'export FLOW ONE des départs ;
l'outil choisit pour chaque expédition le transporteur le moins cher (BMV, KUEHNE NAGEL, XPO) qui respecte les contraintes,
puis produit un CSV par transporteur et le fichier de retour FLOW ONE. Phase 2 (plus tard) : création des expéditions
sur les sites des transporteurs ; identifiants uniquement dans un `.env` non versionné.

**Le dépôt est public** : jamais de grille tarifaire, d'export FLOW ONE, de nom de client ni de montant de frais dans le dépôt
(tests avec données inventées uniquement). Tout cela vit dans `transport/data/` (non versionné).

- Même principe que le site de QCM : PHP sur O2switch, code d'accès, `config.php` non versionné. Aucune dépendance.
  Adresse choisie : https://transport.galan-ga.com (DNS Shopify : A `transport` → 109.234.166.238, racine `/home/reev5185/transport`) ;
  transport.by-ed.fr écarté pour l'instant (by-ed.fr en maintenance, DNS chez Eolas).
- `moteur.php` : export FLOW ONE (Windows-1252, « ; », CP complété à 5 chiffres, bons regroupés par ORDEXPNUM), prix, choix, taux,
  fichiers de sortie, historique. `classeur.php` : import du classeur Excel « Aide décision » (xlsx lu sans bibliothèque).
  `api.php` : routes. `index.html` : Départs du jour / Historique / Réglages. `php tests/tester.php` : tests (sur le Mac : PHP de MAMP, `/Applications/MAMP/bin/php/php8.3.30/bin/php`). `construire-zip.sh` → zip O2switch.
- Données : `data/reglages.json`, `grilles.json`, `lot.json` (export en cours + saisies), `colonnes.json`, `historique/AAAA-MM.json`.
- Page aux couleurs d'Euro Distribution (by-ed.fr) : noir #020202, crème #F4F2EA, orange #CF4716, polices Questrial/Archivo ;
  variables `--marque*` et `--accent` en tête du CSS de `index.html`. Logo `transport/logo.png` (badge rond, repris de by-ed.fr).

Règles (validées par le propriétaire) :
- Contraintes : BMV 3 palettes, 4 m³, 2,00 m ; KUEHNE 3 palettes, 2,10 m ; XPO 12 places (80×85 = 1 place, 80×120 = 2), 2,20 m.
  Formats de palettes : 80×120, 80×85, 100×100, 100×120, 100×130, 70×240 (`PALETTES`). XPO ne prend que 80×85 et 80×120
  (réglage `formats`) ; BMV et KUEHNE prennent tous les formats.
  Transporteur exclu s'il dépasse ; aucun possible ou palettes manquantes → ligne rouge, rien d'attribué.
- Prix de base = grille × révision × saison × majoration du département + frais (fixes, livraison urbaine, rendez-vous, accès difficile).
  Prix du transport = base × (1 + taxe gasoil) ; la taxe s'applique sur tout. Taux gasoil par mois et par transporteur :
  absent pour le mois d'expédition → calcul bloqué. Choix sur le prix du transport ; la secrétaire peut forcer un autre transporteur valide.
- Grilles au poids (BMV, KUEHNE) : forfaits jusqu'à 100 kg, puis prix aux 100 kg sur le poids arrondi aux 10 kg supérieurs.
  BMV : le prix au kilo continue au-delà de 200 kg ; coefficient « MAJ 01.01 » (révision) appliqué.
  XPO : prix au nombre de places seulement, le poids ne compte pas (confirmé par le propriétaire).
  XPO région parisienne : majoration appliquée une seule fois sur les 8 départements « + taxe Paris et RP », Paris compris (confirmé).
- Taux = prix du transport / ORDHTBASETOTAL. Moyenne pondérée du jour et du mois ; alerte au-dessus de 3,5 %.
  Repère par expédition 3,5 %, 5 % en Bretagne, Sud-Ouest, Sud-Est (listes modifiables).
- Pas de suggestion de répartition des palettes : le propriétaire veut seulement le bon transporteur au meilleur prix
  (« pistes d'économie » retirées à sa demande).
- Retour FLOW ONE : l'export d'origine octet pour octet ; seuls DEOCARRIER, CARRIERDESIGNATION et les colonnes de la table
  de correspondance changent (contrôle avant export). Bons groupés : prix, poids et palettes sur le bon principal, 0 sur les autres.
  Sens de DEONUMERIC01 à 06 : pas encore donné par le propriétaire, ne jamais le deviner.

Robot (phase 2, `transport/robot/`, Node.js + Playwright) : tourne sur un VPS OVHcloud (VPS-1, Canada, Ubuntu 24.04 ; O2switch ne peut pas
piloter un navigateur). Bouton du site « Créer chez les transporteurs » → `data/envois.json` (file d'attente) ; le robot lit `api.php?a=robot_a_faire`
et répond `robot_resultat`, identifié par l'en-tête `X-Jeton-Robot` (= `jeton_robot` de config.php). Identifiants transporteurs dans `robot/.env`.
XPO = Station Chargeur (xpo.station-chargeur.com, éditeur Teliae), cadre « Appli », formulaire `saisie_int_rapide.php` : #DateExpe (JJ/MM/AA),
#Nom, #Adresse1/2, #CdPostal, #Ville, #CdInseePays, #quefairedecedestinataireT (destinataire temporaire), #NumBl (n° d'expédition), #nbPalette1,
#nbUnitesTaxables (places), #Poids, #Instructions et #Remarques (70 car.), bouton FormExpedentCompletSubmitBt. MODE=repetition remplit puis annule.
KUEHNE = même logiciel Station Chargeur (kn-overland.station-chargeur.com/kn-overland/, identifiants KN_*) : même formulaire,
sans unités taxables (champ caché), case #bPrendreRDV. BMV = Besoft Web (besoftweb.groupebmv.com, toujours en https, identifiants BMV_*),
page POSITION/CREATE : #DATE_CONTRAT (JJ/MM/AAAA), #DropDown_IDPRODUIT = MES (Messagerie, choix de la secrétaire), #REF, #NOM_DES, #AD1/2/3_DES,
#IDPAYS_DES, #DPT_DES, #CP_DES, #VILLE_DES, #INSTRUCTION_LIV, #LST_OPTION RDV, #NBCOLIS = nombre de palettes (confirmé), #POIDS ; #BtnEnregistrer_pos / #BtnFermer.
`node robot.js --essai=XPO|KUEHNE|BMV` : connexion + expédition inventée, remplie puis annulée. Service systemd `robot-transport` sur le VPS
(ubuntu@54.39.110.129, clé SSH ~/.ssh/robot_transport_ed25519 du Mac, connexion par mot de passe désactivée).
La secrétaire imprime les étiquettes et transmet les pré-annonces elle-même (Encours).

Classeur Excel (constats) : les prix viennent de « Tarifs à changer chaque année ». La feuille de comparaison contient des erreurs
(KUEHNE ≥ 500 kg décalés de plusieurs lignes ; XPO 76 et 79-85 majorés, 75/77/78 majorés deux fois, 90 multiplié par les frais ;
formule de saison XPO sur d'autres départements avec des dates de 2020) : l'outil ne les reprend pas et les signale à l'import.
Mentions des libellés : feuille de comparaison → KUEHNE (saison, villes de livraison urbaine) ; « Tarifs » → XPO (saison, région parisienne).
Saison (1er mai – 31 août), listes confirmées par le propriétaire : KUEHNE 06, 11, 13, 17, 20, 22, 29, 30, 33, 34, 35, 40, 44, 56, 64, 65, 66, 83, 84, 85 ;
XPO 04, 05, 06, 09, 11, 13, 14, 17, 20, 22, 29, 30, 31, 32, 33, 34, 40, 47, 64, 65, 66, 83, 84.

En attente du propriétaire : colonnes palettes dans l'export (pour l'instant saisie dans l'outil), sens des DEONUMERIC,
codes FLOW ONE de BMV et XPO, formats d'import des transporteurs (CSV provisoire en attendant, `FORMATS_TRANSPORTEUR`),
poids maximum BMV (existe, valeur non donnée).
