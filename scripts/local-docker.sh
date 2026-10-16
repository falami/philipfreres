#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Do not let Compose read the application's production .env or its default files.
compose=(docker compose --env-file docker/local/environment.env -f compose.local.yaml -p philipfreres)
case "${1:-help}" in
  up)
    "${compose[@]}" up -d --build
    printf '\nApplication : http://localhost:8083/fr/login\nE-mails locaux : http://localhost:8027\nBase de données : http://localhost:8084\nImporter la copie SQL dans philipfreres_dev avant de se connecter.\n'
    ;;
  stop) "${compose[@]}" stop ;;
  restart) "${compose[@]}" restart app ;;
  status) "${compose[@]}" ps ;;
  logs) "${compose[@]}" logs --tail=80 app ;;
  console) shift; "${compose[@]}" exec -u www-data app php bin/console "$@" ;;
  test)
    shift
    if [[ $# == 0 ]]; then
      set -- tests/Integration/EmailTwoFactorLoginTest.php tests/Repository/FuelDashboardRepositoryTest.php
    fi
    "${compose[@]}" exec -u www-data -e APP_ENV=test app php vendor/bin/phpunit --cache-directory var/phpunit-cache "$@"
    ;;
  import)
    dump="${2:?Utilisation : bash scripts/local-docker.sh import /chemin/base.sql[.gz]}"
    [[ -f "$dump" ]] || { echo 'Fichier SQL introuvable.' >&2; exit 1; }
    count="$("${compose[@]}" exec -T database sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -N -u "$MYSQL_USER" "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"')"
    [[ "$count" == 0 ]] || { echo 'ARRÊT : la base Docker contient déjà des tables. Aucun écrasement automatique.' >&2; exit 1; }
    # The local application account has privileges only on philipfreres_dev.
    # A dump containing USE another_database will fail rather than target it.
    if [[ "$dump" == *.gz ]]; then
      gzip -t "$dump"
      gzip -dc "$dump" | "${compose[@]}" exec -T database sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql --binary-mode=1 -u "$MYSQL_USER" "$MYSQL_DATABASE"'
    else
      "${compose[@]}" exec -T database sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql --binary-mode=1 -u "$MYSQL_USER" "$MYSQL_DATABASE"' < "$dump"
    fi
    "${compose[@]}" exec -u www-data app php bin/console cache:clear --env=dev
    echo 'Copie SQL importée dans Docker. Connexion : http://localhost:8083/fr/login'
    ;;
  *) echo 'Usage : bash scripts/local-docker.sh {up|stop|restart|status|logs|test [fichier]|console ...|import fichier.sql[.gz]}' ;;
esac
