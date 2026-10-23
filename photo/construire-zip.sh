#!/bin/sh
# Prépare photo-o2switch.zip, prêt à téléverser sur l'hébergement (aucune dépendance, aucun réglage).
set -e
cd "$(dirname "$0")"
rm -rf build && mkdir -p build/photo
cp index.html .htaccess LISEZMOI.txt logo.png build/photo/
rm -f photo-o2switch.zip
(cd build && zip -qr ../photo-o2switch.zip photo)
rm -rf build
echo "photo-o2switch.zip prêt ($(du -h photo-o2switch.zip | cut -f1))"
