<?php
// API du site de QCM : connexion par code, stockage des cours en fichiers JSON, création des questions avec Claude.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;

const MATIERES = ['Appareil urinaire', 'Appareil respiratoire', 'Immunopathologie', 'Biostatistique', 'Génétique médicale', 'PIX (socle numérique)', 'Autre'];
const NIVEAUX_PL = [1 => 'faciles', 2 => 'moyennes', 3 => 'difficiles'];
const NIVEAU_DESC = [
    1 => 'FACILE : définitions, notions de base, reconnaissance directe d\'une information du cours.',
    2 => 'MOYEN : mécanismes, liens entre notions, comparaisons, application d\'une notion.',
    3 => 'DIFFICILE : détails fins, valeurs chiffrées précises, pièges subtils (inversions, chiffres modifiés, notions proches confondues), raisonnement ou calcul si le cours s\'y prête.',
];
const TEXTE_MAX = 230000;
const IMAGES_MAX = 6;

if (!is_file(__DIR__ . '/config.php')) {
    repondre(500, ['erreur' => 'config', 'message' => 'Le fichier config.php est absent.']);
}
$C = require __DIR__ . '/config.php';
$DATA = __DIR__ . '/data';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// ---------- Outils ----------

function repondre(int $code, array $donnees): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE);
    exit;
}

function lireJson(string $chemin, mixed $defaut): mixed
{
    if (!is_file($chemin)) return $defaut;
    $d = json_decode((string) file_get_contents($chemin), true);
    return $d ?? $defaut;
}

function ecrireJson(string $chemin, mixed $donnees): void
{
    $tmp = $chemin . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($donnees, JSON_UNESCAPED_UNICODE)) === false || !rename($tmp, $chemin)) {
        @unlink($tmp);
        throw new RuntimeException('ecriture');
    }
}

function avecVerrou(string $nom, callable $fn): mixed
{
    global $DATA;
    $h = fopen("$DATA/verrous/$nom.lock", 'c');
    flock($h, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

function idValide(mixed $id): string
{
    if (!is_string($id) || !preg_match('/^c[a-z0-9]{6,40}$/', $id)) repondre(400, ['erreur' => 'id']);
    return $id;
}

function cheminCours(string $id): string { global $DATA; return "$DATA/cours/$id.json"; }
function cheminQuestions(string $id): string { global $DATA; return "$DATA/questions/$id.json"; }
function cheminSource(string $id): string { global $DATA; return "$DATA/sources/$id.txt"; }

function chargerCours(string $id): array
{
    $c = lireJson(cheminCours($id), null);
    if (!is_array($c)) repondre(404, ['erreur' => 'introuvable']);
    return $c;
}

function statutDe(array $lots): string
{
    if (!$lots) return 'analyse';
    foreach ($lots as $l) if (($l['statut'] ?? '') !== 'ok') {
        foreach ($lots as $m) if (in_array($m['statut'] ?? '', ['attente', 'en_cours'], true)) return 'en_cours';
        return 'incomplet';
    }
    return 'pret';
}

// ---------- Connexion ----------

function jeton(array $C): string { return hash_hmac('sha256', 'qcm-acces-v1', $C['secret'] . '|' . $C['code_acces']); }

function estConnecte(array $C): bool
{
    return isset($_COOKIE['qcm_acces']) && hash_equals(jeton($C), (string) $_COOKIE['qcm_acces']);
}

// ---------- Suivi de la consommation ----------

function compterAppel(): void
{
    global $DATA, $C;
    avecVerrou('usage', function () use ($DATA, $C) {
        $u = lireJson("$DATA/usage.json", []);
        $jour = date('Y-m-d');
        $n = (int) ($u['jours'][$jour] ?? 0);
        if ($n >= (int) ($C['appels_max_par_jour'] ?? 60)) repondre(429, ['erreur' => 'limite_jour']);
        $u['jours'] = array_slice(array_filter($u['jours'] ?? [], fn ($k) => $k >= date('Y-m-d', strtotime('-40 days')), ARRAY_FILTER_USE_KEY), -40, null, true);
        $u['jours'][$jour] = $n + 1;
        ecrireJson("$DATA/usage.json", $u);
    });
}

function noterUsage(array $usage): void
{
    global $DATA;
    avecVerrou('usage', function () use ($DATA, $usage) {
        $u = lireJson("$DATA/usage.json", []);
        $mois = date('Y-m');
        $m = $u['mois'][$mois] ?? ['entree' => 0, 'sortie' => 0, 'cache_ecriture' => 0, 'cache_lecture' => 0, 'appels' => 0];
        foreach (['entree', 'sortie', 'cache_ecriture', 'cache_lecture'] as $k) $m[$k] += (int) ($usage[$k] ?? 0);
        $m['appels']++;
        $u['mois'][$mois] = $m;
        ecrireJson("$DATA/usage.json", $u);
    });
}

function coutMois(): array
{
    global $DATA, $C;
    $u = lireJson("$DATA/usage.json", []);
    $m = $u['mois'][date('Y-m')] ?? null;
    if (!$m) return ['dollars' => 0, 'appels' => 0];
    $p = $C['prix'] ?? [];
    $d = 0.0;
    foreach (['entree', 'sortie', 'cache_ecriture', 'cache_lecture'] as $k) $d += ($m[$k] ?? 0) / 1e6 * (float) ($p[$k] ?? 0);
    return ['dollars' => round($d, 2), 'appels' => $m['appels'] ?? 0];
}

// ---------- Flux de réponse (garde la connexion ouverte pendant que Claude écrit) ----------

function ouvrirFlux(): void
{
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('X-Accel-Buffering: no');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) ob_end_flush();
    ignore_user_abort(true);
    set_time_limit(900);
}

function envoyer(array $ligne): void
{
    echo json_encode($ligne, JSON_UNESCAPED_UNICODE) . "\n";
    @flush();
}

function finirEnErreur(string $code, string $message = ''): never
{
    envoyer(['erreur' => $code, 'message' => $message]);
    exit;
}

// ---------- Claude ----------

const ROLE = "Tu prépares des QCM de révision pour une étudiante en 3e année de médecine (France), à partir de son cours, fourni ci-dessous.\n"
    . "Elle révise en espacé (J7, J14, J28) : l'ensemble des questions doit lui faire repasser TOUT le cours sans qu'elle ait besoin de le rouvrir.\n"
    . "Utilise UNIQUEMENT le contenu du cours : aucune information extérieure, même vraie. Écris en français, avec les termes exacts du cours.";

/**
 * Appelle Claude en streaming et renvoie le texte complet.
 * $surTexte est appelé avec le texte reçu jusque-là, pour suivre l'avancement.
 */
function appelerClaude(?string $cours, array $contenu, ?array $schema, string $effort, int $maxTokens, ?callable $surTexte = null): string
{
    global $C;
    compterAppel();
    $client = new Client(apiKey: (string) $C['cle_api'], baseUrl: $C['url_api'] ?? null);
    $system = [['type' => 'text', 'text' => ROLE]];
    if ($cours !== null) {
        // Le cours est identique pour tous les appels d'une création : mis en cache, il n'est payé en entier qu'une fois.
        $system[] = ['type' => 'text', 'text' => "=== COURS ===\n$cours\n=== FIN DU COURS ===", 'cacheControl' => ['type' => 'ephemeral']];
    }
    $outputConfig = ['effort' => $effort];
    if ($schema !== null) $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $schema];

    $texte = '';
    $stop = null;
    $usage = [];
    try {
        $flux = $client->beta->messages->createStream(
            maxTokens: $maxTokens,
            messages: [['role' => 'user', 'content' => $contenu]],
            model: (string) ($C['modele'] ?? 'claude-opus-5-5'),
            system: $system,
            outputConfig: $outputConfig,
            // Si le modèle refuse (filtre de sécurité trop prudent), Anthropic relance la demande sur un autre modèle.
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
        );
        foreach ($flux as $ev) {
            switch ($ev->type) {
                case 'message_start':
                    $u = $ev->message->usage;
                    $usage = ['entree' => $u->inputTokens ?? 0, 'sortie' => $u->outputTokens ?? 0,
                        'cache_ecriture' => $u->cacheCreationInputTokens ?? 0, 'cache_lecture' => $u->cacheReadInputTokens ?? 0];
                    break;
                case 'content_block_delta':
                    if (($ev->delta->type ?? '') === 'text_delta') {
                        $texte .= $ev->delta->text;
                        if ($surTexte) $surTexte($texte);
                    }
                    break;
                case 'message_delta':
                    $stop = $ev->delta->stopReason ?? $stop;
                    $u = $ev->usage;
                    $usage['sortie'] = $u->outputTokens;
                    if ($u->inputTokens !== null) $usage['entree'] = $u->inputTokens;
                    if ($u->cacheCreationInputTokens !== null) $usage['cache_ecriture'] = $u->cacheCreationInputTokens;
                    if ($u->cacheReadInputTokens !== null) $usage['cache_lecture'] = $u->cacheReadInputTokens;
                    break;
            }
        }
    } catch (AuthenticationException|PermissionDeniedException $e) {
        finirEnErreur('cle_api', 'La clé API est refusée par Anthropic.');
    } catch (RateLimitException $e) {
        finirEnErreur('surcharge', 'Trop de demandes en même temps.');
    } catch (BadRequestException $e) {
        $msg = (string) $e->getMessage();
        finirEnErreur(str_contains($msg, 'credit') ? 'credit' : 'requete', $msg);
    } catch (APIStatusException $e) {
        finirEnErreur('surcharge', 'Le service Claude est momentanément indisponible.');
    } catch (APIConnectionException $e) {
        finirEnErreur('connexion', 'Le serveur n\'a pas pu joindre Claude.');
    }
    noterUsage($usage);
    if ($stop === 'refusal') finirEnErreur('refused');
    if ($stop === 'max_tokens') finirEnErreur('tronque');
    return $texte;
}

function schemaPlan(): array
{
    $liste = ['type' => 'array', 'items' => ['type' => 'string']];
    return [
        'type' => 'object',
        'properties' => [
            'titre' => ['type' => 'string'],
            'matiere' => ['type' => 'string', 'enum' => MATIERES],
            'notions' => $liste, 'facile' => $liste, 'moyen' => $liste, 'difficile' => $liste,
        ],
        'required' => ['titre', 'matiere', 'notions', 'facile', 'moyen', 'difficile'],
        'additionalProperties' => false,
    ];
}

function schemaLot(): array
{
    $prop = [
        'type' => 'object',
        'properties' => ['texte' => ['type' => 'string'], 'vrai' => ['type' => 'boolean'], 'explication' => ['type' => 'string']],
        'required' => ['texte', 'vrai', 'explication'],
        'additionalProperties' => false,
    ];
    $question = [
        'type' => 'object',
        'properties' => ['enonce' => ['type' => 'string'], 'propositions' => ['type' => 'array', 'items' => $prop], 'a_retenir' => ['type' => 'string']],
        'required' => ['enonce', 'propositions', 'a_retenir'],
        'additionalProperties' => false,
    ];
    return [
        'type' => 'object',
        'properties' => ['questions' => ['type' => 'array', 'items' => $question]],
        'required' => ['questions'],
        'additionalProperties' => false,
    ];
}

function consignePlan(string $matiere): string
{
    return "Étape demandée : analyser le cours et préparer le plan des questions. Ne rédige pas encore les questions." . ($matiere ? "\nMatière indiquée par l'étudiante : $matiere." : '') . "\n\n"
        . "1. \"titre\" : titre court et précis du cours (celui du document si possible).\n"
        . "2. \"matiere\" : la matière du cours parmi la liste proposée.\n"
        . "3. \"notions\" : la liste EXHAUSTIVE des notions du cours, dans l'ordre du cours : définitions, mécanismes, classifications, valeurs chiffrées et normes, signes, étiologies, exceptions, formules, schémas et tableaux. Une notion = une ligne courte.\n"
        . "4. \"facile\", \"moyen\", \"difficile\" : pour chaque niveau, la liste des questions à poser. Chaque élément décrit en une phrase ce que la question teste (notions et chiffres précis visés).\n"
        . "   - Facile : définitions, notions de base, reconnaissance directe.\n"
        . "   - Moyen : mécanismes, liens entre notions, comparaisons, application.\n"
        . "   - Difficile : détails fins, valeurs chiffrées précises, pièges (inversions, chiffres modifiés, notions proches confondues), cas pratiques, raisonnement ou calcul si le cours s'y prête.\n"
        . "   - Nombre de questions selon la quantité de notions : environ 10 par niveau pour un cours de longueur normale, jusqu'à 15 par niveau si le cours est long et dense. Pour un cours court, 6 par niveau : moins de questions, mais aucune répétition.\n"
        . "   - Chaque notion doit être testée par au moins une question, tous niveaux confondus. Une même notion n'est jamais interrogée deux fois au même niveau ; si elle revient à un autre niveau, c'est sous un angle vraiment différent (définition au facile, comparaison ou mécanisme au moyen, cas pratique ou piège au difficile).\n"
        . "   - Les schémas, tableaux, classifications et listes du cours (y compris la partie « Schémas, tableaux et images » si elle existe) sont des notions à part entière : prévois des questions dessus.\n"
        . "   - Ignore les anecdotes et histoires racontées par le professeur : aucune question sur leurs détails (lieux, chiffres de l'histoire, qui fait quoi le plus souvent…). Elles ne figurent pas non plus dans \"notions\".";
}

function consigneLot(int $niveau, array $items, array $autresAngles): string
{
    $lignes = [];
    foreach ($items as $i => $it) {
        $lignes[] = ($i + 1) . '. ' . $it['angle'] . ' → ' . ($it['type'] === 'QRU'
            ? 'QRU : exactement 1 proposition vraie sur 5'
            : 'QCM : exactement ' . $it['nbVraies'] . ' propositions vraies sur 5');
    }
    $autres = $autresAngles ? "\n\nAutres questions du même QCM, rédigées à part (n'en reprends pas les angles ni les propositions) :\n- " . implode("\n- ", $autresAngles) : '';
    return "Rédige les questions ci-dessous.\n\n"
        . "Règles impératives :\n"
        . "- Chaque question a un énoncé et EXACTEMENT 5 propositions. L'énoncé demande toujours de cocher la ou les propositions EXACTES.\n"
        . "- Respecte EXACTEMENT le type et le nombre de propositions vraies demandés pour chaque question (QRU = une seule vraie ; QCM = le nombre indiqué). L'ordre des propositions sera mélangé ensuite.\n"
        . "- Niveau de ces questions : " . NIVEAU_DESC[$niveau] . "\n"
        . "- Pièges pour les propositions fausses : inversions (augmente/diminue, afférent/efférent, cause/conséquence, droite/gauche, avant/après…), chiffres ou valeurs modifiés (proches mais faux), confusions entre notions proches du cours. N'utilise PAS de mots absolus (« toujours », « jamais », « systématiquement », « uniquement ») comme piège.\n"
        . "- Interroge les chiffres et valeurs précises du cours quand il y en a.\n"
        . "- Les propositions fausses sont plausibles et du même style et de la même longueur que les vraies. Pas de « toutes les réponses » ni de proposition absurde. Une proposition ne donne pas la réponse d'une autre.\n"
        . "- Chaque proposition teste un élément DISTINCT. Ne découpe jamais une même phrase ou une même définition du cours en plusieurs propositions quasi identiques (par exemple trois propositions vraies qui reprennent chacune un mot de la même définition).\n"
        . "- Quand plusieurs propositions sont vraies, elles ne doivent pas se contenter de recopier une liste du cours : chacune demande de reconnaître, d'appliquer ou de distinguer quelque chose.\n"
        . "- Aucune question ni proposition sur les anecdotes du professeur (lieux, chiffres ou personnages d'une histoire racontée en cours).\n"
        . "- \"explication\" pour CHAQUE proposition (1 à 3 phrases) : si elle est vraie, rappelle le contexte utile ; si elle est fausse, donne la formulation correcte avec la bonne valeur. L'étudiante doit pouvoir réviser avec la seule correction, sans rouvrir son cours.\n"
        . "- \"a_retenir\" : 1 à 2 phrases qui résument la notion clé de la question.\n\n"
        . "Questions à rédiger, dans cet ordre :\n" . implode("\n", $lignes) . $autres;
}

function consigneVisuels(array $pages): string
{
    return "Ces images sont des pages d'un cours de médecine (" . implode(', ', $pages) . ", dans cet ordre). Leur texte courant a déjà été extrait séparément.\n"
        . "Transcris UNIQUEMENT ce qui se trouve dans les images, schémas, tableaux, figures et diagrammes de ces pages : tout le texte qu'ils contiennent, "
        . "les tableaux ligne par ligne (cellules séparées par « | », en indiquant les cases cochées), les légendes, et les liens que montre la disposition "
        . "(par exemple quel exemple est rattaché à quelle catégorie d'un schéma). Ne recopie pas les paragraphes de texte courant, ni le surlignage.\n"
        . "Commence chaque page par « [Page …] » avec son numéro. Si une page n'a aucun élément visuel porteur d'information, écris seulement « [Page …] rien à signaler ».";
}

const CONSIGNE_TRANSCRIPTION ="Ces images sont des pages ou des photos d'un cours de médecine. Transcris fidèlement TOUT le texte visible, dans l'ordre de lecture : titres, paragraphes, listes, tableaux (ligne par ligne, cellules séparées par « | »), légendes et annotations des schémas, valeurs chiffrées et unités. Pour un schéma sans texte qui apporte une information, décris-le en une ligne entre crochets. Ne résume pas, n'ajoute rien. Sépare les images par une ligne « --- ».";

function specsPour(int $n): array
{
    $nQru = max(1, (int) round($n * (0.25 + mt_rand() / mt_getrandmax() * 0.12)));
    $specs = array_fill(0, $nQru, ['type' => 'QRU', 'nbVraies' => 1]);
    $sac = [];
    while (count($specs) < $n) {
        if (!$sac) { $sac = [2, 3, 4, 5]; shuffle($sac); }
        $specs[] = ['type' => 'QCM', 'nbVraies' => array_pop($sac)];
    }
    shuffle($specs);
    return $specs;
}

function construireLots(array $plan): array
{
    $lots = [];
    foreach (['facile' => 1, 'moyen' => 2, 'difficile' => 3] as $cle => $niveau) {
        $angles = array_values(array_filter(array_map(fn ($a) => mb_substr(trim((string) $a), 0, 400), (array) ($plan[$cle] ?? []))));
        $angles = array_slice($angles, 0, 15);
        if (!$angles) continue;
        $specs = specsPour(count($angles));
        $items = [];
        foreach ($angles as $i => $angle) $items[] = ['angle' => $angle] + $specs[$i];
        $taille = (int) ceil(count($items) / ceil(count($items) / 6));
        foreach (array_chunk($items, $taille) as $morceau) $lots[] = ['niveau' => $niveau, 'items' => $morceau, 'statut' => 'attente'];
    }
    return $lots;
}

function normaliserQuestion(mixed $r, int $niveau): ?array
{
    if (!is_array($r)) return null;
    $enonce = mb_substr(trim((string) ($r['enonce'] ?? '')), 0, 700);
    $brutes = is_array($r['propositions'] ?? null) ? array_values($r['propositions']) : [];
    if ($enonce === '' || count($brutes) < 5) return null;
    $props = [];
    foreach (array_slice($brutes, 0, 5) as $i => $p) {
        $texte = mb_substr(trim((string) ($p['texte'] ?? '')), 0, 450);
        if ($texte === '') return null;
        $props[] = ['id' => 'abcde'[$i], 'texte' => $texte, 'vrai' => ($p['vrai'] ?? false) === true,
            'explication' => mb_substr(trim((string) ($p['explication'] ?? '')), 0, 800)];
    }
    $nv = count(array_filter($props, fn ($p) => $p['vrai']));
    if ($nv === 0) return null;
    return ['id' => 'q' . bin2hex(random_bytes(5)), 'niveau' => $niveau, 'type' => $nv === 1 ? 'QRU' : 'QCM',
        'enonce' => $enonce, 'propositions' => $props, 'a_retenir' => mb_substr(trim((string) ($r['a_retenir'] ?? '')), 0, 600)];
}

function compteurQuestions(int $total): callable
{
    $dernier = -1;
    return function (string $texte) use (&$dernier, $total) {
        $k = min(max(substr_count($texte, '"enonce"'), 1), $total);
        if ($k !== $dernier) { $dernier = $k; envoyer(['progres' => $k]); }
    };
}

// ---------- Routes ----------

$action = (string) ($_GET['a'] ?? '');
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$entree = [];
if ($methode === 'POST') {
    if (!str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) repondre(415, ['erreur' => 'format']);
    $entree = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($entree)) repondre(400, ['erreur' => 'format']);
}

foreach (['cours', 'questions', 'sources', 'verrous'] as $d) if (!is_dir("$DATA/$d")) @mkdir("$DATA/$d", 0775, true);
if (!is_writable("$DATA/cours")) repondre(500, ['erreur' => 'droits', 'message' => 'Le dossier data/ n\'est pas accessible en écriture.']);

if ($action === 'connexion' && $methode === 'POST') {
    if (!hash_equals((string) $C['code_acces'], (string) ($entree['code'] ?? ''))) {
        sleep(1);
        repondre(401, ['erreur' => 'code']);
    }
    setcookie('qcm_acces', jeton($C), [
        'expires' => time() + 400 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    repondre(200, ['ok' => true]);
}

if (!estConnecte($C)) repondre(401, ['erreur' => 'auth']);

switch ($action) {
    case 'etat':
        $cours = [];
        foreach (glob("$DATA/cours/*.json") ?: [] as $f) {
            $c = lireJson($f, null);
            if (is_array($c)) $cours[] = ['id' => basename($f, '.json')] + $c;
        }
        repondre(200, ['cours' => $cours, 'progres' => (object) lireJson("$DATA/progres.json", []), 'usage' => coutMois()]);

    case 'questions':
        $id = idValide($_GET['id'] ?? null);
        repondre(200, ['questions' => lireJson(cheminQuestions($id), ['questions' => []])['questions']]);

    case 'cours_creer':
        $texte = trim((string) ($entree['texte'] ?? ''));
        if (mb_strlen($texte) < 300) repondre(400, ['erreur' => 'trop_court']);
        if (strlen($texte) > TEXTE_MAX) repondre(400, ['erreur' => 'prompt_too_large']);
        $matiere = in_array($entree['matiere'] ?? '', MATIERES, true) ? $entree['matiere'] : '';
        $id = 'c' . base_convert((string) time(), 10, 36) . bin2hex(random_bytes(3));
        file_put_contents(cheminSource($id), $texte);
        ecrireJson(cheminQuestions($id), ['questions' => []]);
        ecrireJson(cheminCours($id), [
            'titre' => mb_substr(trim((string) ($entree['titre'] ?? '')), 0, 160), 'matiere' => $matiere,
            'creeLe' => gmdate('c'), 'statut' => 'analyse', 'nbQuestions' => 0, 'parNiveau' => ['1' => 0, '2' => 0, '3' => 0],
            'notions' => [], 'lots' => [],
        ]);
        repondre(200, ['id' => $id]);

    case 'transcrire':
        $images = is_array($entree['images'] ?? null) ? array_slice($entree['images'], 0, IMAGES_MAX) : [];
        $contenu = [];
        foreach ($images as $img) {
            $type = (string) ($img['type'] ?? '');
            $data = (string) ($img['data'] ?? '');
            if (!in_array($type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) || $data === '' || strlen($data) > 7000000) repondre(400, ['erreur' => 'image_rejected']);
            $contenu[] = ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $type, 'data' => $data]];
        }
        if (!$contenu) repondre(400, ['erreur' => 'image_rejected']);
        if (($entree['mode'] ?? '') === 'visuels') {
            $pages = array_map(fn ($p) => mb_substr(preg_replace('/[^\pL\pN .,_()\-]/u', '', (string) $p), 0, 80), (array) ($entree['pages'] ?? []));
            $contenu[] = ['type' => 'text', 'text' => consigneVisuels(array_slice($pages, 0, count($contenu)))];
        } else {
            $contenu[] = ['type' => 'text', 'text' => CONSIGNE_TRANSCRIPTION];
        }
        ouvrirFlux();
        $texte = appelerClaude(null, $contenu, null, 'low', 32000);
        envoyer(['fin' => ['texte' => $texte]]);
        exit;

    case 'plan':
        $id = idValide($entree['id'] ?? null);
        $cours = chargerCours($id);
        $source = (string) @file_get_contents(cheminSource($id));
        if ($source === '') repondre(409, ['erreur' => 'source_absente']);
        ouvrirFlux();
        $plan = json_decode(appelerClaude($source, [['type' => 'text', 'text' => consignePlan($cours['matiere'] ?? '')]], schemaPlan(), (string) ($C['effort'] ?? 'high'), 32000), true);
        $lots = is_array($plan) ? construireLots($plan) : [];
        if (!$lots) finirEnErreur('plan');
        $cours = avecVerrou($id, function () use ($id, $plan, $lots) {
            $c = chargerCours($id);
            if (($c['titre'] ?? '') === '') $c['titre'] = mb_substr(trim((string) ($plan['titre'] ?? '')), 0, 160) ?: 'Cours sans titre';
            if (($c['matiere'] ?? '') === '') $c['matiere'] = in_array($plan['matiere'] ?? '', MATIERES, true) ? $plan['matiere'] : 'Autre';
            $c['notions'] = array_slice(array_values(array_filter(array_map(fn ($n) => mb_substr(trim((string) $n), 0, 200), (array) ($plan['notions'] ?? [])))), 0, 200);
            $c['lots'] = $lots;
            $c['statut'] = statutDe($lots);
            ecrireJson(cheminCours($id), $c);
            return $c;
        });
        envoyer(['fin' => ['cours' => ['id' => $id] + $cours]]);
        exit;

    case 'lot':
        $id = idValide($entree['id'] ?? null);
        $index = (int) ($entree['index'] ?? -1);
        $cours = chargerCours($id);
        $lot = $cours['lots'][$index] ?? null;
        if (!is_array($lot)) repondre(400, ['erreur' => 'lot']);
        if (($lot['statut'] ?? '') === 'ok') repondre(200, ['fin' => ['ajoutees' => 0, 'cours' => ['id' => $id] + $cours]]);
        // Un lot lancé il y a moins de 15 minutes tourne peut-être encore : le relancer créerait des doublons.
        if (($lot['statut'] ?? '') === 'en_cours' && time() - (int) ($lot['debut'] ?? 0) < 900) repondre(409, ['erreur' => 'deja_en_cours']);
        $source = (string) @file_get_contents(cheminSource($id));
        if ($source === '') repondre(409, ['erreur' => 'source_absente']);
        $marquer = function (string $statut, array $nouvelles = []) use ($id, $index) {
            return avecVerrou($id, function () use ($id, $index, $statut, $nouvelles) {
                $c = chargerCours($id);
                $qs = lireJson(cheminQuestions($id), ['questions' => []])['questions'];
                array_push($qs, ...$nouvelles);
                if ($nouvelles) ecrireJson(cheminQuestions($id), ['questions' => $qs]);
                $c['lots'][$index]['statut'] = $statut;
                if ($statut === 'en_cours') $c['lots'][$index]['debut'] = time();
                $par = ['1' => 0, '2' => 0, '3' => 0];
                foreach ($qs as $q) $par[(string) $q['niveau']]++;
                $c['nbQuestions'] = count($qs);
                $c['parNiveau'] = $par;
                $c['statut'] = statutDe($c['lots']);
                ecrireJson(cheminCours($id), $c);
                return $c;
            });
        };
        $marquer('en_cours');
        ouvrirFlux();
        // Si l'appel échoue ou que PHP s'arrête en route, le lot est marqué en échec pour pouvoir le refaire avec « Compléter ».
        $termine = false;
        register_shutdown_function(function () use (&$termine, $marquer) { if (!$termine) $marquer('echec'); });
        $niveau = (int) $lot['niveau'];
        $autresAngles = [];
        foreach ($cours['lots'] as $j => $l) {
            if ($j === $index) continue;
            foreach ($l['items'] as $it) $autresAngles[] = '(' . NIVEAUX_PL[(int) $l['niveau']] . ') ' . $it['angle'];
        }
        $brut = json_decode(appelerClaude($source, [['type' => 'text', 'text' => consigneLot($niveau, $lot['items'], $autresAngles)]], schemaLot(),
            (string) ($C['effort'] ?? 'high'), 32000, compteurQuestions(count($lot['items']))), true);
        $nouvelles = array_values(array_filter(array_map(fn ($r) => normaliserQuestion($r, $niveau), (array) ($brut['questions'] ?? []))));
        if (!$nouvelles) finirEnErreur('invalid_json');
        $cours = $marquer('ok', $nouvelles);
        $termine = true;
        envoyer(['fin' => ['ajoutees' => count($nouvelles), 'cours' => ['id' => $id] + $cours]]);
        exit;

    case 'progres':
        $id = (string) ($entree['id'] ?? '');
        if ($id !== 'demo') idValide($id);
        $p = $entree['progres'] ?? null;
        if (!is_array($p) || !is_array($p['tentatives'] ?? null) || strlen(json_encode($p)) > 200000) repondre(400, ['erreur' => 'format']);
        avecVerrou('progres', function () use ($DATA, $id, $p) {
            $tout = lireJson("$DATA/progres.json", []);
            $tout[$id] = ['tentatives' => array_slice($p['tentatives'], -200), 'dernier' => (object) ($p['dernier'] ?? [])];
            ecrireJson("$DATA/progres.json", $tout);
        });
        repondre(200, ['ok' => true]);

    case 'supprimer':
        $id = idValide($entree['id'] ?? null);
        avecVerrou($id, function () use ($id) {
            @unlink(cheminCours($id));
            @unlink(cheminQuestions($id));
            @unlink(cheminSource($id));
        });
        avecVerrou('progres', function () use ($DATA, $id) {
            $tout = lireJson("$DATA/progres.json", []);
            unset($tout[$id]);
            ecrireJson("$DATA/progres.json", $tout);
        });
        repondre(200, ['ok' => true]);
}

repondre(404, ['erreur' => 'action']);
