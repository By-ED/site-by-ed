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

- Même principe que le site de QCM : PHP sur O2switch (adresse à choisir), code d'accès, `config.php` non versionné. Aucune dépendance.
- `moteur.php` : export FLOW ONE (Windows-1252, « ; », CP complété à 5 chiffres, bons regroupés par ORDEXPNUM), prix, choix, taux,
  fichiers de sortie, historique. `classeur.php` : import du classeur Excel « Aide décision » (xlsx lu sans bibliothèque).
  `api.php` : routes. `index.html` : Départs du jour / Historique / Réglages. `php tests/tester.php` : tests. `construire-zip.sh` → zip O2switch.
- Données : `data/reglages.json`, `grilles.json`, `lot.json` (export en cours + saisies), `colonnes.json`, `historique/AAAA-MM.json`.

Règles (validées par le propriétaire) :
- Contraintes : BMV 3 palettes, 4 m³, 2,00 m ; KUEHNE 3 palettes, 2,10 m ; XPO 12 places (80×85 = 1 place, 80×120 = 2), 2,20 m.
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
- Retour FLOW ONE : l'export d'origine octet pour octet ; seuls DEOCARRIER, CARRIERDESIGNATION et les colonnes de la table
  de correspondance changent (contrôle avant export). Bons groupés : prix, poids et palettes sur le bon principal, 0 sur les autres.
  Sens de DEONUMERIC01 à 06 : pas encore donné par le propriétaire, ne jamais le deviner.

Classeur Excel (constats) : les prix viennent de « Tarifs à changer chaque année ». La feuille de comparaison contient des erreurs
(KUEHNE ≥ 500 kg décalés de plusieurs lignes ; XPO 76 et 79-85 majorés, 75/77/78 majorés deux fois, 90 multiplié par les frais ;
formule de saison XPO sur d'autres départements avec des dates de 2020) : l'outil ne les reprend pas et les signale à l'import.
Mentions des libellés : feuille de comparaison → KUEHNE (saison, villes de livraison urbaine) ; « Tarifs » → XPO (saison, région parisienne).
Saison (1er mai – 31 août), listes confirmées par le propriétaire : KUEHNE 06, 11, 13, 17, 20, 22, 29, 30, 33, 34, 35, 40, 44, 56, 64, 65, 66, 83, 84, 85 ;
XPO 04, 05, 06, 09, 11, 13, 14, 17, 20, 22, 29, 30, 31, 32, 33, 34, 40, 47, 64, 65, 66, 83, 84.

En attente du propriétaire : colonnes palettes dans l'export (pour l'instant saisie dans l'outil), sens des DEONUMERIC,
codes FLOW ONE de BMV et XPO, formats d'import des transporteurs (CSV provisoire en attendant, `FORMATS_TRANSPORTEUR`),
poids maximum BMV (existe, valeur non donnée), adresse du site.
