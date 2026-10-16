#!/bin/sh
set -eu
mkdir -p /var/www/app/var /var/www/app/public/uploads
cp -R /opt/project-packages/. /var/www/app/config/packages/
mkdir -p /var/www/app/config/packages/dev
cp /opt/docker-dev.yaml /var/www/app/config/packages/dev/zz_docker_local.yaml
chown www-data:www-data /var/www/app/var /var/www/app/public/uploads
exec apache2-foreground
