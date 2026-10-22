<?php
// Import du classeur Excel des tarifs (« Aide décision BMV - XPO - KUEHNE ») :
// - « Tarifs à changer chaque année » : les grilles des trois transporteurs (seule source des prix) ;
// - « XPO - Localités spéciales » : communes d'accès difficile et leur majoration ;
// - la feuille de comparaison « KUEHNE - XPO - BMV » : frais par expédition, saison, livraison urbaine, majorations XPO.
// Sans bibliothèque : un .xlsx est un zip de fichiers XML.
declare(strict_types=1);

const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

function colonneIndex(string $lettres): int
{
    $n = 0;
    foreach (str_split($lettres) as $c) $n = $n * 26 + ord($c) - 64;
    return $n;
}

function colonneLettres(int $n): string
{
    $s = '';
    for (; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s;
    return $s;
}

// Texte d'une chaîne partagée, y compris en texte enrichi (plusieurs morceaux <r><t>).
function texteXml(SimpleXMLElement $si): string
{
    if (isset($si->t)) return (string) $si->t;
    $s = '';
    foreach ($si->r as $r) $s .= (string) $r->t;
    return $s;
}

// Formule partagée recopiée dans une autre cellule : décale les références relatives (A1, $A1, A$1),
// sauf dans les noms de feuille entre apostrophes et les textes entre guillemets.
function decalerFormule(string $f, int $dl, int $dc): string
{
    $morceaux = preg_split('/(\'[^\']*\'|"[^"]*")/', $f, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$f];
    foreach ($morceaux as &$m) {
        if ($m === '' || $m[0] === "'" || $m[0] === '"') continue;
        $m = (string) preg_replace_callback('/(?<![A-Za-z_\d.])(\$?)([A-Z]{1,3})(\$?)(\d+)(?![\d(A-Za-z_])/', fn ($x) =>
            $x[1] . ($x[1] === '$' ? $x[2] : colonneLettres(colonneIndex($x[2]) + $dc)) . $x[3] . ($x[3] === '$' ? $x[4] : (string) ((int) $x[4] + $dl)), $m);
    }
    unset($m);
    return implode('', $morceaux);
}

// Feuilles du classeur : [nom => [ligne => [colonne => ['v' => valeur, 'f' => formule|null]]]].
function lireXlsx(string $chemin): array
{
    $zip = new ZipArchive();
    if ($zip->open($chemin, ZipArchive::RDONLY) !== true) throw new RuntimeException('Ce fichier n\'est pas un classeur Excel (.xlsx).');
    $lire = function (string $nom) use ($zip): ?SimpleXMLElement {
        $st = $zip->statName($nom);
        if ($st === false) return null;
        if ($st['size'] > 40 * 1024 * 1024) throw new RuntimeException('Classeur trop volumineux.');
        $x = simplexml_load_string((string) $zip->getFromName($nom), SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        return $x === false ? null : $x;
    };
    $wb = $lire('xl/workbook.xml');
    $rels = $lire('xl/_rels/workbook.xml.rels');
    if (!$wb || !$rels) throw new RuntimeException('Classeur illisible : enregistrez-le au format .xlsx.');
    $cibles = [];
    foreach ($rels->Relationship as $r) $cibles[(string) $r['Id']] = (string) $r['Target'];
    $partages = [];
    if ($ss = $lire('xl/sharedStrings.xml')) foreach ($ss->si as $si) $partages[] = texteXml($si);

    $feuilles = [];
    foreach ($wb->sheets->sheet as $s) {
        $id = (string) $s->attributes(NS_REL)['id'];
        $cible = $cibles[$id] ?? null;
        if ($cible === null) continue;
        $cible = str_starts_with($cible, '/') ? ltrim($cible, '/') : 'xl/' . $cible;
        $x = $lire($cible);
        if (!$x) continue;
        $cellules = [];
        $maitres = [];
        $copies = [];
        foreach ($x->sheetData->row as $row) {
            foreach ($row->c as $c) {
                if (!preg_match('/^([A-Z]{1,3})(\d+)$/', (string) $c['r'], $m)) continue;
                $l = (int) $m[2];
                $col = colonneIndex($m[1]);
                $type = (string) $c['t'];
                $v = isset($c->v) ? (string) $c->v : null;
                $v = match ($type) {
                    's' => $partages[(int) $v] ?? '',
                    'inlineStr' => texteXml($c->is),
                    'str', 'e' => $v,
                    'b' => $v === '1',
                    default => $v === null ? null : (float) $v,
                };
                $f = isset($c->f) && (string) $c->f !== '' ? (string) $c->f : null;
                if (isset($c->f) && (string) $c->f['t'] === 'shared') {
                    $si = (string) $c->f['si'];
                    if ($f !== null) $maitres[$si] = [$f, $l, $col];
                    else $copies[] = [$si, $l, $col];
                }
                if ($v === null && $f === null && !isset($c->f)) continue;
                $cellules[$l][$col] = ['v' => $v, 'f' => $f];
            }
        }
        foreach ($copies as [$si, $l, $col])
            if (isset($maitres[$si])) $cellules[$l][$col]['f'] = decalerFormule($maitres[$si][0], $l - $maitres[$si][1], $col - $maitres[$si][2]);
        $feuilles[(string) $s['name']] = $cellules;
    }
    $zip->close();
    return $feuilles;
}

function valeurCellule(array $f, int $l, int $c): mixed { return $f[$l][$c]['v'] ?? null; }

function texteCellule(array $f, int $l, int $c): string
{
    $v = valeurCellule($f, $l, $c);
    return is_string($v) ? trim($v) : '';
}

function feuilleNommee(array $feuilles, string $motif): ?string
{
    foreach (array_keys($feuilles) as $n) if (preg_match($motif, (string) $n)) return (string) $n;
    return null;
}

// Premier nombre à droite d'une cellule dont le texte correspond au motif (ex. « Frais de sûreté : » puis son montant).
function nombreApresLibelle(array $f, string $motif, int $ligneMax = 30): ?array
{
    foreach ($f as $l => $cols) {
        if ($l > $ligneMax) continue;
        ksort($cols);
        foreach ($cols as $c => $x) {
            if (!is_string($x['v']) || !preg_match($motif, $x['v'])) continue;
            foreach ($cols as $c2 => $y) if ($c2 > $c && is_float($y['v'])) return [$y['v'], colonneLettres($c2) . $l];
        }
    }
    return null;
}

// Département en tête d'un libellé : « 06 Alpes Marit. ( + taxe saison) » → 06.
function departementLibelle(string $s): ?string
{
    return preg_match('/^\s*(\d{2}|2[AB])\b/', $s, $m) ? $m[1] : null;
}

// Repère les colonnes de début de chaque transporteur sur une ligne de titres.
function sectionsTransporteurs(array $f, int $ligneMax): array
{
    $motifs = ['BMV' => '/\bBMV\b/i', 'XPO' => '/\bXPO\b/i', 'KUEHNE' => '/KUEHNE|NAGEL/i'];
    for ($l = 1; $l <= $ligneMax; $l++) {
        $debuts = [];
        foreach ($f[$l] ?? [] as $c => $x) {
            if (!is_string($x['v'])) continue;
            foreach ($motifs as $t => $m) if (!isset($debuts[$t]) && preg_match($m, $x['v'])) $debuts[$t] = $c;
        }
        if (count($debuts) === 3) {
            asort($debuts);
            $s = [];
            $ts = array_keys($debuts);
            foreach ($ts as $i => $t) $s[$t] = [$debuts[$t], isset($ts[$i + 1]) ? $debuts[$ts[$i + 1]] - 1 : PHP_INT_MAX];
            return [$l, $s];
        }
    }
    throw new RuntimeException('Titres BMV, XPO et KUEHNE introuvables dans la feuille des tarifs.');
}

function importerClasseur(string $chemin): array
{
    $feuilles = lireXlsx($chemin);
    $resume = [];
    $alertes = [];
    $reglages = [];
    $noter = function (string $t, string $cle, mixed $v, string $origine) use (&$reglages) { $reglages[] = [$t, $cle, $v, $origine]; };

    // --- Grilles : feuille « Tarifs à changer chaque année » ---
    $nomT = feuilleNommee($feuilles, '/tarif/i') ?? throw new RuntimeException('Feuille « Tarifs à changer chaque année » introuvable.');
    $T = $feuilles[$nomT];
    $lEntete = null;
    foreach ($T as $l => $cols) if (preg_match('/^D[ée]partements/u', texteCellule($T, $l, 1))) { $lEntete = $l; break; }
    if ($lEntete === null) throw new RuntimeException("Feuille « $nomT » : ligne « Departements livrés » introuvable.");
    [, $sections] = sectionsTransporteurs($T, $lEntete - 1);

    $lignesDep = [];
    foreach ($T as $l => $cols) if ($l > $lEntete && ($d = departementLibelle(texteCellule($T, $l, 1))) !== null) $lignesDep[$d] = $l;
    if (count($lignesDep) < 90) $alertes[] = 'Seulement ' . count($lignesDep) . ' départements trouvés dans les tarifs.';

    $grilles = [];
    foreach ($sections as $t => [$c0, $c1]) {
        $forfaits = [];
        $par100 = [];
        $places = [];
        foreach ($T[$lEntete] ?? [] as $c => $x) {
            if ($c < $c0 || $c > $c1 || !is_string($x['v'])) continue;
            $h = $x['v'];
            if ($t === 'XPO') {
                if (preg_match('/^\s*(\d{1,2})\s*(\n|$)/', $h, $m)) $places[(int) $m[1]] = $c;
            } elseif (preg_match('/De\s+(\d+)\s+(?:à|a)\s+(\d+)\s*kg/iu', $h, $m)) {
                $b = ['de' => (int) $m[1], 'a' => (int) $m[2], 'c' => $c];
                if ($b['de'] > 100 || preg_match('/base\s*100/i', $h)) $par100[] = $b;
                else $forfaits[] = $b;
            }
        }
        if ($t === 'XPO' ? !$places : (!$forfaits || !$par100)) throw new RuntimeException("Grille $t introuvable dans « $nomT ».");
        // BMV : au-delà de la dernière tranche, le même prix au kilo continue (confirmé par le propriétaire).
        if ($t === 'BMV') $par100[count($par100) - 1]['a'] = null;
        ksort($places);
        $manques = [];
        foreach ($lignesDep as $d => $l) {
            $d = (string) $d;
            $num = fn (int $c) => is_float(valeurCellule($T, $l, $c)) ? valeurCellule($T, $l, $c) : null;
            if ($t === 'XPO') {
                $g = ['places' => array_values(array_map($num, $places))];
                $ok = !in_array(null, $g['places'], true);
            } else {
                $g = [
                    'forfaits' => array_map(fn ($b) => ['de' => $b['de'], 'a' => $b['a'], 'prix' => $num($b['c'])], $forfaits),
                    'par100' => array_map(fn ($b) => ['de' => $b['de'], 'a' => $b['a'], 'taux' => $num($b['c'])], $par100),
                ];
                $ok = !in_array(null, array_merge(array_column($g['forfaits'], 'prix'), array_column($g['par100'], 'taux')), true);
            }
            if ($ok) $grilles[$t][$d] = $g;
            else $manques[] = $d;
        }
        if ($manques) $alertes[] = "$t : prix manquants pour les départements " . implode(', ', $manques) . ' (transporteur exclu pour ces départements).';
        $resume[] = "Grille $t : " . count($grilles[$t] ?? []) . ' départements, ' . ($t === 'XPO'
            ? 'de 1 à ' . max(array_keys($places)) . ' places'
            : count($forfaits) . ' forfaits jusqu\'à ' . end($forfaits)['a'] . ' kg, puis prix aux 100 kg' . ($t === 'BMV' ? ' sans limite de poids' : ' jusqu\'à ' . end($par100)['a'] . ' kg')) . '.';
    }

    // Coefficient de révision BMV (nombre à côté de « MAJ 01.01 ») au-dessus de la grille BMV.
    [$b0, $b1] = $sections['BMV'];
    for ($l = 1; $l < $lEntete; $l++) {
        foreach ($T[$l] ?? [] as $c => $x) {
            if ($c < $b0 || $c > $b1 || !is_string($x['v']) || !preg_match('/MAJ/i', $x['v'])) continue;
            foreach ($T[$l] as $c2 => $y) if ($c2 > $c && $c2 <= $b1 && is_float($y['v']) && $y['v'] > 0.8 && $y['v'] < 1.5) {
                $noter('BMV', 'coef_revision', $y['v'], "« {$x['v']} » (" . colonneLettres($c2) . "$l)");
                break 3;
            }
        }
    }

    // Mentions des libellés de « Tarifs » : saison et région parisienne XPO.
    $saisonXpo = [];
    $rp = [];
    foreach ($lignesDep as $d => $l) {
        $d = (string) $d;
        $lib = texteCellule($T, $l, 1);
        if (preg_match('/taxe\s+saison/i', $lib)) $saisonXpo[] = $d;
        if (preg_match('/Paris\s+et\s+RP/i', $lib)) $rp[] = $d;
    }

    // --- Feuille de comparaison : frais et majorations ---
    $nomC = feuilleNommee($feuilles, '/KUEHNE.*XPO|XPO.*KUEHNE/i');
    if ($nomC === null) {
        $alertes[] = 'Feuille de comparaison « KUEHNE - XPO - BMV » introuvable : frais à saisir dans Réglages.';
    } else {
        $F = $feuilles[$nomC];
        $lignesC = [];
        foreach ($F as $l => $cols) if (($d = departementLibelle(texteCellule($F, $l, 1))) !== null && !isset($lignesC[$d])) $lignesC[$d] = $l;
        [, $sectC] = sectionsTransporteurs($F, min($lignesC ?: [30]) - 1);

        $frais = [];
        foreach (['saison' => '/saisonni/i', 'surete' => '/s[uû]ret[ée]/iu', 'rdv' => '/RDV|rendez/i', 'urbain' => '/livraison\s+urbaine/i',
                     'transition' => '/transition\s+[ée]nerg/iu', 'eco' => '/contribution\s+[ée]co/iu', 'saison_xpo' => '/^\s*SAISON\s*:?\s*$/i'] as $k => $m)
            $frais[$k] = nombreApresLibelle($F, $m);
        if ($frais['surete'] && $frais['transition'])
            $noter('KUEHNE', 'frais_fixes', round($frais['surete'][0] + $frais['transition'][0], 2), 'sûreté ' . fr($frais['surete'][0]) . ' € + transition énergétique ' . fr($frais['transition'][0]) . ' €');
        if ($frais['rdv']) $noter('KUEHNE', 'rdv', $frais['rdv'][0], 'frais de prise de RDV (' . $frais['rdv'][1] . ')');
        if ($frais['urbain']) $noter('KUEHNE', 'urbain.montant', $frais['urbain'][0], 'frais de livraison urbaine (' . $frais['urbain'][1] . ')');
        if ($frais['saison']) $noter('KUEHNE', 'saison.pct', $frais['saison'][0], 'frais de livraison saisonnière (' . $frais['saison'][1] . ')');
        if ($frais['eco']) $noter('BMV', 'frais_fixes', $frais['eco'][0], 'contribution éco-environnementale (' . $frais['eco'][1] . ')');
        if ($frais['saison_xpo']) $noter('XPO', 'saison.pct', round(($frais['saison_xpo'][0] - 1) * 100, 4), 'coefficient « SAISON » ' . fr($frais['saison_xpo'][0], 4) . ' (' . $frais['saison_xpo'][1] . ')');

        // KUEHNE : départements « + taxe saison » et villes « + frais liv. urbaine » des libellés de la comparaison.
        $saisonKn = [];
        $lieux = [];
        foreach ($lignesC as $d => $l) {
            $d = (string) $d;
            $lib = texteCellule($F, $l, 1);
            if (preg_match('/taxe\s+saison/i', $lib)) $saisonKn[] = $d;
            if (preg_match('/frais\s+liv\.?\s+urbaine\s*([^)]*)\)/i', $lib, $m)) {
                $villes = array_filter(array_map('trim', explode(',', $m[1])));
                foreach ($villes ?: ['*'] as $v) $lieux[] = ['dep' => $d, 'ville' => $v];
            }
        }
        if ($saisonKn) $noter('KUEHNE', 'saison.departements', $saisonKn, 'mentions « + taxe saison » de la feuille de comparaison');
        if ($lieux) $noter('KUEHNE', 'urbain.lieux', $lieux, 'mentions « + frais liv. urbaine » de la feuille de comparaison');
        if ($saisonXpo) $noter('XPO', 'saison.departements', $saisonXpo, "mentions « + taxe saison » de « $nomT »");

        // XPO région parisienne : « Paris et Région Parisienne : N% par position » pour les départements « + taxe Paris et RP ».
        $pctRp = null;
        foreach ($F as $l => $cols) foreach ($cols as $x)
            if (is_string($x['v']) && preg_match('/Paris\s+et\s+R[ée]gion\s+Parisienne\s*:\s*(\d+(?:[.,]\d+)?)\s*%/iu', $x['v'], $m)) { $pctRp = (float) str_replace(',', '.', $m[1]); break 2; }
        $maj = [];
        if ($pctRp !== null && $rp) {
            $maj = array_fill_keys($rp, $pctRp);
            $noter('XPO', 'majorations', $maj, '« Paris et Région Parisienne : ' . fr($pctRp) . ' % » pour les départements « + taxe Paris et RP »');
        } else {
            $alertes[] = 'XPO : majoration région parisienne introuvable : à saisir dans Réglages si elle existe.';
        }

        // XPO : frais fixes lus dans les formules (« … + montant » en fin de formule), puis contrôle des prix affichés par la comparaison
        // (valeurs calculées par Excel ; la saison n'y est jamais active, ses dates sont de 2020).
        [$x0, $x1] = $sectC['XPO'];
        $ratios = [];
        $fixes = [];
        $saisonFormule = [];
        foreach ($lignesC as $d => $l) {
            $d = (string) $d;
            foreach ($F[$l] ?? [] as $c => $x) {
                if ($c < $x0 || $c > $x1 || $x['f'] === null || !is_float($x['v'])) continue;
                if (!preg_match('/Tarifs[^!]*!\$?([A-Z]{1,3})\$?(\d+)/i', $x['f'], $m)) continue;
                $g = valeurCellule($T, (int) $m[2], colonneIndex($m[1]));
                if (!is_float($g) || $g <= 0) continue;
                if (preg_match('/\+\s*(\d+(?:\.\d+)?)\s*$/', $x['f'], $mb)) $fixes[] = $mb[1];
                $ratios[$d][] = [$x['v'], $g];
                if (str_contains($x['f'], 'TODAY()')) $saisonFormule[$d] = true;
            }
        }
        if ($fixes) {
            $valeursFixes = array_count_values($fixes);
            arsort($valeursFixes);
            $fixe = (float) array_key_first($valeursFixes);
            $noter('XPO', 'frais_fixes', $fixe, 'formules XPO de la comparaison (« + ' . array_key_first($valeursFixes) . ' »)');
            $differents = [];
            foreach ($ratios as $d => $paires) {
                $d = (string) $d;
                $attendu = 1 + ($maj[$d] ?? 0) / 100;
                $ecart = array_filter($paires, fn ($p) => abs($p[0] - ($p[1] * $attendu + $fixe)) > 0.02);
                if ($ecart) {
                    $r = ($paires[0][0] - $fixe) / $paires[0][1];
                    $differents[] = $d . ' (' . (abs($r - round($r, 2)) < 0.0005 && $r < 1.5 ? '+' . fr(($r - 1) * 100) . ' %' : 'formule à revoir') . ')';
                }
            }
            if ($differents) $alertes[] = 'XPO : la comparaison calcule autrement ces départements : ' . implode(', ', $differents)
                . '. L\'outil applique la grille, ' . ($pctRp !== null ? fr($pctRp) . ' % pour la région parisienne' : 'sans majoration') . ' et ' . fr($fixe) . ' € de frais.';
            $sf = array_map('strval', array_keys($saisonFormule));
            if ($saisonXpo && $sf && array_values($sf) !== $saisonXpo)
                $alertes[] = 'XPO : la formule de saison de la comparaison vise d\'autres départements (' . implode(', ', array_diff($sf, $saisonXpo)) . ' en plus, '
                    . (implode(', ', array_diff($saisonXpo, $sf)) ?: 'aucun') . ' en moins) que les mentions « + taxe saison ». L\'outil suit les mentions ; ses dates (2020) l\'empêchaient de toute façon de s\'appliquer.';
        }

        // Contrôle : les prix KUEHNE aux 100 kg recopiés dans la comparaison doivent être ceux de « Tarifs ».
        [$k0, $k1] = $sectC['KUEHNE'];
        $lTitres = null;
        foreach ($F as $l => $cols) foreach ($cols as $c => $x) if ($c >= $k0 && $c <= $k1 && is_string($x['v']) && preg_match('/De\s+101\s/i', $x['v'])) { $lTitres = $l; break 2; }
        $ecarts = [];
        if ($lTitres !== null) {
            foreach ($F[$lTitres] as $c => $x) {
                if ($c < $k0 || $c > $k1 || !is_string($x['v']) || !preg_match('/De\s+(\d+)\s+(?:à|a)\s+(\d+)\s*kg/iu', $x['v'], $m) || (int) $m[1] <= 100) continue;
                foreach ($lignesC as $d => $l) {
                    $d = (string) $d;
                    $fo = $F[$l][$c]['f'] ?? null;
                    if ($fo === null || !preg_match('/^=?\+?\$?([A-Z]{1,3})\$?(\d+)\s*\/\s*100/', $fo, $mr)) continue;
                    $vu = valeurCellule($F, (int) $mr[2], colonneIndex($mr[1]));
                    $bon = null;
                    foreach ($grilles['KUEHNE'][$d]['par100'] ?? [] as $b) if ($b['a'] === (int) $m[2]) $bon = $b['taux'];
                    if (is_float($vu) && $bon !== null && abs($vu - $bon) > 0.005) $ecarts[$d][] = $m[1] . '–' . $m[2] . ' kg';
                }
            }
        }
        if ($ecarts) {
            $tranches = array_unique(array_merge(...array_values($ecarts)));
            $alertes[] = 'La comparaison affiche des prix KUEHNE différents de « ' . $nomT . ' » pour ' . count($ecarts) . ' départements (tranches ' . implode(', ', $tranches)
                . ') : l\'outil utilise « ' . $nomT . ' ». Pensez à corriger la feuille de comparaison si elle sert encore.';
        }
    }

    // --- Localités d'accès difficile XPO ---
    $localites = [];
    $nomL = feuilleNommee($feuilles, '/localit/i');
    if ($nomL !== null) {
        $L = $feuilles[$nomL];
        $cCp = $cVille = $lTete = null;
        foreach ($L as $l => $cols) {
            foreach ($cols as $c => $x) if (is_string($x['v']) && strcasecmp(trim($x['v']), 'CP') === 0) $cCp = $c;
            if ($cCp !== null) { $lTete = $l; break; }
        }
        foreach ($L[$lTete] ?? [] as $c => $x) if (is_string($x['v']) && preg_match('/^Libell/i', trim($x['v']))) $cVille = $c;
        if ($cCp !== null && $cVille !== null) {
            foreach ($L as $l => $cols) {
                if ($l <= $lTete) continue;
                $cp = valeurCellule($L, $l, $cCp);
                $ville = texteCellule($L, $l, $cVille);
                $cp = is_float($cp) ? codePostal((string) (int) $cp) : (is_string($cp) ? codePostal($cp) : null);
                if ($cp !== null && $ville !== '') $localites[] = ['cp' => $cp, 'ville' => $ville];
            }
        }
        foreach ($L as $l => $cols) foreach ($cols as $x) {
            if (is_string($x['v']) && preg_match('/(\d+(?:[.,]\d+)?)\s*€\s*les\s*100\s*kilos.*?maximum\D*(\d+(?:[.,]\d+)?)\s*€/iu', $x['v'], $m)) {
                $noter('XPO', 'difficile.par_100kg', (float) str_replace(',', '.', $m[1]), "« $nomL »");
                $noter('XPO', 'difficile.max', (float) str_replace(',', '.', $m[2]), "« $nomL »");
                break 2;
            }
        }
        $resume[] = 'Localités d\'accès difficile XPO : ' . count($localites) . '.';
    } else {
        $alertes[] = 'Feuille « XPO - Localités spéciales » introuvable.';
    }

    return ['grilles' => $grilles, 'localites' => ['XPO' => $localites], 'reglages' => $reglages, 'resume' => $resume, 'alertes' => $alertes];
}

// Applique au réglages les valeurs lues dans le classeur ; renvoie le texte de chaque valeur reprise.
function appliquerReglagesClasseur(array $R, array $lus): array
{
    $R = completerReglages($R);
    $textes = [];
    foreach ($lus as [$t, $cle, $v, $origine]) {
        $ref = &$R['transporteurs'][$t];
        foreach (explode('.', $cle) as $k) $ref = &$ref[$k];
        $ref = $v;
        unset($ref);
        $aff = match (true) {
            $cle === 'urbain.lieux' => count($v) . ' lieux (' . implode(', ', array_map(fn ($x) => $x['dep'] . ' ' . ($x['ville'] === '*' ? 'tout' : $x['ville']), array_slice($v, 0, 5))) . '…)',
            $cle === 'majorations' => $v ? implode(', ', array_map(fn ($d, $p) => "$d +" . fr($p, 2) . ' %', array_keys($v), $v)) : 'aucune',
            is_array($v) => implode(', ', $v),
            default => fr((float) $v, 4),
        };
        $textes[] = "$t · " . LIBELLES_REGLAGES[$cle] . " : $aff — $origine";
    }
    return [completerReglages($R), $textes];
}

const LIBELLES_REGLAGES = [
    'coef_revision' => 'révision tarifaire', 'frais_fixes' => 'frais fixes par expédition (€)', 'rdv' => 'prise de rendez-vous (€)',
    'urbain.montant' => 'livraison urbaine (€)', 'urbain.lieux' => 'lieux de livraison urbaine', 'saison.pct' => 'majoration saisonnière (%)',
    'saison.departements' => 'départements en saison', 'majorations' => 'majorations par département (%)',
    'difficile.par_100kg' => 'accès difficile (€ / 100 kg)', 'difficile.max' => 'accès difficile, maximum (€)',
];
