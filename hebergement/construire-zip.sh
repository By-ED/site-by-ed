#!/bin/sh
# Prépare qcm-o2switch.zip, prêt à téléverser sur l'hébergement (dépendances PHP incluses).
set -e
cd "$(dirname "$0")"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --quiet
rm -rf build && mkdir -p build/qcm
cp -r index.html api.php config.exemple.php .htaccess LISEZMOI.txt data vendor build/qcm/
cp data/.htaccess build/qcm/vendor/.htaccess
rm -rf build/qcm/vendor/anthropic-ai/sdk/tests build/qcm/vendor/anthropic-ai/sdk/fixtures build/qcm/vendor/anthropic-ai/sdk/examples build/qcm/vendor/anthropic-ai/sdk/scripts
# Ni historique git, ni tests, ni documentation des dépendances : seulement le code utile.
find build/qcm/vendor -depth -type d \( -name .git -o -name .github -o -name tests -o -name docs \) -exec rm -rf {} +
find build/qcm/data -mindepth 1 ! -name .htaccess -exec rm -rf {} +
rm -f qcm-o2switch.zip
(cd build && zip -qr ../qcm-o2switch.zip qcm)
rm -rf build
echo "qcm-o2switch.zip prêt ($(du -h qcm-o2switch.zip | cut -f1))"
