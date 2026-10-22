#!/bin/sh
# Prépare transport-o2switch.zip, prêt à téléverser sur l'hébergement (aucune dépendance à installer).
set -e
cd "$(dirname "$0")"
php tests/tester.php
rm -rf build && mkdir -p build/transport
cp -r index.html api.php moteur.php classeur.php config.exemple.php .htaccess LISEZMOI.txt data build/transport/
# Jamais de données (grilles, clients, historique) dans le zip : seulement le dossier protégé, vide.
find build/transport/data -mindepth 1 ! -name .htaccess -exec rm -rf {} +
rm -f transport-o2switch.zip
(cd build && zip -qr ../transport-o2switch.zip transport)
rm -rf build
echo "transport-o2switch.zip prêt ($(du -h transport-o2switch.zip | cut -f1))"
