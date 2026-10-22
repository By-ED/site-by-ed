<?php
// API de l'outil transport : connexion par code, import de l'export FLOW ONE et du classeur des tarifs,
// calcul, saisies (poids pesé, palettes, choix forcé), réglages, historique, fichiers à télécharger.
// Données en fichiers JSON dans data/ (jamais dans le dépôt : grilles, clients et prix sont confidentiels).
declare(strict_types=1);

require __DIR__ . '/moteur.php';
require __DIR__ . '/classeur.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
// Le « mois en cours » (taxe gasoil, moyenne du mois) est celui de La Clayette, quel que soit le réglage du serveur.
date_default_timezone_set('Europe/Paris');

if (!is_file(__DIR__ . '/config.php')) repondre(500, ['erreur' => 'config', 'message' => 'Le fichier config.php est absent.']);
$C = require __DIR__ . '/config.php';
$DATA = __DIR__ . '/data';

// ---------- Outils ----------

function repondre(int $code, array $donnees): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function erreur(string $message, int $code = 400): never { repondre($code, ['erreur' => 'refus', 'message' => $message]); }

function lireJson(string $chemin, mixed $defaut): mixed
{
    if (!is_file($chemin)) return $defaut;
    $d = json_decode((string) file_get_contents($chemin), true);
    return $d ?? $defaut;
}

function ecrireJson(string $chemin, mixed $donnees): void
{
    $tmp = $chemin . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) === false || !rename($tmp, $chemin)) {
        @unlink($tmp);
        throw new RuntimeException('ecriture');
    }
}

// Une seule écriture à la fois (deux onglets ouverts, double clic).
function avecVerrou(callable $fn): mixed
{
    global $DATA;
    $h = fopen("$DATA/verrous/donnees.lock", 'c');
    flock($h, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

function telecharger(string $nom, string $contenu): never
{
    header('Content-Type: text/csv; charset=windows-1252');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    header('Content-Length: ' . strlen($contenu));
    echo $contenu;
    exit;
}

function reglages(): array { global $DATA; return completerReglages(lireJson("$DATA/reglages.json", [])); }
function grilles(): ?array { global $DATA; return lireJson("$DATA/grilles.json", null); }
function lotEnCours(): ?array { global $DATA; return lireJson("$DATA/lot.json", null); }
function cheminHistorique(string $mois): string { global $DATA; return "$DATA/historique/$mois.json"; }
function historiqueMois(string $mois): array { return lireJson(cheminHistorique($mois), ['expeditions' => []])['expeditions']; }

function moisValide(mixed $m): string
{
    if (!is_string($m) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) erreur('Mois invalide.');
    return $m;
}

// Contenu d'un fichier envoyé par le navigateur (en base64 dans le JSON).
function fichierRecu(array $entree, int $max): string
{
    $b = base64_decode((string) ($entree['contenu'] ?? ''), true);
    if ($b === false || $b === '') erreur('Fichier vide ou illisible.');
    if (strlen($b) > $max) erreur('Fichier trop volumineux.');
    return $b;
}

// Calcul du lot importé, avec l'historique des mois concernés (moyennes du jour et du mois).
function calculLot(array $lot, array $R): array
{
    $export = lireExport(base64_decode($lot['brut']));
    $ix = array_search('ORDEXPEDATE', $export['entete'], true);
    $mois = [];
    foreach ($export['lignes'] as $l) if ($d = dateIso($l['v'][$ix])) $mois[substr($d, 0, 7)] = true;
    $hist = [];
    foreach (array_keys($mois) as $m) $hist = array_merge($hist, array_values(historiqueMois((string) $m)));
    return calculerLot($export, $lot['saisies'] ?? [], $R, grilles(), $hist) + ['fichier' => $lot['fichier'], 'importe_le' => $lot['importe_le'], 'enregistre_le' => $lot['enregistre_le'] ?? null];
}

function etat(): array
{
    global $DATA;
    $R = reglages();
    $lot = lotEnCours();
    $calcul = null;
    $erreurLot = null;
    if ($lot) {
        try { $calcul = calculLot($lot, $R); }
        catch (RuntimeException $e) { $erreurLot = $e->getMessage(); }
    }
    $mois = date('Y-m');
    $recap = recapitulatif(array_values(historiqueMois($mois)));
    $g = grilles();
    return [
        'lot' => $calcul,
        'erreur_lot' => $erreurLot,
        'mois_courant' => ['mois' => $mois, 'libelle' => nomMois($mois), 'taux' => $recap['total']['taux'], 'nb' => $recap['total']['nb'], 'max' => (float) $R['taux']['moyenne_max']],
        'grille' => $g ? ['fichier' => $g['fichier'], 'importe_le' => $g['importe_le']] : null,
        'gasoil_mois' => array_map(fn ($t) => nombre($R['gasoil'][$mois][$t] ?? null), array_combine(TRANSPORTEURS, TRANSPORTEURS)),
        // Logo d'Euro Distribution déposé à côté de index.html (facultatif).
        'logo' => array_values(array_filter(['logo.svg', 'logo.png', 'logo.jpg'], fn ($f) => is_file(__DIR__ . "/$f")))[0] ?? null,
    ];
}

// Enregistre les expéditions attribuées du lot dans l'historique du mois (remplace un enregistrement précédent).
function enregistrerHistorique(array $calcul): int
{
    $parMois = [];
    foreach ($calcul['expeditions'] as $e) if ($e['date']) $parMois[substr($e['date'], 0, 7)][] = $e;
    $n = 0;
    foreach ($parMois as $m => $exps) {
        $h = lireJson(cheminHistorique((string) $m), ['expeditions' => []]);
        foreach ($exps as $e) {
            if ($e['choix']) { $h['expeditions'][$e['num']] = entreeHistorique($e) + ['enregistre_le' => date('c')]; $n++; }
            else unset($h['expeditions'][$e['num']]);
        }
        ecrireJson(cheminHistorique((string) $m), $h);
    }
    return $n;
}

function nomFichierLot(array $calcul): string
{
    $dates = array_keys($calcul['jours']);
    return $dates ? ($dates[0] . (count($dates) > 1 ? '_' . end($dates) : '')) : date('Y-m-d');
}

// ---------- Connexion ----------

function jeton(array $C): string { return hash_hmac('sha256', 'transport-acces-v1', $C['secret'] . '|' . $C['code_acces']); }

function estConnecte(array $C): bool
{
    return isset($_COOKIE['transport_acces']) && hash_equals(jeton($C), (string) $_COOKIE['transport_acces']);
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

foreach (['historique', 'verrous', 'tmp'] as $d) if (!is_dir("$DATA/$d")) @mkdir("$DATA/$d", 0775, true);
if (!is_writable("$DATA/historique")) repondre(500, ['erreur' => 'droits', 'message' => 'Le dossier data/ n\'est pas accessible en écriture.']);

if ($action === 'connexion' && $methode === 'POST') {
    if (!hash_equals((string) $C['code_acces'], (string) ($entree['code'] ?? ''))) {
        sleep(1);
        repondre(401, ['erreur' => 'code']);
    }
    setcookie('transport_acces', jeton($C), [
        'expires' => time() + 400 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    repondre(200, ['ok' => true]);
}

if (!estConnecte($C)) repondre(401, ['erreur' => 'auth']);

try {
    switch ($action) {
        case 'etat':
            repondre(200, etat());

        case 'importer':
            if ($methode !== 'POST') break;
            $brut = fichierRecu($entree, 5 * 1024 * 1024);
            try { $export = lireExport($brut); }
            catch (RuntimeException $e) { erreur($e->getMessage()); }
            avecVerrou(function () use ($brut, $export, $entree) {
                global $DATA;
                ecrireJson("$DATA/lot.json", ['fichier' => mb_substr((string) ($entree['nom'] ?? 'export.csv'), 0, 120), 'importe_le' => date('c'), 'brut' => base64_encode($brut), 'saisies' => (object) []]);
                ecrireJson("$DATA/colonnes.json", $export['entete']);
            });
            repondre(200, etat());

        case 'saisir':
            if ($methode !== 'POST') break;
            avecVerrou(function () use ($entree) {
                global $DATA;
                $lot = lotEnCours() ?? erreur('Aucun export importé.');
                $num = (string) ($entree['num'] ?? '');
                $nums = array_column(construireExpeditions(lireExport(base64_decode($lot['brut'])), [], reglages()), 'num');
                if (!in_array($num, $nums, true)) erreur('Expédition inconnue.');
                $s = (array) (($lot['saisies'] ?? [])[$num] ?? []);
                foreach ((array) ($entree['saisie'] ?? []) as $k => $v) {
                    $v = match ($k) {
                        'poids_pese', 'hauteur' => $v === null || $v === '' ? null : (nombre($v) ?? erreur('Nombre attendu.')),
                        'n120', 'n85', 'n100x100', 'n100x120', 'n100x130', 'n70x240' => $v === null || $v === '' ? null : (($x = nombre($v)) !== null && $x >= 0 && $x <= 50 && floor($x) == $x ? (int) $x : erreur('Nombre de palettes entier attendu.')),
                        'rdv' => $v === null ? null : (bool) $v,
                        'force' => $v === null || $v === '' ? null : (in_array($v, TRANSPORTEURS, true) ? $v : erreur('Transporteur inconnu.')),
                        default => erreur('Champ inconnu.'),
                    };
                    if ($v !== null && in_array($k, ['poids_pese', 'hauteur'], true) && $v <= 0) erreur('Valeur positive attendue.');
                    $s[$k] = $v;
                }
                $saisies = (array) ($lot['saisies'] ?? []);
                $saisies[$num] = array_filter($s, fn ($v) => $v !== null);
                if (!$saisies[$num]) unset($saisies[$num]);
                $lot['saisies'] = (object) $saisies;
                ecrireJson("$DATA/lot.json", $lot);
            });
            repondre(200, etat());

        case 'fermer':
            if ($methode !== 'POST') break;
            avecVerrou(fn () => @unlink("$DATA/lot.json"));
            repondre(200, etat());

        case 'enregistrer':
            if ($methode !== 'POST') break;
            $n = avecVerrou(function () {
                global $DATA;
                $lot = lotEnCours() ?? erreur('Aucun export importé.');
                $calcul = calculLot($lot, reglages());
                if ($calcul['blocages']) erreur('Calcul bloqué : ' . $calcul['blocages'][0]);
                $n = enregistrerHistorique($calcul);
                $lot['enregistre_le'] = date('c');
                ecrireJson("$DATA/lot.json", $lot);
                return $n;
            });
            repondre(200, ['enregistrees' => $n] + etat());

        case 'telecharger':
            $type = (string) ($_GET['type'] ?? '');
            if ($type === 'recap') {
                $mois = moisValide($_GET['mois'] ?? null);
                telecharger("recap-transport-$mois.csv", recapCsv(recapitulatif(array_values(historiqueMois($mois))), $mois));
            }
            [$nom, $contenu] = avecVerrou(function () use ($type) {
                global $DATA;
                $lot = lotEnCours() ?? erreur('Aucun export importé.');
                $R = reglages();
                $calcul = calculLot($lot, $R);
                if ($calcul['blocages']) erreur('Calcul bloqué : ' . $calcul['blocages'][0]);
                if ($type === 'transporteur') {
                    $t = (string) ($_GET['t'] ?? '');
                    if (!in_array($t, TRANSPORTEURS, true)) erreur('Transporteur inconnu.');
                    $r = ["$t-" . nomFichierLot($calcul) . '.csv', fichierTransporteur($t, $calcul, $R)];
                } elseif ($type === 'flowone') {
                    try { $r = ['retour-flowone-' . nomFichierLot($calcul) . '.csv', retourFlowOne(base64_decode($lot['brut']), $calcul, $R)]; }
                    catch (RuntimeException $e) { erreur($e->getMessage()); }
                } else {
                    erreur('Type de fichier inconnu.');
                }
                // Un fichier téléchargé = une journée traitée : elle entre dans l'historique.
                enregistrerHistorique($calcul);
                $lot['enregistre_le'] = date('c');
                ecrireJson("$DATA/lot.json", $lot);
                return $r;
            });
            telecharger($nom, $contenu);

        case 'reglages':
            repondre(200, [
                'reglages' => reglages(),
                'colonnes' => lireJson("$DATA/colonnes.json", []),
                'infos_lecture' => INFOS_LECTURE, 'infos_ecriture' => INFOS_ECRITURE, 'transporteurs' => TRANSPORTEURS,
                'grille' => ($g = grilles()) ? ['fichier' => $g['fichier'], 'importe_le' => $g['importe_le'], 'resume' => $g['resume'], 'alertes' => $g['alertes'], 'reglages_lus' => $g['reglages_lus'] ?? []] : null,
            ]);

        case 'reglages_enregistrer':
            if ($methode !== 'POST') break;
            [$R, $erreurs] = nettoyerReglages((array) ($entree['reglages'] ?? []));
            if ($erreurs) repondre(400, ['erreur' => 'refus', 'message' => implode(' ', $erreurs)]);
            avecVerrou(fn () => ecrireJson("$DATA/reglages.json", $R));
            repondre(200, ['reglages' => reglages()]);

        case 'classeur':
            if ($methode !== 'POST') break;
            $brut = fichierRecu($entree, 20 * 1024 * 1024);
            $tmp = "$DATA/tmp/" . bin2hex(random_bytes(8)) . '.xlsx';
            file_put_contents($tmp, $brut);
            try { $imp = importerClasseur($tmp); }
            catch (RuntimeException $e) { erreur($e->getMessage()); }
            finally { @unlink($tmp); }
            $textes = avecVerrou(function () use ($imp, $entree) {
                global $DATA;
                [$R, $textes] = appliquerReglagesClasseur(reglages(), $imp['reglages']);
                ecrireJson("$DATA/reglages.json", $R);
                ecrireJson("$DATA/grilles.json", [
                    'fichier' => mb_substr((string) ($entree['nom'] ?? 'tarifs.xlsx'), 0, 120), 'importe_le' => date('c'),
                    'grilles' => $imp['grilles'], 'localites' => $imp['localites'], 'resume' => $imp['resume'], 'alertes' => $imp['alertes'], 'reglages_lus' => $textes,
                ]);
                return $textes;
            });
            repondre(200, ['resume' => $imp['resume'], 'alertes' => $imp['alertes'], 'reglages_lus' => $textes]);

        case 'historique':
            $mois = array_map(fn ($f) => basename($f, '.json'), glob("$DATA/historique/*.json") ?: []);
            rsort($mois);
            $m = isset($_GET['mois']) ? moisValide($_GET['mois']) : ($mois[0] ?? date('Y-m'));
            $entrees = array_values(historiqueMois($m));
            usort($entrees, fn ($a, $b) => [$a['date'], $a['num']] <=> [$b['date'], $b['num']]);
            repondre(200, ['mois' => $m, 'libelle' => nomMois($m), 'disponibles' => array_map(fn ($x) => ['mois' => $x, 'libelle' => nomMois($x)], $mois),
                'recap' => recapitulatif($entrees), 'expeditions' => $entrees, 'max' => (float) reglages()['taux']['moyenne_max']]);
    }
} catch (RuntimeException $e) {
    repondre(500, ['erreur' => 'serveur', 'message' => $e->getMessage() === 'ecriture' ? 'Écriture impossible dans data/.' : $e->getMessage()]);
}

repondre(404, ['erreur' => 'action']);
