<?php
// Tests de l'outil transport, à lancer en ligne de commande : php tests/tester.php
// Données inventées uniquement (le dépôt est public) : fausse grille, faux clients, petit classeur fabriqué ici.
declare(strict_types=1);

require __DIR__ . '/../moteur.php';
require __DIR__ . '/../classeur.php';

$ok = 0;
$echecs = [];
function verifier(bool $cond, string $nom): void
{
    global $ok, $echecs;
    if ($cond) $ok++;
    else $echecs[] = $nom;
}
function egal(mixed $a, mixed $b, string $nom): void
{
    verifier($a === $b || (is_float($a) && is_float($b) && abs($a - $b) < 0.006), $nom . ' (obtenu ' . json_encode($a, JSON_UNESCAPED_UNICODE) . ', attendu ' . json_encode($b, JSON_UNESCAPED_UNICODE) . ')');
}
function exception(callable $fn, string $motif, string $nom): void
{
    try { $fn(); verifier(false, "$nom : aucune erreur"); }
    catch (RuntimeException $e) { verifier((bool) preg_match($motif, $e->getMessage()), "$nom : « {$e->getMessage()} »"); }
}

// ---------- Données de test ----------

function grilleTest(): array
{
    $poids = fn (array $f, array $p100) => [
        'forfaits' => array_map(fn ($x) => ['de' => $x[0], 'a' => $x[1], 'prix' => $x[2]], $f),
        'par100' => array_map(fn ($x) => ['de' => $x[0], 'a' => $x[1], 'taux' => $x[2]], $p100),
    ];
    $kn = fn (float $k) => $poids([[1, 29, 20 * $k], [30, 39, 22 * $k], [40, 100, 25 * $k]], [[101, 299, 30 * $k], [300, 499, 28 * $k], [500, 1500, 20 * $k]]);
    $bmv = fn (float $k) => $poids([[1, 5, 10 * $k], [6, 50, 15 * $k], [51, 100, 30 * $k]], [[101, null, 40 * $k]]);
    $xpo = fn (float $k) => ['places' => array_map(fn ($n) => round((50 + 20 * ($n - 1)) * $k, 2), range(1, 12))];
    $g = [];
    foreach (['01' => 1.0, '06' => 1.2, '38' => 1.1, '69' => 0.9, '75' => 1.3] as $d => $k) {
        $g['KUEHNE'][$d] = $kn($k);
        $g['BMV'][$d] = $bmv($k);
        $g['XPO'][$d] = $xpo($k);
    }
    unset($g['BMV']['75']);
    return ['grilles' => $g, 'localites' => ['XPO' => [['cp' => '38250', 'ville' => 'VILLARD DE LANS']]]];
}

function reglagesTest(): array
{
    $R = reglagesParDefaut();
    $R['transporteurs']['BMV'] = array_replace($R['transporteurs']['BMV'], ['code_flowone' => '7', 'coef_revision' => 1.05, 'frais_fixes' => 0.5]);
    $R['transporteurs']['KUEHNE'] = array_replace($R['transporteurs']['KUEHNE'], ['frais_fixes' => 1.8, 'rdv' => 3.0,
        'saison' => ['pct' => 12.0, 'du' => '05-01', 'au' => '08-31', 'departements' => ['06']],
        'urbain' => ['montant' => 5.0, 'lieux' => [['dep' => '69', 'ville' => 'LYON'], ['dep' => '75', 'ville' => '*']]]]);
    $R['transporteurs']['XPO'] = array_replace($R['transporteurs']['XPO'], ['code_flowone' => '9', 'frais_fixes' => 3.1,
        'saison' => ['pct' => 12.0, 'du' => '05-01', 'au' => '08-31', 'departements' => ['06']], 'majorations' => ['75' => 5.0],
        'difficile' => ['par_100kg' => 12.0, 'max' => 100.0]]);
    $R['gasoil'] = ['2026-10' => ['BMV' => 11.0, 'KUEHNE' => 11.0, 'XPO' => 25.0], '2026-06' => ['BMV' => 10.0, 'KUEHNE' => 10.0, 'XPO' => 24.0]];
    return completerReglages($R);
}

// Export FLOW ONE inventé : Windows-1252, « ; », CRLF, zéro initial du code postal perdu, deux bons groupés.
function exportTest(): string
{
    $cols = ['OrdNotRead', 'ORDNOORDER', 'CUSNAME', 'DEOZIPCODE', 'ORDDESIGNATION', 'ORDEXPEDATE', 'DEONUMERIC01', 'DEOCARRIER', 'CARRIERDESIGNATION',
        'ORDHTBASETOTAL', 'ORDEXPNUM', 'ORDDELIVERYDATE', 'ORDREFCUSORDER', 'ORDISINVOICED', 'DEONAME', 'DEOFIRSTNAME', 'DEOADDRESS1', 'DEOADDRESS2',
        'DEOCITY', 'DEOCOMMENT', 'ORDTOTALWEIGHT', 'DEONUMERIC06'];
    $l = [
        ['', '1001', 'JARDINERIE ÉTÉ', '1000', '', '01/10/2026', '29', '3', 'KUEHNE', '1000', '1001', '02/10/2026', 'Tab n°1', 'û', 'JARDINERIE ÉTÉ', 'BOURG EN BRESSE', 'ROUTE 1', '', 'BOURG EN BRESSE', 'FERMÉ LE LUNDI', '120.5', '12.83'],
        ['', '1002', 'SERRES', '69003', '', '01/10/2026', '', '3', 'KUEHNE', '2000', '1002', '02/10/2026', 'Mail', 'û', 'SERRES', '', '2 RUE', '', 'LYON 03', 'PRENDRE RDV AVEC M. X', '250', '12.83'],
        ['', '1004', 'GROUPE', '38250', 'Livré avec le bon n°1003', '01/10/2026', '', '3', 'KUEHNE', '500', '1003', '02/10/2026', '', 'û', 'GROUPE', '', 'ZA', '', 'VILLARD-DE-LANS', '', '30', ''],
        ['', '1003', 'GROUPE', '38250', 'Livré avec le bon n°1004', '01/10/2026', '100', '3', 'KUEHNE', '700', '1003', '02/10/2026', '"A;B"', 'û', 'GROUPE', '', 'ZA', '', 'VILLARD-DE-LANS', '', '40', '12.08'],
        ['', '1005', 'SANS PALETTE', '6000', '', '01/10/2026', '', '3', 'KUEHNE', '900', '1005', '02/10/2026', '', 'û', 'X', '', 'Y', '', 'NICE', '', '80', ''],
    ];
    $lignes = [implode(';', $cols)];
    foreach ($l as $x) $lignes[] = implode(';', array_map(fn ($v) => str_starts_with($v, '"') ? $v : mb_convert_encoding($v, 'Windows-1252', 'UTF-8'), $x));
    return implode("\r\n", $lignes) . "\r\n";
}

$R = reglagesTest();
$G = grilleTest();
$exp = fn (string $dep, float $poids, string $date = '2026-10-01', string $ville = 'X', bool $rdv = false, string $cp = '') =>
    ['dep' => $dep, 'poids' => $poids, 'date' => $date, 'rdv' => $rdv, 'dest' => ['cp' => $cp ?: $dep . '000', 'ville' => $ville]];
$pal = fn (int $n120, int $n85, float $h = 1.2) => ['n120' => $n120, 'n85' => $n85, 'hauteur' => $h, 'volume' => ($n120 * 0.96 + $n85 * 0.68) * $h];

// ---------- Outils ----------

egal(codePostal('6000'), '06000', 'CP sur 5 chiffres');
egal(codePostal('3100'), '03100', 'CP 03');
egal(codePostal('123456'), null, 'CP trop long');
egal(codeDep('6'), '06', 'département sur 2 chiffres');
egal(hauteurM(180.0), 1.8, 'hauteur en cm');
egal(hauteurM(1.8), 1.8, 'hauteur en m');
egal(nombreFlowOne(34.27), '34.27', 'nombre FLOW ONE');
egal(nombreFlowOne(100.0), '100', 'nombre FLOW ONE entier');
egal(nombreFlowOne(16.80), '16.8', 'nombre FLOW ONE sans zéro final');
egal(normaliserVille("St-Martin d'Hères"), 'SAINT MARTIN D HERES', 'normalisation des villes');
egal(dateIso('02/10/2026'), '2026-10-02', 'date FLOW ONE');
egal(lieuUrbain([['dep' => '69', 'ville' => 'LYON']], '69', 'LYON 03'), 'LYON', 'Lyon 3e = Lyon');
egal(lieuUrbain([['dep' => '69', 'ville' => 'LYON']], '69', 'LYONNAIS'), null, 'autre commune');
egal(decalerFormule('A1+$B$2+C$3+$D4', 2, 1), 'B3+$B$2+D$3+$D6', 'formule partagée décalée');
egal(decalerFormule("'Feuille 1'!U6*\$AB\$3+3.1", 1, 0), "'Feuille 1'!U7*\$AB\$3+3.1", 'formule avec nom de feuille');
egal(decalerFormule('IF(TODAY()>A1,"B2",C3)', 1, 0), 'IF(TODAY()>A2,"B2",C4)', 'formule avec texte');

// ---------- Prix ----------

$o = offre('KUEHNE', $exp('01', 29.0), $pal(1, 0), $R, $G, 11.0);
egal($o['base'], 21.8, 'KUEHNE 29 kg = forfait 1-29 + frais fixes');
egal($o['taxe'], 2.4, 'KUEHNE taxe gasoil 11 %');
egal($o['total'], 24.2, 'KUEHNE total');
egal(offre('KUEHNE', $exp('01', 29.4), $pal(1, 0), $R, $G, 0)['base'], 23.8, 'KUEHNE 29,4 kg = tranche 30-39');
egal(offre('KUEHNE', $exp('01', 124.0), $pal(1, 0), $R, $G, 0)['base'], round(30 * 130 / 100 + 1.8, 2), 'KUEHNE 124 kg facturé 130 kg');
egal(offre('KUEHNE', $exp('01', 300.2), $pal(1, 0), $R, $G, 0)['base'], round(28 * 310 / 100 + 1.8, 2), 'KUEHNE 300,2 kg → 310 kg tranche 300-499');
egal(offre('KUEHNE', $exp('01', 1600.0), $pal(1, 0), $R, $G, 0)['exclu'][0] ?? '', "poids 1\u{202f}600 kg : au-delà de la grille", 'KUEHNE au-delà de 1 500 kg');
egal(offre('BMV', $exp('01', 5.0), $pal(1, 0), $R, $G, 0)['base'], round(10 * 1.05 + 0.5, 2), 'BMV révision + frais fixes');
egal(offre('BMV', $exp('01', 2000.0), $pal(1, 0), $R, $G, 0)['base'], round(40 * 2000 / 100 * 1.05 + 0.5, 2), 'BMV prix au kilo sans limite');
egal(offre('BMV', $exp('75', 50.0), $pal(1, 0), $R, $G, 0)['exclu'][0] ?? '', 'département 75 absent de la grille', 'BMV hors grille');
egal(offre('XPO', $exp('01', 500.0), $pal(2, 1), $R, $G, 25.0)['lignes'][0][0], 'Grille 5 places (80×85 = 1, 80×120 = 2)', 'XPO 2×80×120 + 1×80×85 = 5 places');
egal(offre('XPO', $exp('01', 500.0), $pal(2, 1), $R, $G, 25.0)['total'], round((130 + 3.1) * 1.25, 2), 'XPO total avec taxe 25 %');
egal(offre('XPO', $exp('01', 50.0), $pal(1, 0), $R, $G, 0)['base'], offre('XPO', $exp('01', 1500.0), $pal(1, 0), $R, $G, 0)['base'], 'XPO : le poids ne change pas le prix');
egal(offre('XPO', $exp('75', 100.0), $pal(0, 1), $R, $G, 0)['base'], round(50 * 1.3 * 1.05 + 3.1, 2), 'XPO région parisienne +5 %');
egal(offre('XPO', $exp('06', 100.0, '2026-06-15'), $pal(0, 1), $R, $G, 0)['base'], round(60 * 1.12 + 3.1, 2), 'XPO saison en juin');
egal(offre('XPO', $exp('06', 100.0, '2026-10-15'), $pal(0, 1), $R, $G, 0)['base'], round(60 + 3.1, 2), 'XPO hors saison en octobre');
egal(offre('KUEHNE', $exp('06', 50.0, '2026-08-31'), $pal(1, 0), $R, $G, 0)['base'], round(25 * 1.2 * 1.12 + 1.8, 2), 'KUEHNE saison le 31 août');
egal(offre('KUEHNE', $exp('69', 50.0, '2026-10-01', 'LYON CEDEX 07'), $pal(1, 0), $R, $G, 0)['base'], round(25 * 0.9 + 1.8 + 5, 2), 'KUEHNE livraison urbaine Lyon');
egal(offre('KUEHNE', $exp('75', 50.0, '2026-10-01', 'PARIS 15'), $pal(1, 0), $R, $G, 0)['base'], round(25 * 1.3 + 1.8 + 5, 2), 'KUEHNE livraison urbaine tout Paris');
egal(offre('KUEHNE', $exp('01', 50.0, '2026-10-01', 'X', true), $pal(1, 0), $R, $G, 0)['base'], round(25 + 1.8 + 3, 2), 'KUEHNE rendez-vous');
egal(offre('XPO', $exp('38', 250.0, '2026-10-01', 'VILLARD-DE-LANS', false, '38250'), $pal(1, 0), $R, $G, 0)['base'], round(70 * 1.1 + 3.1 + 12 * 2.5, 2), 'XPO accès difficile par 100 kg');
egal(offre('XPO', $exp('38', 1500.0, '2026-10-01', 'VILLARD DE LANS', false, '38250'), $pal(1, 0), $R, $G, 0)['lignes'][2][1], 100.0, 'XPO accès difficile plafonné');
$o = offre('XPO', $exp('38', 250.0, '2026-10-01', 'AUTRANS', false, '38250'), $pal(1, 0), $R, $G, 0);
verifier(count($o['lignes']) === 2 && str_contains($o['notes'][0] ?? '', 'vérifier la commune'), 'XPO même CP, autre commune : pas de majoration mais une note');

// Contraintes
egal(offre('BMV', $exp('01', 500.0), $pal(4, 0, 1.0), $R, $G, 0)['exclu'][0], '4 palettes (max 3)', 'BMV 4 palettes exclu');
egal(offre('BMV', $exp('01', 500.0), $pal(3, 0, 1.5), $R, $G, 0)['exclu'][0], 'volume 4,32 m³ (max 4 m³)', 'BMV volume exclu');
egal(offre('KUEHNE', $exp('01', 500.0), $pal(1, 0, 2.15), $R, $G, 0)['exclu'][0], 'hauteur 2,15 m (max 2,1 m)', 'KUEHNE hauteur exclu');
verifier(isset(offre('XPO', $exp('01', 500.0), $pal(6, 0), $R, $G, 0)['total']), 'XPO 6 palettes 80×120 accepté');
verifier(isset(offre('XPO', $exp('01', 500.0), $pal(0, 12), $R, $G, 0)['total']), 'XPO 12 palettes 80×85 accepté');
verifier(str_starts_with(offre('XPO', $exp('01', 500.0), $pal(6, 1), $R, $G, 0)['exclu'][0] ?? '', '13 places'), 'XPO 13 places exclu');
verifier(isset(offre('XPO', $exp('01', 500.0), $pal(1, 0, 2.2), $R, $G, 0)['total']), 'XPO hauteur 2,20 m acceptée');

// ---------- Lot complet ----------

$brut = exportTest();
$export = lireExport($brut);
egal(count($export['lignes']), 5, 'export : 5 bons');
egal($export['lignes'][0]['v'][2], 'JARDINERIE ÉTÉ', 'export : accents Windows-1252');
exception(fn () => lireExport(mb_convert_encoding($brut, 'UTF-8', 'Windows-1252')), '/réenregistré/', 'export réenregistré en UTF-8 refusé');
exception(fn () => lireExport("A;B\r\n1;2\r\n"), '/colonnes absentes/', 'fichier qui n\'est pas l\'export');

$saisies = ['1001' => ['n120' => 1, 'hauteur' => 1.2], '1002' => ['n120' => 2, 'hauteur' => '150'], '1003' => ['n85' => 2, 'hauteur' => 1.0, 'force' => 'XPO']];
$c = calculerLot($export, $saisies, $R, $G, []);
$par = array_column($c['expeditions'], null, 'num');
egal(count($c['expeditions']), 4, 'lot : 4 expéditions (1003 + 1004 groupés)');
egal($c['blocages'], [], 'lot : pas de blocage');
egal($par['1003']['bons'], ['1004', '1003'], 'groupe : bons');
egal($par['1003']['principal'], '1003', 'groupe : bon principal = ORDEXPNUM');
egal($par['1003']['ht'], 1200.0, 'groupe : HT additionné');
egal($par['1003']['poids'], 70.0, 'groupe : poids additionné');
egal($par['1001']['dest']['cp'], '01000', 'lot : CP complété');
egal($par['1001']['dest']['complement'], '', 'complément = ville : retiré');
egal($par['1002']['palettes']['hauteur'], 1.5, 'hauteur saisie en cm');
verifier($par['1002']['rdv'], 'rendez-vous détecté dans les consignes');
egal($par['1005']['erreurs'], ['Données palettes manquantes'], 'sans palettes : ligne rouge');
egal($par['1005']['choix'], null, 'sans palettes : aucun transporteur');
egal($par['1003']['choix'], 'XPO', 'choix forcé');
verifier($par['1003']['moins_cher'] !== 'XPO', 'choix forcé : le moins cher reste connu');
$moins = $par['1001']['moins_cher'];
egal($par['1001']['choix'], $moins, 'choix automatique = moins cher');
foreach ($par['1001']['offres'] as $t => $o) if (isset($o['total'])) verifier($o['total'] >= $par['1001']['offres'][$moins]['total'], "1001 : $t pas moins cher que $moins");
$total = $par['1001']['total'] + $par['1002']['total'] + $par['1003']['total'];
egal($c['jours']['2026-10-01']['taux'], round($total / (1000 + 2000 + 1200) * 100, 2), 'moyenne du jour pondérée');
egal($c['jours']['2026-10-01']['alerte'], $total / 4200 * 100 > 3.5, 'alerte du jour au-dessus de 3,5 %');

// Historique : une autre expédition du jour compte, la même expédition déjà enregistrée est remplacée.
$hist = [['num' => '9999', 'date' => '2026-10-01', 'client' => 'AUTRE', 'total' => 10.0, 'ht' => 1000.0], ['num' => '1001', 'date' => '2026-10-01', 'client' => 'ANCIEN', 'total' => 999.0, 'ht' => 1.0]];
$c2 = calculerLot($export, $saisies, $R, $G, $hist);
egal($c2['jours']['2026-10-01']['taux'], round(($total + 10) / (4200 + 1000) * 100, 2), 'moyenne avec historique, sans doublon');
egal($c2['mois']['2026-10']['nb'], 4, 'moyenne du mois : nombre d\'expéditions');

// Taux gasoil manquant : calcul bloqué, jamais l'ancien taux.
$R2 = $R;
unset($R2['gasoil']['2026-10']['XPO']);
$c3 = calculerLot($export, $saisies, $R2, $G, []);
verifier(str_contains($c3['blocages'][0] ?? '', 'octobre 2026 non saisi pour XPO'), 'gasoil manquant : blocage');
egal(array_filter(array_column($c3['expeditions'], 'choix')), [], 'gasoil manquant : aucun choix');
$R3 = $R;
$R3['transporteurs']['KUEHNE']['rdv'] = null;
verifier(in_array('Réglage à compléter : KUEHNE : frais de rendez-vous.', calculerLot($export, $saisies, $R3, $G, [])['blocages'], true), 'réglage vide : blocage');

// ---------- Fichiers de sortie ----------

$retour = retourFlowOne($brut, $c, $R);
$avant = decouperCsv($brut);
$apres = decouperCsv($retour);
egal(count($apres), count($avant), 'retour : même nombre de lignes');
egal($apres[0], $avant[0], 'retour : même en-tête');
$ix = array_flip(array_map('valeurChamp', $avant[0]));
egal(valeurChamp($apres[1][$ix['DEOCARRIER']]), (string) $R['transporteurs'][$par['1001']['choix']]['code_flowone'], 'retour : code transporteur');
egal(valeurChamp($apres[3][$ix['CARRIERDESIGNATION']]), 'XPO', 'retour : bon secondaire, même transporteur');
egal($apres[5], $avant[5], 'retour : ligne rouge inchangée');
egal($apres[4][$ix['ORDREFCUSORDER']], '"A;B"', 'retour : champ entre guillemets conservé');
verifier(str_ends_with($retour, "\r\n") && substr_count($retour, "\r\n") === substr_count($brut, "\r\n"), 'retour : fins de ligne CRLF');
foreach ($avant as $i => $l) foreach ($l as $j => $v)
    if (!in_array($avant[0][$j], ['DEOCARRIER', 'CARRIERDESIGNATION'], true) && $apres[$i][$j] !== $v) verifier(false, "retour : colonne {$avant[0][$j]} modifiée ligne $i");

$R4 = $R;
$R4['ecriture']['prix_transport'] = 'DEONUMERIC01';
$R4['ecriture']['taxe_gasoil'] = 'DEONUMERIC06';
$apres = decouperCsv(retourFlowOne($brut, $c, $R4));
egal($apres[4][$ix['DEONUMERIC01']], nombreFlowOne($par['1003']['total']), 'retour : prix total sur le bon principal');
egal($apres[3][$ix['DEONUMERIC01']], '0', 'retour : 0 sur l\'autre bon du groupe');
egal($apres[3][$ix['DEONUMERIC06']], '25', 'retour : taxe gasoil sur tous les bons');
egal($apres[5][$ix['DEONUMERIC01']], '', 'retour : ligne rouge, DEONUMERIC01 inchangé');
$R5 = $R;
$R5['transporteurs']['XPO']['code_flowone'] = '';
exception(fn () => retourFlowOne($brut, $c, $R5), '/Code FLOW ONE de XPO inconnu/', 'retour bloqué sans code FLOW ONE');
$modifie = decouperCsv($brut);
$modifie[2][$ix['ORDHTBASETOTAL']] = '1';
exception(fn () => verifierRetour(decouperCsv($brut), $modifie, ['DEOCARRIER', 'CARRIERDESIGNATION'], array_map('valeurChamp', $modifie[0])), '/ORDHTBASETOTAL a été modifiée/', 'contrôle : colonne hors transport');

$f = mb_convert_encoding(fichierTransporteur('XPO', $c, $R), 'UTF-8', 'Windows-1252');
$lignes = array_map(fn ($l) => str_getcsv($l, ';', '"', ''), explode("\r\n", trim($f)));
egal(count($lignes), 2, 'fichier XPO : en-tête + 1 expédition');
$x = array_combine($lignes[0], $lignes[1]);
egal($x['Dest. code postal'], '38250', 'fichier transporteur : CP');
egal($x['Bons de commande'], '1004 / 1003', 'fichier transporteur : bons groupés');
egal($x['Poids (kg)'], '70', 'fichier transporteur : poids');
egal($x['Hauteur (m)'], '1', 'fichier transporteur : hauteur');
egal($x['Date expédition'], '01/10/2026', 'fichier transporteur : date');

$h = array_map('entreeHistorique', array_values(array_filter($c['expeditions'], fn ($e) => $e['choix'])));
$r = recapitulatif($h);
egal($r['total']['nb'], 3, 'récap : nombre');
egal($r['total']['total'], round($total, 2), 'récap : coût total');
egal($r['forces']['nb'], 1, 'récap : choix forcés');
verifier(str_contains(mb_convert_encoding(recapCsv($r, '2026-10'), 'UTF-8', 'Windows-1252'), '2026-10;XPO;38;1;2;70;'), 'récap CSV');

// ---------- Réglages ----------

[$n, $err] = nettoyerReglages(['transporteurs' => ['XPO' => ['saison' => ['departements' => '4, 05 ;6', 'du' => '1/5', 'au' => '31/08']]], 'ecriture' => ['poids' => 'ORDTOTALWEIGHT']]);
egal($n['transporteurs']['XPO']['saison']['departements'], ['04', '05', '06'], 'réglages : départements');
egal($n['transporteurs']['XPO']['saison']['du'], '05-01', 'réglages : date de saison');
verifier(str_contains(implode(' ', $err), 'ORDTOTALWEIGHT'), 'réglages : colonne de calcul interdite');
[, $err] = nettoyerReglages(['ecriture' => ['poids' => 'DEONUMERIC01', 'prix_transport' => 'DEONUMERIC01']]);
verifier(str_contains(implode(' ', $err), 'choisie deux fois'), 'réglages : colonne en double');
[, $err] = nettoyerReglages(['gasoil' => ['2026-10' => ['XPO' => 'abc']]]);
verifier(str_contains(implode(' ', $err), 'nombre positif'), 'réglages : taux gasoil invalide');

// ---------- Import d'un classeur fabriqué ----------

function feuilleXml(array $cellules): string
{
    $lignes = [];
    foreach ($cellules as $ref => $v) {
        preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
        $lignes[(int) $m[2]][colonneIndex($m[1])] = [$ref, $v];
    }
    ksort($lignes);
    $x = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($lignes as $l => $cs) {
        ksort($cs);
        $x .= "<row r=\"$l\">";
        foreach ($cs as [$ref, $v]) {
            if (is_array($v)) $x .= "<c r=\"$ref\"><f" . (isset($v['si']) ? ' t="shared" si="' . $v['si'] . '"' : '') . '>' . htmlspecialchars($v['f'] ?? '') . '</f><v>' . $v['v'] . '</v></c>';
            elseif (is_string($v)) $x .= "<c r=\"$ref\" t=\"inlineStr\"><is><t>" . htmlspecialchars($v) . '</t></is></c>';
            else $x .= "<c r=\"$ref\"><v>$v</v></c>";
        }
        $x .= '</row>';
    }
    return $x . '</sheetData></worksheet>';
}

function classeurTest(string $chemin): void
{
    $L = colonneLettres(...);
    // Tarifs : BMV en D (12 forfaits + base 100), XPO en U (12 places), KUEHNE en AG (8 forfaits + 5 tranches).
    $t = ['D1' => 'BMV', 'U1' => 'XPO', 'AG1' => 'KUEHNE ', 'D2' => 'MAJ 01.01', 'F2' => 1.05, 'A3' => "Departements\nlivrés"];
    $bmv = ['De 1 à 5 kg', 'De 5 à 10 kg', 'De 11 à 15 kg', 'De 16 à 20 kg', 'De 21 à 30 kg', 'De 31 à 40 kg', 'De 41 à 50 kg', 'De 51 à 60 kg', 'De 61 à 70 kg', 'De 71 à 80 kg', 'De 81 à 90 kg', 'De 91 à 100 kg', "De 101 à 200 kg\n(base 100 Kg)"];
    foreach ($bmv as $i => $hd) $t[$L(4 + $i) . '3'] = $hd;
    $t['Q3'] = "Tarif pour \n> 100 Kg \n(base 1 Kg)";
    foreach (range(1, 12) as $n) $t[$L(20 + $n) . '3'] = "$n\n\n" . ($n / 2);
    $kn = ['De 1 à 29 kg', 'De 30 à 39 kg', 'De 40 à 49 kg', 'De 50 à 59 kg', 'De 60 à 69 kg', 'De 70 à 79 kg', 'De 80 à 89 kg', 'De 90 à 100 kg', 'De 101 à 299 kg', 'De 300 à 499 kg', 'De 500 à 699 kg', 'De 700 à 999 kg', 'De 1000 à 1500 kg'];
    foreach ($kn as $i => $hd) $t[$L(33 + $i) . '3'] = $hd;
    $deps = ['01 Ain', '06 Alpes Marit. ( + taxe saison)', '75 Paris + taxe Paris et RP'];
    foreach ($deps as $i => $lib) {
        $r = 6 + $i;
        $t["A$r"] = $lib;
        foreach (range(0, 12) as $k) $t[$L(4 + $k) . $r] = 10.0 + $k + $i;
        if ($i !== 1) foreach (range(1, 12) as $n) $t[$L(20 + $n) . $r] = 50.0 + $n + $i;
        foreach (range(0, 12) as $k) $t[$L(33 + $k) . $r] = 20.0 + $k + $i;
    }
    unset($t['U7']);
    // Comparaison : frais, saison KUEHNE, villes urbaines, formules XPO (frais fixes, région parisienne), KUEHNE aux 100 kg décalé pour 06. Montants inventés.
    $c = ['E2' => 'Frais livraison saisonniere :', 'I2' => 12.0, 'E3' => 'Frais de surete :', 'I3' => 1.5, 'E4' => 'Frais de livraison prise de RDV', 'I4' => 3.0,
        'E5' => 'Frais de livraison urbaine :', 'I5' => 5.0, 'E6' => 'Frais transition énergétique', 'I6' => 0.3, 'AI2' => 'Contribution éco environnementale', 'AL2' => 0.5,
        'AD3' => 'SAISON : ', 'AE3' => 1.12, 'AD4' => 'Paris et Région Parisienne : 5% par position (inclus)',
        'E10' => 'KUEHNE NAGEL', 'W10' => 'XPO Logistique', 'AI10' => 'BMV', 'M11' => 'De 101 à 299 kg', 'O11' => 'De 300 à 499 kg'];
    $comp = [['01 Ain', 1.0], ['06 Alpes Marit. ( + taxe saison) ( + frais liv. urbaine CANNES, NICE)', 1.0], ['75 Paris + taxe Paris et RP ( + frais liv. urbaine)', 1.05]];
    foreach ($comp as $i => [$lib, $k]) {
        $r = 14 + $i;
        $c["A$r"] = $lib;
        $c["W$r"] = ['f' => "'Tarifs à changer chaque année'!U" . (6 + $i) . '*$AB$3' . ($k > 1 ? '*1.05' : '') . '+3.1', 'v' => (51.0 + $i) * $k + 3.1];
        $c["M$r"] = $i === 0 ? ['f' => "N$r/100*\$M\$13+\$I\$3+\$I\$6", 'si' => 0, 'v' => 0] : ['si' => 0, 'v' => 0];
        $c["N$r"] = $i === 1 ? 99.0 : 28.0 + $i;
    }
    unset($c['W15']);
    $l = ['A1' => 'Liste localités accès difficiles', 'A2' => 'Les livraisons dans les zones d\'accès difficiles (montagnes, iles…) sont majorées de 12€ les 100 kilos avec un maximum de perception 100€',
        'A3' => 'Dpt', 'B3' => 'Libelles ', 'C3' => 'code localité', 'D3' => 'CP', 'A4' => '01', 'B4' => 'APREMONT', 'C4' => '011', 'D4' => 1100.0];
    $z = new ZipArchive();
    $z->open($chemin, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="' . NS_REL . '"><sheets>'
        . '<sheet name="KUEHNE - XPO - BMV" sheetId="1" r:id="rId1"/><sheet name="XPO - Localités spéciales" sheetId="2" r:id="rId2"/><sheet name="Tarifs à changer chaque année" sheetId="3" r:id="rId3"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Target="/xl/worksheets/sheet3.xml"/></Relationships>');
    $z->addFromString('xl/worksheets/sheet1.xml', feuilleXml($c));
    $z->addFromString('xl/worksheets/sheet2.xml', feuilleXml($l));
    $z->addFromString('xl/worksheets/sheet3.xml', feuilleXml($t));
    $z->close();
}

$xlsx = tempnam(sys_get_temp_dir(), 'tarifs') . '.xlsx';
classeurTest($xlsx);
$imp = importerClasseur($xlsx);
@unlink($xlsx);
$gi = $imp['grilles'];
egal(array_keys($gi), ['BMV', 'XPO', 'KUEHNE'], 'classeur : trois grilles');
egal($gi['BMV']['06']['forfaits'][0], ['de' => 1, 'a' => 5, 'prix' => 11.0], 'classeur : forfait BMV');
egal($gi['BMV']['01']['par100'], [['de' => 101, 'a' => null, 'taux' => 22.0]], 'classeur : BMV aux 100 kg sans limite');
egal($gi['KUEHNE']['75']['par100'][4], ['de' => 1000, 'a' => 1500, 'taux' => 34.0], 'classeur : KUEHNE 1000-1500 kg');
egal(count($gi['XPO']['01']['places']), 12, 'classeur : XPO 12 places');
verifier(!isset($gi['XPO']['06']), 'classeur : XPO sans prix pour 06');
verifier(str_contains(implode(' ', $imp['alertes']), 'XPO : prix manquants pour les départements 06'), 'classeur : alerte prix manquants');
verifier(str_contains(implode(' ', $imp['alertes']), 'KUEHNE différents'), 'classeur : alerte KUEHNE recopié de travers');
egal($imp['localites']['XPO'], [['cp' => '01100', 'ville' => 'APREMONT']], 'classeur : localités XPO');
[$Ri, $textes] = appliquerReglagesClasseur(reglagesParDefaut(), $imp['reglages']);
$ti = $Ri['transporteurs'];
egal($ti['BMV']['coef_revision'], 1.05, 'classeur : révision BMV');
egal($ti['BMV']['frais_fixes'], 0.5, 'classeur : frais BMV');
egal($ti['KUEHNE']['frais_fixes'], 1.8, 'classeur : frais KUEHNE');
egal($ti['KUEHNE']['rdv'], 3.0, 'classeur : rendez-vous KUEHNE');
egal($ti['KUEHNE']['urbain']['montant'], 5.0, 'classeur : livraison urbaine');
egal($ti['KUEHNE']['urbain']['lieux'], [['dep' => '06', 'ville' => 'CANNES'], ['dep' => '06', 'ville' => 'NICE'], ['dep' => '75', 'ville' => '*']], 'classeur : villes urbaines');
egal($ti['KUEHNE']['saison']['departements'], ['06'], 'classeur : saison KUEHNE');
egal($ti['XPO']['saison']['pct'], 12.0, 'classeur : saison XPO');
egal($ti['XPO']['saison']['departements'], ['06'], 'classeur : départements saison XPO');
egal($ti['XPO']['frais_fixes'], 3.1, 'classeur : frais XPO');
egal($ti['XPO']['majorations'], [75 => 5.0], 'classeur : région parisienne XPO');
egal($ti['XPO']['difficile'], ['par_100kg' => 12.0, 'max' => 100.0], 'classeur : accès difficile');
egal(reglagesManquants($Ri), [], 'classeur : tous les réglages remplis');

// ---------- Bilan ----------

echo "$ok vérifications réussies\n";
if ($echecs) {
    echo count($echecs) . " échec(s) :\n - " . implode("\n - ", $echecs) . "\n";
    exit(1);
}
