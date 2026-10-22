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
