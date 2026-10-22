<?php
// Moteur de l'outil transport : lecture de l'export FLOW ONE, prix des transporteurs, choix du moins cher
// qui respecte les contraintes, taux de transport, fichiers de sortie et historique.
// Aucune donnée commerciale ici : grilles, frais et taux gasoil viennent de data/ (import de l'Excel + réglages).
declare(strict_types=1);

const TRANSPORTEURS = ['BMV', 'KUEHNE', 'XPO'];
// Formats de palettes : surface au sol (m²) et places XPO (80×85 = 1 place, « 0,5 palette » ; 80×120 = 2).
// Les autres formats n'ont pas de places : XPO ne les prend pas.
const PALETTES = [
    'n120' => ['libelle' => '80×120', 'surface' => 0.96, 'places' => 2],
    'n85' => ['libelle' => '80×85', 'surface' => 0.68, 'places' => 1],
    'n100x100' => ['libelle' => '100×100', 'surface' => 1.00, 'places' => null],
    'n100x120' => ['libelle' => '100×120', 'surface' => 1.20, 'places' => null],
    'n100x130' => ['libelle' => '100×130', 'surface' => 1.30, 'places' => null],
    'n70x240' => ['libelle' => '70×240', 'surface' => 1.68, 'places' => null],
];
const COLONNES_REQUISES = ['ORDNOORDER', 'ORDEXPNUM', 'DEOZIPCODE', 'ORDTOTALWEIGHT', 'ORDHTBASETOTAL', 'ORDEXPEDATE', 'DEOCARRIER', 'CARRIERDESIGNATION'];
// Informations lues dans l'export (si la colonne est indiquée dans les réglages) et informations écrites dans le retour FLOW ONE.
const INFOS_LECTURE = [
    'n120' => 'Nombre de palettes 80×120',
    'n85' => 'Nombre de palettes 80×85',
    'n100x100' => 'Nombre de palettes 100×100',
    'n100x120' => 'Nombre de palettes 100×120',
    'n100x130' => 'Nombre de palettes 100×130',
    'n70x240' => 'Nombre de palettes 70×240',
    'hauteur' => 'Hauteur des palettes (m ou cm)',
    'volume' => 'Volume (m³)',
    'poids_pese' => 'Poids pesé (kg)',
];
const INFOS_ECRITURE = [
    'prix_transport' => 'Prix du transport (base + taxe gasoil)',
    'taxe_gasoil' => 'Taxe gasoil appliquée (%)',
    'poids' => 'Poids expédié (kg)',
    'nb_palettes' => 'Nombre de palettes',
    'n120' => 'Nombre de palettes 80×120',
    'n85' => 'Nombre de palettes 80×85',
    'n100x100' => 'Nombre de palettes 100×100',
    'n100x120' => 'Nombre de palettes 100×120',
    'n100x130' => 'Nombre de palettes 100×130',
    'n70x240' => 'Nombre de palettes 70×240',
    'hauteur' => 'Hauteur (m)',
    'numero_suivi' => 'N° d\'expédition / de suivi du transporteur',
];
// Informations d'une expédition : écrites en entier sur le bon principal, 0 sur les autres bons du groupe.
const INFOS_QUANTITES = ['prix_transport', 'poids', 'nb_palettes', 'n120', 'n85', 'n100x100', 'n100x120', 'n100x130', 'n70x240'];
const MOIS_FR = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

// ---------- Réglages ----------

// null = valeur à lire dans l'Excel ou à saisir : le calcul est bloqué tant qu'elle manque.
function reglagesParDefaut(): array
{
    $t = fn (array $c) => array_replace([
        'code_flowone' => '', 'designation_flowone' => '', 'compte' => '',
        'palettes_max' => null, 'places_max' => null, 'volume_max' => null, 'hauteur_max' => null, 'poids_max' => null,
        'coef_revision' => 1.0, 'frais_fixes' => 0.0,
        'formats' => [],
        'saison' => ['pct' => 0.0, 'du' => '05-01', 'au' => '08-31', 'departements' => []],
        'urbain' => ['montant' => 0.0, 'lieux' => []],
        'rdv' => 0.0,
        'majorations' => [],
        'difficile' => ['par_100kg' => 0.0, 'max' => 0.0],
    ], $c);
    return [
        'transporteurs' => [
            'BMV' => $t(['designation_flowone' => 'BMV', 'palettes_max' => 3, 'volume_max' => 4.0, 'hauteur_max' => 2.0,
                'coef_revision' => null, 'frais_fixes' => null]),
            'KUEHNE' => $t(['code_flowone' => '3', 'designation_flowone' => 'KUEHNE', 'palettes_max' => 3, 'hauteur_max' => 2.1,
                'frais_fixes' => null, 'saison' => ['pct' => null, 'du' => '05-01', 'au' => '08-31', 'departements' => []],
                'urbain' => ['montant' => null, 'lieux' => []], 'rdv' => null]),
            'XPO' => $t(['designation_flowone' => 'XPO', 'places_max' => 12, 'hauteur_max' => 2.2, 'formats' => ['n85', 'n120'],
                'frais_fixes' => null, 'saison' => ['pct' => null, 'du' => '05-01', 'au' => '08-31', 'departements' => []],
                'difficile' => ['par_100kg' => null, 'max' => null]]),
        ],
        'gasoil' => (object) [],
        'taux' => [
            'moyenne_min' => 3.0, 'moyenne_max' => 3.5, 'seuil' => 3.5, 'seuil_zones' => 5.0,
            'zones' => [
                'Bretagne' => ['22', '29', '35', '56'],
                'Sud-Ouest' => ['09', '12', '16', '17', '24', '31', '32', '33', '40', '46', '47', '64', '65', '81', '82'],
                'Sud-Est' => ['04', '05', '06', '11', '13', '30', '34', '48', '66', '83', '84'],
            ],
        ],
        'expediteur' => ['nom' => '', 'adresse1' => '', 'adresse2' => '', 'cp' => '', 'ville' => '', 'pays' => 'FR', 'telephone' => '', 'contact' => ''],
        'lecture' => array_fill_keys(array_keys(INFOS_LECTURE), ''),
        'ecriture' => array_fill_keys(array_keys(INFOS_ECRITURE), ''),
    ];
}

// Réglages enregistrés complétés par les valeurs par défaut (une clé ajoutée plus tard reste lisible).
function completerReglages(array $r): array
{
    $d = reglagesParDefaut();
    $fusion = function (array $def, array $val) use (&$fusion): array {
        foreach ($def as $k => $v) {
            if (!array_key_exists($k, $val)) { $val[$k] = $v; continue; }
            if (is_array($v) && $v && !array_is_list($v) && is_array($val[$k])) $val[$k] = $fusion($v, $val[$k]);
        }
        return $val;
    };
    $r = $fusion($d, $r);
    $r['gasoil'] = (array) $r['gasoil'];
    // Codes département toujours en texte sur deux caractères (« 6 » ou 6 → « 06 ») : JSON et PHP en font parfois des nombres.
    // Les listes peuvent arriver en texte depuis l'écran Réglages (« 04, 05 », « 69 LYON ») : nettoyerReglages les découpe.
    $deps = fn (mixed $l) => array_values(array_unique(array_filter(array_map('codeDep', is_array($l) ? $l : preg_split('/[^0-9A-Za-z]+/', (string) $l)))));
    foreach ($r['transporteurs'] as &$t) {
        $t['saison']['departements'] = $deps($t['saison']['departements']);
        $t['urbain']['lieux'] = array_map(fn ($l) => is_array($l) ? ['dep' => codeDep($l['dep'] ?? ''), 'ville' => (string) ($l['ville'] ?? '')] : $l, (array) $t['urbain']['lieux']);
        $maj = [];
        foreach ($t['majorations'] as $k => $v) $maj[codeDep($k)] = (float) $v;
        $t['majorations'] = $maj;
    }
    unset($t);
    foreach ($r['taux']['zones'] as $k => $l) $r['taux']['zones'][$k] = $deps($l);
    return $r;
}

function codeDep(mixed $x): string
{
    $s = strtoupper(trim((string) $x));
    return ctype_digit($s) && strlen($s) === 1 ? '0' . $s : $s;
}

// Contrôle et remise en forme des réglages envoyés par l'écran Réglages. Renvoie [réglages, erreurs].
function nettoyerReglages(array $in): array
{
    $R = completerReglages($in);
    $err = [];
    $num = function (mixed $v, string $lib, bool $obligatoire = false) use (&$err): ?float {
        if ($v === null || $v === '') {
            if ($obligatoire) $err[] = "$lib : valeur obligatoire.";
            return null;
        }
        $x = nombre($v);
        if ($x === null || $x < 0) { $err[] = "$lib : nombre positif attendu."; return null; }
        return $x;
    };
    $texte = fn (mixed $v, int $max = 80) => mb_substr(trim((string) $v), 0, $max);
    $deps = fn (mixed $v) => array_values(array_unique(array_filter(array_map('codeDep', is_array($v) ? $v : preg_split('/[^0-9A-Za-z]+/', (string) $v)))));
    $mmjj = function (mixed $v, string $lib) use (&$err): string {
        $v = trim((string) $v);
        if (preg_match('#^(\d{1,2})/(\d{1,2})$#', $v, $m)) $v = sprintf('%02d-%02d', $m[2], $m[1]);
        if (!preg_match('/^(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[1], (int) $m[2], 2024)) { $err[] = "$lib : date JJ/MM attendue."; return '01-01'; }
        return $v;
    };
    foreach (TRANSPORTEURS as $t) {
        $x = &$R['transporteurs'][$t];
        foreach (['code_flowone' => 20, 'designation_flowone' => 40, 'compte' => 40] as $k => $m) $x[$k] = $texte($x[$k], $m);
        foreach (['palettes_max' => 'palettes max', 'places_max' => 'places max', 'volume_max' => 'volume max', 'hauteur_max' => 'hauteur max', 'poids_max' => 'poids max'] as $k => $lib)
            $x[$k] = $num($x[$k], "$t, $lib");
        foreach (['coef_revision' => 'révision tarifaire', 'frais_fixes' => 'frais fixes', 'rdv' => 'rendez-vous'] as $k => $lib) $x[$k] = $num($x[$k], "$t, $lib");
        $x['saison'] = ['pct' => $num($x['saison']['pct'] ?? null, "$t, saison"), 'du' => $mmjj($x['saison']['du'] ?? '', "$t, début de saison"),
            'au' => $mmjj($x['saison']['au'] ?? '', "$t, fin de saison"), 'departements' => $deps($x['saison']['departements'] ?? [])];
        $lieux = [];
        foreach ((array) ($x['urbain']['lieux'] ?? []) as $l) {
            if (is_string($l)) $l = preg_match('/^\s*(\d{1,2}|2[AB])\s+(.+?)\s*$/i', $l, $m) ? ['dep' => $m[1], 'ville' => $m[2]] : null;
            if (!is_array($l) || trim((string) ($l['ville'] ?? '')) === '') continue;
            $lieux[] = ['dep' => codeDep($l['dep'] ?? ''), 'ville' => strtoupper($texte($l['ville'], 60)) === 'TOUT' ? '*' : $texte($l['ville'], 60)];
        }
        $x['urbain'] = ['montant' => $num($x['urbain']['montant'] ?? null, "$t, livraison urbaine"), 'lieux' => $lieux];
        $maj = [];
        foreach ((array) $x['majorations'] as $d => $p) if (($p = $num($p, "$t, majoration $d")) !== null && codeDep($d) !== '') $maj[codeDep($d)] = $p;
        $x['majorations'] = $maj;
        $x['formats'] = array_values(array_intersect((array) $x['formats'], array_keys(PALETTES)));
        $x['difficile'] = ['par_100kg' => $num($x['difficile']['par_100kg'] ?? null, "$t, accès difficile"), 'max' => $num($x['difficile']['max'] ?? null, "$t, accès difficile maximum")];
    }
    unset($x);
    $gasoil = [];
    foreach ((array) $R['gasoil'] as $m => $taux) {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $m)) { $err[] = "Taxe gasoil : mois $m invalide."; continue; }
        $ligne = [];
        foreach (TRANSPORTEURS as $t) if (($v = $num(((array) $taux)[$t] ?? null, 'Taxe gasoil ' . nomMois((string) $m) . " $t")) !== null) $ligne[$t] = $v;
        if ($ligne) $gasoil[(string) $m] = $ligne;
    }
    ksort($gasoil);
    $R['gasoil'] = $gasoil;
    foreach (['moyenne_min' => 'moyenne basse', 'moyenne_max' => 'moyenne haute', 'seuil' => 'repère général', 'seuil_zones' => 'repère des zones chères'] as $k => $lib)
        $R['taux'][$k] = $num($R['taux'][$k], "Taux de transport, $lib", true) ?? reglagesParDefaut()['taux'][$k];
    foreach ($R['taux']['zones'] as $z => $l) $R['taux']['zones'][$z] = $deps($l);
    foreach ($R['expediteur'] as $k => $v) $R['expediteur'][$k] = $texte($v);
    foreach (['lecture' => INFOS_LECTURE, 'ecriture' => INFOS_ECRITURE] as $sens => $infos) {
        $R[$sens] = array_intersect_key(array_map(fn ($c) => $texte($c, 60), $R[$sens]), $infos) + array_fill_keys(array_keys($infos), '');
    }
    $vues = [];
    foreach ($R['ecriture'] as $info => $col) {
        if ($col === '') continue;
        if (in_array($col, COLONNES_REQUISES, true)) $err[] = "Correspondance : la colonne $col sert au calcul ou au transporteur, elle ne peut pas recevoir « " . INFOS_ECRITURE[$info] . ' ».';
        if (isset($vues[$col])) $err[] = "Correspondance : la colonne $col est choisie deux fois.";
        $vues[$col] = true;
    }
    return [$R, $err];
}

// Liste des réglages encore vides qui empêchent le calcul.
function reglagesManquants(array $R): array
{
    $m = [];
    foreach (TRANSPORTEURS as $t) {
        $x = $R['transporteurs'][$t];
        foreach (['coef_revision' => 'coefficient de révision', 'frais_fixes' => 'frais fixes par expédition', 'rdv' => 'frais de rendez-vous'] as $k => $lib)
            if ($x[$k] === null) $m[] = "$t : $lib";
        if ($x['saison']['pct'] === null) $m[] = "$t : majoration saisonnière";
        if ($x['urbain']['montant'] === null) $m[] = "$t : frais de livraison urbaine";
        if ($x['difficile']['par_100kg'] === null || $x['difficile']['max'] === null) $m[] = "$t : majoration accès difficile";
    }
    return $m;
}

// ---------- Outils ----------

function nombre(mixed $s): ?float
{
    if (is_int($s) || is_float($s)) return (float) $s;
    $s = str_replace([' ', "\u{a0}", ','], ['', '', '.'], trim((string) $s));
    return $s !== '' && is_numeric($s) ? (float) $s : null;
}

// Nombre à la française pour les messages : 1 234,5.
function fr(float $x, int $dec = 2): string
{
    $s = number_format($x, $dec, ',', "\u{202f}");
    return str_contains($s, ',') ? rtrim(rtrim($s, '0'), ',') : $s;
}

// Nombre pour FLOW ONE : point décimal, sans zéros inutiles (34.27, 16.8, 100).
function nombreFlowOne(float $x): string
{
    $s = number_format($x, 2, '.', '');
    return rtrim(rtrim($s, '0'), '.');
}

// Hauteur saisie en mètres (1,80) ou en centimètres (180).
function hauteurM(?float $h): ?float
{
    if ($h === null || $h <= 0) return null;
    return $h > 10 ? $h / 100 : $h;
}

function dateIso(string $s): ?string
{
    $s = trim($s);
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $s, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3]))
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return $s;
    return null;
}

function nomMois(string $mois): string
{
    [$a, $m] = explode('-', $mois);
    return MOIS_FR[(int) $m] . ' ' . $a;
}

function dateFr(string $iso): string { return substr($iso, 8, 2) . '/' . substr($iso, 5, 2) . '/' . substr($iso, 0, 4); }

// Code postal sur 5 chiffres : FLOW ONE perd le zéro initial (6510 → 06510).
function codePostal(string $cp): ?string
{
    $cp = preg_replace('/\D/', '', $cp);
    if ($cp === '' || strlen($cp) > 5) return null;
    return str_pad($cp, 5, '0', STR_PAD_LEFT);
}

// Nom de commune comparable : majuscules sans accents, ST → SAINT, ponctuation → espaces.
function normaliserVille(string $v): string
{
    $v = strtoupper(strtr($v, [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y', 'œ' => 'oe',
        'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I',
        'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Œ' => 'OE',
    ]));
    $v = trim((string) preg_replace('/[^A-Z0-9]+/', ' ', $v));
    $v = (string) preg_replace(['/\bSTE\b/', '/\bST\b/'], ['SAINTE', 'SAINT'], $v);
    return $v;
}

function departementDe(string $cp5): string { return substr($cp5, 0, 2); }

// ---------- CSV FLOW ONE ----------

// Découpe un CSV en lignes de champs bruts (octets d'origine, guillemets compris) : un champ non modifié
// est réécrit octet pour octet dans le fichier de retour.
function decouperCsv(string $brut, string $sep = ';'): array
{
    $lignes = [];
    $champs = [];
    $champ = '';
    $guill = false;
    $n = strlen($brut);
    for ($i = 0; $i < $n; $i++) {
        $c = $brut[$i];
        if ($guill) {
            $champ .= $c;
            if ($c === '"') {
                if ($i + 1 < $n && $brut[$i + 1] === '"') { $champ .= '"'; $i++; }
                else $guill = false;
            }
        } elseif ($c === '"') {
            $guill = true;
            $champ .= $c;
        } elseif ($c === $sep) {
            $champs[] = $champ;
            $champ = '';
        } elseif ($c === "\r" || $c === "\n") {
            if ($c === "\r" && $i + 1 < $n && $brut[$i + 1] === "\n") $i++;
            $champs[] = $champ;
            $lignes[] = $champs;
            $champs = [];
            $champ = '';
        } else {
            $champ .= $c;
        }
    }
    if ($champ !== '' || $champs) { $champs[] = $champ; $lignes[] = $champs; }
    return $lignes;
}

function valeurChamp(string $brut): string
{
    if (strlen($brut) >= 2 && $brut[0] === '"' && str_ends_with($brut, '"')) $brut = str_replace('""', '"', substr($brut, 1, -1));
    return mb_convert_encoding($brut, 'UTF-8', 'Windows-1252');
}

function champBrut(string $valeur, string $sep = ';'): string
{
    $b = mb_convert_encoding($valeur, 'Windows-1252', 'UTF-8');
    if (strpbrk($b, $sep . "\"\r\n") !== false) $b = '"' . str_replace('"', '""', $b) . '"';
    return $b;
}

// Lit l'export FLOW ONE (Windows-1252, « ; »). Lève une exception avec un message clair si le fichier ne convient pas.
function lireExport(string $brut): array
{
    if (str_starts_with($brut, "\xEF\xBB\xBF") || (preg_match('/[\x80-\xFF]/', $brut) && mb_check_encoding($brut, 'UTF-8')))
        throw new RuntimeException('Ce fichier a été réenregistré (encodage UTF-8, par Excel par exemple). Importez l\'export tel qu\'il sort de FLOW ONE, sans l\'ouvrir avant.');
    $lignes = decouperCsv($brut);
    if (count($lignes) < 2) throw new RuntimeException('Le fichier est vide.');
    $entete = array_map(fn ($c) => trim(valeurChamp($c)), $lignes[0]);
    $manque = array_diff(COLONNES_REQUISES, $entete);
    if ($manque) throw new RuntimeException('Ce n\'est pas l\'export « Saisie des préparations expédition » de FLOW ONE : colonnes absentes ' . implode(', ', $manque) . '.');
    $donnees = [];
    foreach (array_slice($lignes, 1) as $i => $l) {
        if (count($l) === 1 && trim($l[0]) === '') continue;
        if (count($l) !== count($entete)) throw new RuntimeException('Ligne ' . ($i + 2) . ' : ' . count($l) . ' colonnes au lieu de ' . count($entete) . '.');
        $donnees[] = ['n' => $i + 1, 'v' => array_map('valeurChamp', $l)];
    }
    if (!$donnees) throw new RuntimeException('Le fichier ne contient aucun bon de commande.');
    return ['entete' => $entete, 'lignes' => $donnees];
}

// ---------- Expéditions ----------

// Regroupe les bons par ORDEXPNUM : une expédition = un ou plusieurs bons partis ensemble.
function construireExpeditions(array $export, array $saisies, array $R): array
{
    $ix = array_flip($export['entete']);
    $val = fn (array $l, string $col) => isset($ix[$col]) ? trim($l['v'][$ix[$col]]) : '';
    $groupes = [];
    foreach ($export['lignes'] as $l) {
        $num = $val($l, 'ORDEXPNUM') ?: $val($l, 'ORDNOORDER');
        $groupes[$num][] = $l;
    }
    $exps = [];
    foreach ($groupes as $num => $ls) {
        $num = (string) $num;
        $p = $ls[0];
        foreach ($ls as $l) if ($val($l, 'ORDNOORDER') === $num) $p = $l;
        $e = [
            'num' => $num,
            'bons' => array_map(fn ($l) => $val($l, 'ORDNOORDER'), $ls),
            'principal' => $val($p, 'ORDNOORDER'),
            'lignes' => array_map(fn ($l) => $l['n'], $ls),
            'client' => $val($p, 'CUSNAME'),
            'dest' => [
                'nom' => $val($p, 'DEONAME') ?: $val($p, 'CUSNAME'), 'complement' => $val($p, 'DEOFIRSTNAME'),
                'adresse1' => $val($p, 'DEOADDRESS1'), 'adresse2' => $val($p, 'DEOADDRESS2'),
                'cp' => '', 'ville' => $val($p, 'DEOCITY'),
            ],
            'consignes' => implode(' / ', array_values(array_unique(array_filter(array_map(fn ($l) => $val($l, 'DEOCOMMENT'), $ls))))),
            'refs' => implode(' / ', array_values(array_unique(array_filter(array_map(fn ($l) => $val($l, 'ORDREFCUSORDER'), $ls))))),
            'date' => dateIso($val($p, 'ORDEXPEDATE')),
            'date_liv' => dateIso($val($p, 'ORDDELIVERYDATE')),
            'ht' => round(array_sum(array_map(fn ($l) => nombre($val($l, 'ORDHTBASETOTAL')) ?? 0.0, $ls)), 2),
            'poids_export' => round(array_sum(array_map(fn ($l) => nombre($val($l, 'ORDTOTALWEIGHT')) ?? 0.0, $ls)), 3),
            'transporteur_export' => trim($val($p, 'DEOCARRIER') . ' ' . $val($p, 'CARRIERDESIGNATION')),
            'erreurs' => [],
            'notes' => [],
        ];
        if ($e['principal'] !== $num) $e['notes'][] = "Aucun bon n° $num dans le groupe : le bon {$e['principal']} sert de bon principal.";
        $cp = codePostal($val($p, 'DEOZIPCODE'));
        $e['dest']['cp'] = $cp ?? $val($p, 'DEOZIPCODE');
        $e['dep'] = $cp ? departementDe($cp) : null;
        if ($e['dest']['complement'] !== '' && normaliserVille($e['dest']['complement']) === normaliserVille($e['dest']['ville'])) $e['dest']['complement'] = '';

        // Données lues dans l'export (colonnes indiquées dans les réglages), puis corrections saisies dans l'outil.
        $lu = [];
        foreach (INFOS_LECTURE as $k => $_) {
            $col = $R['lecture'][$k] ?? '';
            if ($col === '' || !isset($ix[$col])) continue;
            $vals = array_filter(array_map(fn ($l) => nombre($val($l, $col)), $ls), fn ($x) => $x !== null);
            if (!$vals) continue;
            $lu[$k] = $k === 'hauteur' ? max($vals) : array_sum($vals);
        }
        $s = $saisies[$num] ?? [];
        $choisir = fn (string $k) => array_key_exists($k, $s) && $s[$k] !== null && $s[$k] !== '' ? ['saisie', nombre($s[$k])] : (isset($lu[$k]) ? ['export', $lu[$k]] : [null, null]);
        [$srcPoids, $poidsPese] = $choisir('poids_pese');
        [$srcH, $h] = $choisir('hauteur');
        [, $vol] = $choisir('volume');
        $e['saisie'] = ['poids_pese' => $poidsPese, 'hauteur' => hauteurM($h), 'rdv' => $s['rdv'] ?? null, 'force' => $s['force'] ?? null,
            'sources' => ['poids_pese' => $srcPoids, 'hauteur' => $srcH]];
        foreach (PALETTES as $k => $_) {
            [$src, $n] = $choisir($k);
            $e['saisie'][$k] = $n === null ? null : (int) round($n);
            $e['saisie']['sources'][$k] = $src;
        }
        $e['poids'] = $poidsPese !== null && $poidsPese > 0 ? $poidsPese : $e['poids_export'];
        $e['rdv_auto'] = (bool) preg_match('/\bRDV\b|RENDEZ[\s-]*VOUS/i', $e['consignes']);
        $e['rdv'] = is_bool($e['saisie']['rdv']) ? $e['saisie']['rdv'] : $e['rdv_auto'];

        $pal = array_map(fn ($k) => $e['saisie'][$k] ?? 0, array_combine(array_keys(PALETTES), array_keys(PALETTES)));
        if (nbPalettes($pal) > 0 && $e['saisie']['hauteur'] !== null) {
            $e['palettes'] = $pal + ['hauteur' => $e['saisie']['hauteur'],
                'volume' => round($vol !== null && $vol > 0 ? $vol : surfacePalettes($pal) * $e['saisie']['hauteur'], 3)];
        } else {
            $e['palettes'] = null;
            $e['erreurs'][] = 'Données palettes manquantes';
        }
        if ($e['date'] === null) $e['erreurs'][] = 'Date d\'expédition illisible';
        if ($e['dep'] === null) $e['erreurs'][] = 'Code postal illisible';
        if ($e['poids'] <= 0) $e['erreurs'][] = 'Poids manquant';
        $exps[] = $e;
    }
    return $exps;
}

// ---------- Prix ----------

// Prix de la grille seule (sans frais ni taxe), ou null si l'envoi sort de la grille.
function prixGrille(array $g, float $poids, int $places): ?array
{
    if (isset($g['places'])) {
        if ($places < 1 || $places > count($g['places']) || $g['places'][$places - 1] === null) return null;
        return [(float) $g['places'][$places - 1], 'Grille ' . $places . ' place' . ($places > 1 ? 's' : '') . ' (80×85 = 1, 80×120 = 2)'];
    }
    foreach ($g['forfaits'] as $b) if ($poids <= $b['a'] + 1e-9) return [(float) $b['prix'], "Grille {$b['de']} à {$b['a']} kg"];
    // Au-delà des forfaits : prix aux 100 kg, poids arrondi aux 10 kg supérieurs (124 kg → 130 kg).
    $p10 = (int) (ceil(round($poids, 3) / 10) * 10);
    foreach ($g['par100'] as $b) {
        if ($b['a'] === null || $p10 <= $b['a'])
            return [$b['taux'] * $p10 / 100, 'Grille ' . ($b['a'] === null ? 'plus de ' . $b['de'] : $b['de'] . ' à ' . $b['a']) . ' kg : ' . fr((float) $b['taux']) . " €/100 kg × $p10 kg"];
    }
    return null;
}

function enSaison(array $saison, string $date): bool
{
    $md = substr($date, 5);
    return $saison['du'] <= $saison['au'] ? ($md >= $saison['du'] && $md <= $saison['au']) : ($md >= $saison['du'] || $md <= $saison['au']);
}

function lieuUrbain(array $lieux, string $dep, string $ville): ?string
{
    $v = normaliserVille($ville);
    foreach ($lieux as $l) {
        if (codeDep($l['dep'] ?? '') !== $dep) continue;
        if ($l['ville'] === '*') return 'tout le département ' . $dep;
        $n = normaliserVille($l['ville']);
        if ($v === $n || preg_match('/^' . preg_quote($n, '/') . ' (CEDEX|\d)/', $v)) return $l['ville'];
    }
    return null;
}

function nbPalettes(array $pal): int
{
    return (int) array_sum(array_map(fn ($k) => $pal[$k] ?? 0, array_keys(PALETTES)));
}

function surfacePalettes(array $pal): float
{
    return array_sum(array_map(fn ($k, $f) => ($pal[$k] ?? 0) * $f['surface'], array_keys(PALETTES), PALETTES));
}

// Places XPO (80×85 = 1, 80×120 = 2) ; null dès qu'un format n'a pas de places.
function placesXpo(array $pal): ?int
{
    $n = 0;
    foreach (PALETTES as $k => $f) {
        if (($pal[$k] ?? 0) === 0) continue;
        if ($f['places'] === null) return null;
        $n += $pal[$k] * $f['places'];
    }
    return $n;
}

// Prix d'un transporteur pour une expédition, ou les raisons de son exclusion.
// Prix de base = grille × révision × saison × majoration + frais ; prix du transport = base × (1 + taxe gasoil).
function offre(string $t, array $e, array $pal, array $R, array $G, float $gasoil): array
{
    $tr = $R['transporteurs'][$t];
    $nb = nbPalettes($pal);
    $places = placesXpo($pal);
    $exclu = [];
    foreach (PALETTES as $k => $f)
        if (($pal[$k] ?? 0) > 0 && $tr['formats'] && !in_array($k, $tr['formats'], true)) $exclu[] = "palettes {$f['libelle']} non acceptées";
    if ($tr['palettes_max'] !== null && $nb > $tr['palettes_max']) $exclu[] = "$nb palettes (max " . fr((float) $tr['palettes_max']) . ')';
    if ($tr['places_max'] !== null && $places !== null && $places > $tr['places_max'])
        $exclu[] = "$places places (max " . fr((float) $tr['places_max']) . ' : 80×85 = 1 place, 80×120 = 2)';
    if ($tr['volume_max'] !== null && $pal['volume'] > $tr['volume_max'] + 1e-9) $exclu[] = 'volume ' . fr($pal['volume']) . ' m³ (max ' . fr((float) $tr['volume_max']) . ' m³)';
    if ($tr['hauteur_max'] !== null && $pal['hauteur'] > $tr['hauteur_max'] + 1e-9) $exclu[] = 'hauteur ' . fr($pal['hauteur']) . ' m (max ' . fr((float) $tr['hauteur_max']) . ' m)';
    if ($tr['poids_max'] !== null && $e['poids'] > $tr['poids_max']) $exclu[] = 'poids ' . fr($e['poids'], 0) . ' kg (max ' . fr((float) $tr['poids_max'], 0) . ' kg)';
    $g = $G['grilles'][$t][$e['dep']] ?? null;
    if ($g === null) $exclu[] = "département {$e['dep']} absent de la grille";
    if ($exclu) return ['exclu' => $exclu];
    $grille = prixGrille($g, $e['poids'], $places ?? 0);
    if ($grille === null) return ['exclu' => [isset($g['places']) ? "$places places : au-delà de la grille" : 'poids ' . fr($e['poids'], 0) . ' kg : au-delà de la grille']];

    [$p, $lib] = $grille;
    $lignes = [[$lib, $p]];
    $notes = [];
    if (abs((float) $tr['coef_revision'] - 1) > 1e-9) {
        $x = $p * ($tr['coef_revision'] - 1);
        $lignes[] = ['Révision tarifaire × ' . fr((float) $tr['coef_revision'], 4), $x];
        $p += $x;
    }
    if ($tr['saison']['pct'] > 0 && in_array($e['dep'], $tr['saison']['departements'], true) && enSaison($tr['saison'], $e['date'])) {
        $x = $p * $tr['saison']['pct'] / 100;
        $lignes[] = ['Majoration saisonnière ' . fr((float) $tr['saison']['pct']) . ' %', $x];
        $p += $x;
    }
    $maj = (float) ($tr['majorations'][$e['dep']] ?? 0);
    if ($maj != 0.0) {
        $x = $p * $maj / 100;
        $lignes[] = ["Majoration département {$e['dep']} " . fr($maj) . ' %', $x];
        $p += $x;
    }
    if ($tr['frais_fixes'] > 0) $lignes[] = ['Frais fixes par expédition', (float) $tr['frais_fixes']];
    if ($tr['urbain']['montant'] > 0 && ($lieu = lieuUrbain($tr['urbain']['lieux'], $e['dep'], $e['dest']['ville'])) !== null)
        $lignes[] = ["Livraison urbaine ($lieu)", (float) $tr['urbain']['montant']];
    if ($tr['rdv'] > 0 && $e['rdv']) $lignes[] = ['Prise de rendez-vous', (float) $tr['rdv']];
    if ($tr['difficile']['par_100kg'] > 0 && ($loc = $G['localites'][$t] ?? [])) {
        $cp = $e['dest']['cp'];
        $memeCp = array_filter($loc, fn ($l) => $l['cp'] === $cp);
        if ($memeCp) {
            $ville = normaliserVille($e['dest']['ville']);
            if (array_filter($memeCp, fn ($l) => normaliserVille($l['ville']) === $ville)) {
                $p10 = ceil(round($e['poids'], 3) / 10) * 10;
                $x = min((float) $tr['difficile']['max'], $tr['difficile']['par_100kg'] * $p10 / 100);
                $lignes[] = ['Accès difficile (' . fr((float) $tr['difficile']['par_100kg']) . ' €/100 kg, max ' . fr((float) $tr['difficile']['max']) . ' €)', $x];
            } else {
                $notes[] = "Le code postal $cp compte des communes d'accès difficile (" . implode(', ', array_slice(array_column($memeCp, 'ville'), 0, 6)) . ') : vérifier la commune.';
            }
        }
    }
    $base = round(array_sum(array_column($lignes, 1)), 2);
    $taxe = round($base * $gasoil / 100, 2);
    return ['base' => $base, 'gasoil' => $gasoil, 'taxe' => $taxe, 'total' => round($base + $taxe, 2),
        'lignes' => array_map(fn ($l) => [$l[0], round($l[1], 2)], $lignes), 'notes' => $notes];
}

function zoneDe(string $dep, array $R): ?string
{
    foreach ($R['taux']['zones'] as $nom => $deps) if (in_array($dep, $deps, true)) return $nom;
    return null;
}

// ---------- Calcul complet d'un lot importé ----------

// $historique : expéditions déjà enregistrées pour les mois concernés (pour la moyenne du jour et du mois).
function calculerLot(array $export, array $saisies, array $R, ?array $G, array $historique): array
{
    $exps = construireExpeditions($export, $saisies, $R);
    $blocages = [];
    $avert = [];
    if (!$G || empty($G['grilles'])) $blocages[] = 'Grille tarifaire non importée : Réglages › Grille tarifaire.';
    foreach (reglagesManquants($R) as $m) $blocages[] = "Réglage à compléter : $m.";

    $mois = array_values(array_unique(array_filter(array_map(fn ($e) => $e['date'] ? substr($e['date'], 0, 7) : null, $exps))));
    sort($mois);
    $gasoils = [];
    foreach ($mois as $m) {
        $manque = [];
        foreach (TRANSPORTEURS as $t) {
            $x = nombre($R['gasoil'][$m][$t] ?? null);
            if ($x === null) $manque[] = $t;
            else $gasoils[$m][$t] = $x;
        }
        if ($manque) $blocages[] = 'Taux gasoil de ' . nomMois($m) . ' non saisi pour ' . implode(', ', $manque) . ' : calcul bloqué (Réglages › Taxe gasoil).';
    }

    foreach ($exps as &$e) {
        $e['offres'] = [];
        $e['choix'] = null;
        $e['moins_cher'] = null;
        if ($blocages || $e['erreurs']) continue;
        $g = $gasoils[substr($e['date'], 0, 7)];
        foreach (TRANSPORTEURS as $t) $e['offres'][$t] = offre($t, $e, $e['palettes'], $R, $G, $g[$t]);
        $valides = array_filter($e['offres'], fn ($o) => isset($o['total']));
        if (!$valides) {
            $e['erreurs'][] = 'Aucun transporteur ne convient';
            continue;
        }
        uasort($valides, fn ($a, $b) => $a['total'] <=> $b['total']);
        $e['moins_cher'] = array_key_first($valides);
        $f = $e['saisie']['force'];
        if ($f && isset($valides[$f])) $e['choix'] = $f;
        else {
            if ($f) $e['notes'][] = "Choix forcé ($f) abandonné : ce transporteur ne convient plus.";
            $e['choix'] = $e['moins_cher'];
        }
        $o = $e['offres'][$e['choix']];
        $e['total'] = $o['total'];
        $e['taux'] = $e['ht'] > 0 ? $o['total'] / $e['ht'] * 100 : null;
        $e['zone'] = zoneDe($e['dep'], $R);
        $e['seuil'] = $e['zone'] ? (float) $R['taux']['seuil_zones'] : (float) $R['taux']['seuil'];
        $e['au_dessus'] = $e['taux'] !== null && $e['taux'] > $e['seuil'] + 1e-9;
    }
    unset($e);

    // Moyennes pondérées : total des prix de transport / total des montants HT.
    $dans = array_flip(array_column($exps, 'num'));
    $autres = array_filter($historique, fn ($h) => !isset($dans[$h['num']]));
    $max = (float) $R['taux']['moyenne_max'];
    $agreger = function (array $courants, array $anciens) use ($max): array {
        $total = array_sum(array_column($courants, 'total')) + array_sum(array_column($anciens, 'total'));
        $ht = array_sum(array_column($courants, 'ht')) + array_sum(array_column($anciens, 'ht'));
        $taux = $ht > 0 ? $total / $ht * 100 : null;
        $resp = [];
        if ($taux !== null && $taux > $max + 1e-9) {
            foreach (array_merge($courants, $anciens) as $x) {
                if ($x['ht'] > 0 && $x['total'] / $x['ht'] * 100 > $max) $resp[] = ['num' => $x['num'], 'client' => $x['client'], 'taux' => round($x['total'] / $x['ht'] * 100, 2), 'surcout' => round($x['total'] - $x['ht'] * $max / 100, 2)];
            }
            usort($resp, fn ($a, $b) => $b['surcout'] <=> $a['surcout']);
        }
        return ['total' => round($total, 2), 'ht' => round($ht, 2), 'taux' => $taux === null ? null : round($taux, 2), 'nb' => count($courants) + count($anciens),
            'alerte' => $taux !== null && $taux > $max + 1e-9, 'responsables' => array_slice($resp, 0, 15)];
    };
    $attribuees = array_values(array_filter($exps, fn ($e) => $e['choix'] !== null));
    $jours = [];
    foreach (array_unique(array_filter(array_column($exps, 'date'))) as $d) {
        $jours[$d] = $agreger(array_values(array_filter($attribuees, fn ($e) => $e['date'] === $d)), array_values(array_filter($autres, fn ($h) => $h['date'] === $d)))
            + ['libelle' => dateFr($d)];
    }
    ksort($jours);
    $parMois = [];
    foreach ($mois as $m) {
        $parMois[$m] = $agreger(array_values(array_filter($attribuees, fn ($e) => str_starts_with($e['date'], $m))), array_values(array_filter($autres, fn ($h) => str_starts_with($h['date'], $m))))
            + ['libelle' => nomMois($m)];
    }

    $sansColonne = array_keys(array_filter($R['ecriture'], fn ($c) => $c === ''));
    if ($sansColonne) $avert[] = 'Correspondance FLOW ONE à compléter : ' . (count($sansColonne) === count(INFOS_ECRITURE)
        ? 'le retour FLOW ONE n\'écrit que le transporteur (DEOCARRIER, CARRIERDESIGNATION)'
        : 'pas encore de colonne pour ' . implode(', ', array_map(fn ($k) => mb_strtolower(INFOS_ECRITURE[$k]), $sansColonne)) . ' (rien n\'est écrit pour ces informations)') . '. Réglages › Correspondance FLOW ONE.';
    $sansCode = array_unique(array_filter(array_map(fn ($e) => $e['choix'] && trim($R['transporteurs'][$e['choix']]['code_flowone']) === '' ? $e['choix'] : null, $exps)));
    if ($sansCode) $avert[] = 'Code FLOW ONE inconnu pour ' . implode(', ', $sansCode) . ' : le retour FLOW ONE est bloqué tant qu\'il n\'est pas saisi (Réglages › Transporteurs).';

    return [
        'blocages' => $blocages,
        'avertissements' => $avert,
        'expeditions' => $exps,
        'jours' => $jours,
        'mois' => $parMois,
        'seuils' => ['moyenne_min' => (float) $R['taux']['moyenne_min'], 'moyenne_max' => $max],
        'nb_bons' => count($export['lignes']),
    ];
}

// ---------- Fichiers de sortie ----------

// Formats des fichiers transporteurs. « provisoire » en attendant le modèle de chaque transporteur :
// pour l'adapter, copier ce bloc sous le nom du transporteur et changer colonnes, séparateur, encodage, formats.
const FORMATS_TRANSPORTEUR = [
    'provisoire' => [
        'separateur' => ';', 'encodage' => 'Windows-1252', 'decimale' => ',', 'date' => 'd/m/Y', 'fin' => "\r\n",
        'colonnes' => [
            ['N° expédition', 'num'], ['Bons de commande', 'bons'], ['Réf. commande client', 'refs'],
            ['Date expédition', 'date'], ['Date livraison prévue', 'date_liv'], ['N° compte client', 'compte'],
            ['Expéditeur', 'exp.nom'], ['Exp. adresse', 'exp.adresse1'], ['Exp. adresse 2', 'exp.adresse2'], ['Exp. code postal', 'exp.cp'],
            ['Exp. ville', 'exp.ville'], ['Exp. pays', 'exp.pays'], ['Exp. téléphone', 'exp.telephone'], ['Exp. contact', 'exp.contact'],
            ['Destinataire', 'dest.nom'], ['Dest. complément', 'dest.complement'], ['Dest. adresse', 'dest.adresse1'], ['Dest. adresse 2', 'dest.adresse2'],
            ['Dest. code postal', 'dest.cp'], ['Dest. ville', 'dest.ville'], ['Dest. pays', 'dest.pays'],
            ['Nb palettes', 'nb_palettes'], ['Palettes 80x120', 'n120'], ['Palettes 80x85', 'n85'], ['Palettes 100x100', 'n100x100'],
            ['Palettes 100x120', 'n100x120'], ['Palettes 100x130', 'n100x130'], ['Palettes 70x240', 'n70x240'], ['Hauteur (m)', 'hauteur'], ['Volume (m3)', 'volume'],
            ['Poids (kg)', 'poids'], ['Rendez-vous', 'rdv'], ['Consignes de livraison', 'consignes'],
        ],
    ],
];

function valeurPourTransporteur(string $champ, array $e, array $R, string $t): mixed
{
    if (str_starts_with($champ, 'exp.')) return $R['expediteur'][substr($champ, 4)] ?? '';
    if ($champ === 'dest.pays') return 'FR';
    if (str_starts_with($champ, 'dest.')) return $e['dest'][substr($champ, 5)] ?? '';
    return match ($champ) {
        'bons' => implode(' / ', $e['bons']),
        'compte' => $R['transporteurs'][$t]['compte'],
        'nb_palettes' => nbPalettes($e['palettes']),
        'n120', 'n85', 'n100x100', 'n100x120', 'n100x130', 'n70x240', 'hauteur', 'volume' => $e['palettes'][$champ],
        'poids' => round($e['poids'], 1),
        'rdv' => $e['rdv'] ? 'OUI' : 'NON',
        'date', 'date_liv' => $e[$champ] ? ['date' => $e[$champ]] : '',
        default => $e[$champ] ?? '',
    };
}

function fichierTransporteur(string $t, array $calcul, array $R): string
{
    $f = FORMATS_TRANSPORTEUR[$t] ?? FORMATS_TRANSPORTEUR['provisoire'];
    $cellule = function (mixed $v) use ($f): string {
        if (is_array($v)) $v = date($f['date'], (int) strtotime($v['date']));
        elseif (is_float($v)) $v = str_replace('.', $f['decimale'], nombreFlowOne($v));
        $v = mb_convert_encoding((string) $v, $f['encodage'], 'UTF-8');
        return strpbrk($v, $f['separateur'] . "\"\r\n") !== false ? '"' . str_replace('"', '""', $v) . '"' : $v;
    };
    $lignes = [implode($f['separateur'], array_map(fn ($c) => $cellule($c[0]), $f['colonnes']))];
    foreach ($calcul['expeditions'] as $e) {
        if ($e['choix'] !== $t) continue;
        $lignes[] = implode($f['separateur'], array_map(fn ($c) => $cellule(valeurPourTransporteur($c[1], $e, $R, $t)), $f['colonnes']));
    }
    return implode($f['fin'], $lignes) . $f['fin'];
}

// Fichier de retour vers FLOW ONE : l'export d'origine, mêmes colonnes et même ordre, seuls les champs transport changent.
function retourFlowOne(string $brut, array $calcul, array $R): string
{
    $lignes = decouperCsv($brut);
    $entete = array_map(fn ($c) => trim(valeurChamp($c)), $lignes[0]);
    $ix = array_flip($entete);
    $modifiables = ['DEOCARRIER' => true, 'CARRIERDESIGNATION' => true];
    $ecriture = [];
    foreach ($R['ecriture'] as $info => $col) {
        if ($col === '') continue;
        if (!isset($ix[$col])) throw new RuntimeException("Correspondance FLOW ONE : la colonne $col n'existe pas dans ce fichier.");
        if (isset($modifiables[$col])) throw new RuntimeException("Correspondance FLOW ONE : la colonne $col est utilisée deux fois.");
        $modifiables[$col] = true;
        $ecriture[$info] = $ix[$col];
    }
    $parBon = [];
    foreach ($calcul['expeditions'] as $e) foreach ($e['bons'] as $b) $parBon[$b] = $e;
    foreach ($calcul['expeditions'] as $e)
        if ($e['choix'] && trim($R['transporteurs'][$e['choix']]['code_flowone']) === '')
            throw new RuntimeException("Code FLOW ONE de {$e['choix']} inconnu : à saisir dans Réglages › Transporteurs.");

    $sortie = $lignes;
    for ($i = 1; $i < count($lignes); $i++) {
        $l = $lignes[$i];
        if (count($l) === 1 && trim($l[0]) === '') continue;
        $bon = trim(valeurChamp($l[$ix['ORDNOORDER']]));
        $e = $parBon[$bon] ?? null;
        if (!$e || !$e['choix']) continue;
        $o = $e['offres'][$e['choix']];
        $tr = $R['transporteurs'][$e['choix']];
        $principal = $bon === $e['principal'];
        $l[$ix['DEOCARRIER']] = champBrut(trim($tr['code_flowone']));
        $l[$ix['CARRIERDESIGNATION']] = champBrut(trim($tr['designation_flowone']) ?: $e['choix']);
        foreach ($ecriture as $info => $j) {
            $v = match ($info) {
                'prix_transport' => $o['total'],
                'taxe_gasoil' => $o['gasoil'],
                'poids' => round($e['poids'], 3),
                'nb_palettes' => nbPalettes($e['palettes']),
                'n120', 'n85', 'n100x100', 'n100x120', 'n100x130', 'n70x240' => $e['palettes'][$info],
                'hauteur' => $e['palettes']['hauteur'],
                'numero_suivi' => (string) ($e['suivi'] ?? ''),
            };
            if (in_array($info, INFOS_QUANTITES, true) && !$principal) $v = 0;
            $l[$j] = champBrut(is_string($v) ? $v : nombreFlowOne((float) $v));
        }
        $sortie[$i] = $l;
    }
    verifierRetour($lignes, $sortie, array_keys($modifiables), $entete);
    $fin = str_contains($brut, "\r\n") ? "\r\n" : "\n";
    $texte = implode($fin, array_map(fn ($l) => implode(';', $l), $sortie));
    return $texte . (str_ends_with($brut, "\n") ? $fin : '');
}

// Contrôle avant export : mêmes lignes, mêmes colonnes, rien de changé en dehors des champs transport.
function verifierRetour(array $avant, array $apres, array $colonnesTransport, array $entete): void
{
    if (count($avant) !== count($apres)) throw new RuntimeException('Contrôle : le nombre de lignes a changé.');
    $permises = array_flip(array_map(fn ($c) => array_search($c, $entete, true), $colonnesTransport));
    foreach ($avant as $i => $l) {
        if (count($l) !== count($apres[$i])) throw new RuntimeException('Contrôle : le nombre de colonnes a changé ligne ' . ($i + 1) . '.');
        foreach ($l as $j => $v) {
            if ($apres[$i][$j] !== $v && ($i === 0 || !isset($permises[$j])))
                throw new RuntimeException('Contrôle : la colonne ' . ($entete[$j] ?? $j) . ' a été modifiée ligne ' . ($i + 1) . ' alors que ce n\'est pas un champ transport.');
        }
    }
}

// ---------- Historique ----------

// Résumé d'une expédition attribuée, conservé dans data/historique/AAAA-MM.json.
function entreeHistorique(array $e): array
{
    $o = $e['offres'][$e['choix']];
    $min = $e['offres'][$e['moins_cher']]['total'];
    return [
        'num' => $e['num'], 'date' => $e['date'], 'bons' => $e['bons'], 'client' => $e['client'], 'cp' => $e['dest']['cp'], 'dep' => $e['dep'],
        'ville' => $e['dest']['ville'], 'transporteur' => $e['choix'], 'force' => $e['choix'] !== $e['moins_cher'], 'moins_cher' => $e['moins_cher'],
        'ecart_force' => round($o['total'] - $min, 2), 'nb_palettes' => nbPalettes($e['palettes']), 'n120' => $e['palettes']['n120'], 'n85' => $e['palettes']['n85'],
        'poids' => round($e['poids'], 3), 'ht' => $e['ht'], 'base' => $o['base'], 'gasoil' => $o['gasoil'], 'taxe' => $o['taxe'], 'total' => $o['total'],
    ];
}

function recapitulatif(array $entrees): array
{
    $vide = ['nb' => 0, 'palettes' => 0, 'poids' => 0.0, 'total' => 0.0, 'ht' => 0.0];
    $ajouter = function (array $a, array $h): array {
        $a['nb']++;
        $a['palettes'] += $h['nb_palettes'] ?? ($h['n120'] + $h['n85']);
        $a['poids'] += $h['poids'];
        $a['total'] += $h['total'];
        $a['ht'] += $h['ht'];
        return $a;
    };
    $finir = fn (array $a) => $a + ['taux' => $a['ht'] > 0 ? round($a['total'] / $a['ht'] * 100, 2) : null];
    $parT = [];
    $parTD = [];
    $parJour = [];
    $forces = ['nb' => 0, 'surcout' => 0.0];
    $tous = $vide;
    foreach ($entrees as $h) {
        $parT[$h['transporteur']] = $ajouter($parT[$h['transporteur']] ?? $vide, $h);
        $parTD[$h['transporteur']][$h['dep']] = $ajouter($parTD[$h['transporteur']][$h['dep']] ?? $vide, $h);
        $parJour[$h['date']] = $ajouter($parJour[$h['date']] ?? $vide, $h);
        $tous = $ajouter($tous, $h);
        if ($h['force']) { $forces['nb']++; $forces['surcout'] += $h['ecart_force']; }
    }
    ksort($parJour);
    $r = ['transporteurs' => [], 'departements' => [], 'jours' => [], 'total' => $finir($tous), 'forces' => $forces];
    foreach (TRANSPORTEURS as $t) if (isset($parT[$t])) {
        $r['transporteurs'][$t] = $finir($parT[$t]);
        ksort($parTD[$t]);
        foreach ($parTD[$t] as $d => $a) $r['departements'][] = ['transporteur' => $t, 'dep' => $d] + $finir($a);
    }
    foreach ($parJour as $d => $a) $r['jours'][] = ['date' => $d] + $finir($a);
    return $r;
}

function recapCsv(array $recap, string $mois): string
{
    $n = fn (float $x) => str_replace('.', ',', nombreFlowOne($x));
    $l = ['Mois;Transporteur;Département;Nb expéditions;Palettes;Poids (kg);Coût transport (€);Montant HT (€);Taux (%)'];
    foreach ($recap['transporteurs'] as $t => $a)
        $l[] = "$mois;$t;Tous;{$a['nb']};{$a['palettes']};" . $n($a['poids']) . ';' . $n($a['total']) . ';' . $n($a['ht']) . ';' . ($a['taux'] === null ? '' : $n($a['taux']));
    foreach ($recap['departements'] as $a)
        $l[] = "$mois;{$a['transporteur']};{$a['dep']};{$a['nb']};{$a['palettes']};" . $n($a['poids']) . ';' . $n($a['total']) . ';' . $n($a['ht']) . ';' . ($a['taux'] === null ? '' : $n($a['taux']));
    $a = $recap['total'];
    $l[] = "$mois;Tous;Tous;{$a['nb']};{$a['palettes']};" . $n($a['poids']) . ';' . $n($a['total']) . ';' . $n($a['ht']) . ';' . ($a['taux'] === null ? '' : $n($a['taux']));
    return mb_convert_encoding(implode("\r\n", $l) . "\r\n", 'Windows-1252', 'UTF-8');
}

// ---------- Création des expéditions chez les transporteurs (robot) ----------

// Transporteurs dont le robot sait créer les expéditions sur le site (les autres viendront ensuite).
const ROBOT_TRANSPORTEURS = ['BMV', 'KUEHNE', 'XPO'];

// Ce que le robot saisit sur le site du transporteur pour une expédition attribuée.
function donneesRobot(array $e): array
{
    return [
        'num' => $e['num'], 'transporteur' => $e['choix'], 'date' => $e['date'], 'refs' => $e['refs'],
        'dest' => ['nom' => $e['dest']['nom'], 'complement' => $e['dest']['complement'], 'adresse1' => $e['dest']['adresse1'],
            'adresse2' => $e['dest']['adresse2'], 'cp' => $e['dest']['cp'], 'ville' => $e['dest']['ville'], 'pays' => 'FR'],
        'palettes' => nbPalettes($e['palettes']), 'places' => placesXpo($e['palettes']),
        'detail_palettes' => array_filter(array_intersect_key($e['palettes'], PALETTES)),
        'hauteur' => $e['palettes']['hauteur'], 'poids' => (int) ceil($e['poids']),
        'consignes' => trim((string) preg_replace('/\s+/', ' ', $e['consignes'])), 'rdv' => $e['rdv'],
    ];
}
