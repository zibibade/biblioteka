#!/usr/bin/env bash
# Start kontenera Biblioteki: katalogi na pliki, konfiguracja z env, tabele, Apache.
set -euo pipefail

cd /var/www/html

# Wolumen na uploads/ przykrywa katalog z obrazu — trzeba go odtworzyć.
mkdir -p uploads/okladki uploads/okladki-ebooki uploads/zdjecia
if [ ! -f uploads/.htaccess ]; then
    printf 'Options -Indexes\n' > uploads/.htaccess
fi
chown -R www-data:www-data uploads

php docker/init.php

exec "$@"
