#!/bin/sh
set -eu

install -d -o root -g www-data -m 750 /etc/firespot
install -o root -g www-data -m 640 /run/secrets/app_environment /etc/firespot/firespot.env
install -o root -g www-data -m 640 /run/secrets/master_key /etc/firespot/master.key

for directory in /var/www/html/portal-v3/uploads /var/www/html/assets/ads; do
    install -d -o www-data -g www-data -m 755 "$directory"
    install -o root -g root -m 644 /opt/firespot-dev/uploads.htaccess "$directory/.htaccess"
done
install -d -o www-data -g www-data -m 755 \
    /var/www/html/portal-v3/uploads/branding \
    /var/www/html/portal-v3/uploads/content

exec "$@"
