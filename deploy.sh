#!/usr/bin/env bash
# Usage : DEPLOY_WRITERS_PAUSED=1 bash deploy.sh ; précontrôles : bash deploy.sh --check
# Le corps entier est chargé avant exécution, même si Git remplace ce fichier.
deploy_main() {
    set -Eeuo pipefail
    umask 077
    export PATH="$HOME/bin:$PATH"
    export APP_ENV=prod APP_DEBUG=0
    export PHILIP_DEPLOY_APP_DIR="${PHILIP_DEPLOY_APP_DIR:-$HOME/apps/philipfreres}"
    deploy_origin='https://app.philipfreres.com'
    deploy_check=0
    deploy_logging=0
    case "${1:-}" in --check) deploy_check=1 ;; '') ;; *) printf 'Usage : bash deploy.sh [--check]\n' >&2; exit 2 ;; esac
    [[ $# -le 1 ]] || exit 2
    fail() { printf 'ARRÊT : %s\n' "$*" >&2; exit 1; }
    note() {
        local timestamp
        timestamp=$(date -u +%FT%TZ)
        printf '\n[%s] %s\n' "$timestamp" "$*"
        if [[ $deploy_logging -eq 1 ]]; then printf '\n[%s] %s\n' "$timestamp" "$*" >&3; fi
    }
    for tool in git php composer tar gzip rsync curl flock sha256sum du df awk find mktemp tail grep; do
        command -v "$tool" >/dev/null || fail "Outil manquant : $tool"
    done
    dump_bin=$(command -v mariadb-dump || command -v mysqldump || true)
    [[ -n "$dump_bin" ]] || fail 'mysqldump ou mariadb-dump est nécessaire.'
    php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || fail 'PHP CLI 8.2 minimum requis.'
    [[ $EUID -ne 0 ]] || fail 'Utiliser le compte cPanel, pas root.'
    cd "$PHILIP_DEPLOY_APP_DIR"
    PHILIP_DEPLOY_APP_DIR=$(pwd -P)
    [[ "$PHILIP_DEPLOY_APP_DIR" != / && "$PHILIP_DEPLOY_APP_DIR" != "$HOME" ]] || fail 'Répertoire applicatif invalide.'
    [[ -f composer.lock && -f vendor/autoload.php && -f public/index.php && -f public/.htaccess ]] || fail 'Application existante incomplète.'
    for path in public var vendor public/index.php public/.htaccess; do [[ ! -L "$path" ]] || fail "Lien symbolique non pris en charge : $path"; done
    for path in public/uploads public/dist config/gcp var/security var/sessions .env.local .env.local.php .env.prod.local; do
        [[ ! -L "$path" ]] || fail "Données externes à sauvegarder séparément : $path"
        if [[ -d "$path" ]]; then [[ -z $(find "$path" -type l -print -quit) ]] || fail "Liens dans les données privées : $path"; fi
    done
    state_dir="${PHILIP_DEPLOY_STATE_DIR:-$HOME/.local/state/philipfreres-deploy}"
    backups_dir="${PHILIP_BACKUP_DIR:-$HOME/backups/philipfreres}"
    [[ ! -L "$state_dir" && ! -L "$backups_dir" ]] || fail 'Répertoires privés sous forme de liens refusés.'
    mkdir -p "$state_dir" "$backups_dir"
    chmod 700 "$state_dir" "$backups_dir"
    backups_dir=$(cd "$backups_dir" && pwd -P)
    case "$backups_dir/" in "$PHILIP_DEPLOY_APP_DIR/"*|"$HOME/public_html/"*) fail 'Les sauvegardes doivent rester hors de toute racine web.' ;; esac
    exec 9>"$state_dir/deploy.lock"
    flock -n 9 || fail 'Un autre déploiement est déjà en cours.'
    [[ ! -e var/deployment-maintenance.json && ! -L var/deployment-maintenance.json ]] || fail 'Maintenance précédente active : restaurer ou terminer avant de relancer.'
    [[ $(git rev-parse --show-toplevel) == "$PHILIP_DEPLOY_APP_DIR" ]] || fail 'Racine Git inattendue.'
    [[ $(git symbolic-ref --short HEAD) == main ]] || fail 'La branche active doit être main.'
    git diff --quiet && git diff --cached --quiet || fail 'Modifications Git locales présentes : elles ne seront pas écrasées.'
    [[ -z $(git ls-files --others --exclude-standard) ]] || fail 'Fichiers non suivis présents. Versionner les fichiers prévus ; garder le lanceur hors du dépôt.'
    before_sha=$(git rev-parse HEAD)
    run_dir=$(mktemp -d "$backups_dir/$(date -u +%Y%m%dT%H%M%SZ)-XXXXXX")
    maintenance=0
    exec 3>&1 4>&2
    on_exit() {
        result=$?
        trap - EXIT INT TERM HUP
        set +e
        rm -f "$run_dir/mysql.cnf" "$run_dir/probe.curl"
        if [[ $result -ne 0 ]]; then
            printf '\nÉCHEC (%s). Journal privé : %s/deployment.log\n' "$result" "$run_dir" >&2
            if [[ $maintenance -eq 1 ]]; then
                if [[ ! -e "$PHILIP_DEPLOY_APP_DIR/var/deployment-maintenance.json" ]]; then
                    printf '{}\n' > "$PHILIP_DEPLOY_APP_DIR/var/deployment-maintenance.json"
                    chmod 600 "$PHILIP_DEPLOY_APP_DIR/var/deployment-maintenance.json"
                fi
                # Même un OPcache web ancien ne doit pas pouvoir ouvrir le site.
                rescue_file=$(mktemp "$PHILIP_DEPLOY_APP_DIR/public/.deploy-rescue-XXXXXX")
                printf 'ErrorDocument 503 "Philip Freres - maintenance en cours"\nRewriteEngine On\nRewriteRule ^ - [R=503,L]\n' > "$rescue_file"
                chmod 644 "$rescue_file"
                mv "$rescue_file" "$PHILIP_DEPLOY_APP_DIR/public/.htaccess"
                printf 'Maintenance Apache conservée. Ne pas rouvrir avant contrôle. Sauvegarde : %s\n' "$run_dir" >&2
            fi
            printf 'FAILED\n' > "$run_dir/status"
            if [[ $deploy_logging -eq 1 ]]; then
                printf '\nDernières lignes du journal de déploiement :\n' >&4
                tail -n 40 "$run_dir/deployment.log" >&4
            fi
        fi
        exec 1>&3 2>&4
        exec 3>&- 4>&-
        exit "$result"
    }
    trap on_exit EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM HUP
    # Un fichier ordinaire fonctionne aussi dans les environnements sans /dev/fd.
    # Les étapes restent visibles via note() ; les sorties détaillées vont au journal.
    exec >> "$run_dir/deployment.log" 2>&1
    deploy_logging=1
    note 'Lecture de la version distante et vérification de l’avance rapide.'
    git fetch --no-tags origin main
    target_sha=$(git rev-parse refs/remotes/origin/main)
    git merge-base --is-ancestor "$before_sha" "$target_sha" || fail 'Historique divergent : aucune réinitialisation forcée.'
    printf 'before=%s\ntarget=%s\napp=%s\n' "$before_sha" "$target_sha" "$PHILIP_DEPLOY_APP_DIR" > "$run_dir/manifest.txt"
    mkdir "$run_dir/candidate"
    candidate="$run_dir/candidate"
    git archive "$target_sha" | tar -x -C "$candidate"
    for path in scripts/deploy-database.php scripts/deploy-maintenance.php deploy/migrations.allowlist public/index.php public/.htaccess; do
        [[ -f "$candidate/$path" ]] || fail "Fichier absent de origin/main : $path"
    done
    grep -Fq 'scripts/deploy-maintenance.php' "$candidate/public/index.php" || fail 'Garde de maintenance absent du front-controller cible.'
    [[ -z $(find "$candidate" -type l -print -quit) ]] || fail 'Version cible avec liens symboliques : revue nécessaire.'
    # Matérialiser les listes avant de les lire : une erreur Git doit arrêter le
    # déploiement, même si Git a déjà écrit une partie de sa sortie.
    git ls-tree -r --name-only -z "$target_sha" > "$run_dir/candidate-files.list"
    git diff --name-only --diff-filter=D -z "$before_sha" "$target_sha" > "$run_dir/deleted-files.list"
    while IFS= read -r -d '' path; do
        case "$path" in var/*|vendor/*|public/uploads/*|public/dist/*|public/vendor/*|assets/vendor/*|.env.local|.env.local.php|.env.*.local|config/gcp/*|config/.env*) fail "Chemin privé suivi dans la version cible : $path" ;; esac
        if [[ -e "$path" || -L "$path" ]]; then git ls-files --error-unmatch -- "$path" >/dev/null 2>&1 || fail "Collision avec un fichier local : $path"; fi
    done < "$run_dir/candidate-files.list"
    cp "$candidate/scripts/deploy-database.php" "$run_dir/database-helper.php"
    db_helper="$run_dir/database-helper.php"
    note 'Contrôle de la base et de la configuration de production, sans écriture métier.'
    php "$db_helper" preflight
    configuration_before=$(php "$db_helper" configuration-fingerprint)
    db_name=$(php "$db_helper" database-name)
    db_bytes=$(php "$db_helper" database-size)
    [[ "$db_bytes" =~ ^[0-9]+$ ]] || fail 'Estimation de la taille de base invalide.'
    app_kb=$(du -sk . | awk '{print $1}')
    free_kb=$(df -Pk "$backups_dir" | awk 'END {print $4}')
    required_kb=$((app_kb * 3 + db_bytes / 1024 * 3 + 1048576))
    [[ "$free_kb" -gt "$required_kb" ]] || fail 'Espace insuffisant : sauvegarde + marge de travail + 1 Gio requis.'
    note 'Vérification des options du client de sauvegarde, avant maintenance.'
    dump_options=(--single-transaction --quick --hex-blob --routines --triggers --events --no-tablespaces --default-character-set=utf8mb4)
    "$dump_bin" --no-defaults --help > "$run_dir/dump-help.txt"
    if grep -q -- '--set-gtid-purged' "$run_dir/dump-help.txt"; then dump_options+=(--set-gtid-purged=OFF); fi
    if grep -q -- '--no-login-paths' "$run_dir/dump-help.txt"; then dump_options+=(--no-login-paths); fi
    php "$db_helper" dump-config "$run_dir/mysql.cnf"
    # --help valide les options réelles sans connexion ni sauvegarde SQL. Ne pas
    # journaliser sa sortie : certains clients affichent les valeurs configurées.
    "$dump_bin" --defaults-file="$run_dir/mysql.cnf" "${dump_options[@]}" --help > /dev/null 2> "$run_dir/dump-client-check.err" \
        || fail "Options refusées par le client de sauvegarde. Diagnostic privé : $run_dir/dump-client-check.err"
    (cd "$candidate" && composer validate --no-check-publish --no-plugins --no-scripts)
    if [[ $deploy_check -eq 1 ]]; then
        printf 'CHECKED\n' > "$run_dir/status"
        note 'Précontrôles terminés. Aucun code installé, aucune migration, aucune coupure. Vérifier aussi le quota cPanel.'
        return
    fi
    [[ ${DEPLOY_WRITERS_PAUSED:-0} == 1 ]] || fail 'Suspendre cron/workers qui écrivent, puis lancer avec DEPLOY_WRITERS_PAUSED=1 (également si aucun worker n’existe).'
    drain_seconds=${DEPLOY_DRAIN_SECONDS:-120}
    [[ "$drain_seconds" =~ ^[0-9]+$ && "$drain_seconds" -ge 30 ]] || fail 'DEPLOY_DRAIN_SECONDS doit être un entier >=30, supérieur à la durée maximale des requêtes en cours.'
    note 'Activation de la maintenance, y compris pour la première livraison.'
    mkdir -p var "$run_dir/original-public"
    cp -p public/index.php public/.htaccess "$run_dir/original-public/"
    probe=$(php -r 'echo bin2hex(random_bytes(32));')
    printf '%s' "$probe" | php -r '$s=stream_get_contents(STDIN); echo json_encode(["probe_hash"=>hash("sha256",$s)], JSON_THROW_ON_ERROR);' > var/deployment-maintenance.json
    chmod 600 var/deployment-maintenance.json
    maintenance=1
    temporary_file=$(mktemp public/.deploy-index-XXXXXX)
    cat > "$temporary_file" <<'PHP'
<?php
http_response_code(503);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
header('Retry-After: 120');
echo "Philip Frères — mise à jour en cours. Merci de réessayer dans quelques instants.\n";
PHP
    chmod 644 "$temporary_file"
    mv "$temporary_file" public/index.php
    temporary_file=$(mktemp public/.deploy-apache-XXXXXX)
    printf 'ErrorDocument 503 "Philip Freres - maintenance en cours"\nRewriteEngine On\nRewriteRule ^ - [R=503,L]\n' > "$temporary_file"
    chmod 644 "$temporary_file"
    mv "$temporary_file" public/.htaccess
    http_status=$(curl --silent --show-error --proto '=https' --tlsv1.2 --connect-timeout 10 --max-time 30 -o "$run_dir/maintenance-response.txt" -w '%{http_code}' "$deploy_origin/fr/login")
    [[ "$http_status" == 503 ]] || fail 'Maintenance HTTP 503 non confirmée sur le domaine public : code et base non modifiés.'
    note "Attente de fin des requêtes déjà en cours : $drain_seconds secondes."
    sleep "$drain_seconds"
    note 'Sauvegarde complète : code, vendor, fichiers privés, uploads, sessions et compteurs.'
    tar --exclude='./var/cache' --exclude='./var/log' --exclude='./var/deployment-maintenance.json' -czf "$run_dir/application.tar.gz.part" .
    mv "$run_dir/application.tar.gz.part" "$run_dir/application.tar.gz"
    gzip -t "$run_dir/application.tar.gz"
    tar -tzf "$run_dir/application.tar.gz" > "$run_dir/application-files.txt"
    note 'Sauvegarde transactionnelle de la base.'
    "$dump_bin" --defaults-file="$run_dir/mysql.cnf" "${dump_options[@]}" --databases "$db_name" | gzip > "$run_dir/database.sql.gz.part"
    mv "$run_dir/database.sql.gz.part" "$run_dir/database.sql.gz"
    rm -f "$run_dir/mysql.cnf"
    gzip -t "$run_dir/database.sql.gz"
    gzip -dc "$run_dir/database.sql.gz" | awk '/^CREATE TABLE/ {found=1} END {exit !found}' || fail 'Le dump ne contient aucune table.'
    (cd "$run_dir" && sha256sum application.tar.gz database.sql.gz original-public/index.php original-public/.htaccess > SHA256SUMS && sha256sum --check SHA256SUMS)
    printf 'BACKUP_VERIFIED\n' > "$run_dir/status"
    note "Sauvegarde vérifiée : $run_dir"
    # Copier le SHA validé sans fusion ni suppression des fichiers ignorés par Git.
    rsync -a --checksum --chmod=Du+rwx,Dgo+rx,Fu+rw,Fgo+r --exclude='/public/index.php' --exclude='/public/.htaccess' "$candidate/" ./
    while IFS= read -r -d '' path; do
        case "$path" in public/index.php|public/.htaccess) fail 'La version cible retire un garde indispensable.' ;; esac
        [[ ! -d "$path" ]] || fail "Suppression ambiguë d’un répertoire : $path"
        rm -f -- "$path"
    done < "$run_dir/deleted-files.list"
    git update-ref refs/heads/main "$target_sha" "$before_sha"
    git read-tree "$target_sha"
    note 'Installation des dépendances verrouillées sans scripts automatiques.'
    composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-scripts
    composer check-platform-reqs --no-dev
    composer audit --no-dev
    # Ne jamais régénérer .env.local.php : il peut contenir les seuls secrets de production.
    php bin/console cache:clear --env=prod --no-debug --no-warmup
    [[ $(php "$db_helper" configuration-fingerprint) == "$configuration_before" ]] || fail 'Configuration base/SMTP/secret modifiée : aucune migration ne sera exécutée.'
    note 'Vérification stricte des migrations en attente.'
    php "$db_helper" pending-migrations "$candidate/deploy/migrations.allowlist" > "$run_dir/pending-migrations.txt"
    while IFS= read -r migration; do
        [[ -n "$migration" ]] || continue
        php bin/console doctrine:migrations:execute --up "$migration" --dry-run --no-interaction --env=prod --no-debug
    done < "$run_dir/pending-migrations.txt"
    while IFS= read -r migration; do
        [[ -n "$migration" ]] || continue
        php bin/console doctrine:migrations:execute --up "$migration" --no-interaction --env=prod --no-debug
    done < "$run_dir/pending-migrations.txt"
    note 'Ressources et contrôles de production.'
    (
        umask 022
        php bin/console assets:install public --env=prod --no-debug
        php bin/console importmap:install --env=prod --no-debug
        php bin/console asset-map:compile --env=prod --no-debug
    )
    php bin/console cache:warmup --env=prod --no-debug
    php bin/console lint:container --env=prod --no-debug
    php bin/console lint:twig templates --env=prod --no-debug
    php bin/console doctrine:schema:validate --env=prod --no-debug
    find var -type d -exec chmod 770 {} +
    find var -type f -exec chmod 660 {} +
    chmod 600 var/deployment-maintenance.json
    for private_env in .env.local.php .env.local .env.prod.local; do [[ ! -f "$private_env" ]] || chmod 600 "$private_env"; done
    for guard in index.php .htaccess; do
        temporary_file=$(mktemp public/.deploy-final-XXXXXX)
        cp "$candidate/public/$guard" "$temporary_file"
        chmod 644 "$temporary_file"
        mv "$temporary_file" "public/$guard"
    done
    note 'Contrôle HTTPS de la nouvelle version avant réouverture.'
    printf 'header = "X-Deployment-Probe: %s"\n' "$probe" > "$run_dir/probe.curl"
    curl --config "$run_dir/probe.curl" --fail --silent --show-error --proto '=https' --tlsv1.2 --connect-timeout 10 --max-time 30 -D "$run_dir/health-headers.txt" -o "$run_dir/health-login.html" "$deploy_origin/fr/login"
    grep -Fq 'name="_username"' "$run_dir/health-login.html" || fail 'Formulaire attendu absent.'
    grep -Fq 'css/auth.css' "$run_dir/health-login.html" || fail 'Nouvelle interface absente.'
    grep -Eiq '^X-Deployment-Environment: prod[[:space:]]*$' "$run_dir/health-headers.txt" || fail 'PHP web n’a pas confirmé l’environnement prod (vérifier aussi OPcache).'
    grep -Eiq '^X-Deployment-Debug: 0[[:space:]]*$' "$run_dir/health-headers.txt" || fail 'PHP web n’a pas confirmé la désactivation du debug.'
    if grep -Eiq '^X-Debug-(Token|Token-Link):' "$run_dir/health-headers.txt"; then fail 'Debug exposé en production.'; fi
    grep -Eiq '^Strict-Transport-Security:' "$run_dir/health-headers.txt" || fail 'HSTS absent.'
    grep -Eiq '^Content-Security-Policy:' "$run_dir/health-headers.txt" || fail 'CSP absente de la connexion.'
    for asset in css/auth.css js/auth.js; do
        http_status=$(curl --silent --show-error --proto '=https' --tlsv1.2 --connect-timeout 10 --max-time 30 -o /dev/null -w '%{http_code}' "$deploy_origin/$asset")
        [[ "$http_status" == 200 ]] || fail "Ressource publique indisponible : $asset"
    done
    git diff --quiet && git diff --cached --quiet || fail 'Les fichiers installés ne correspondent pas exactement au commit cible.'
    http_status=$(curl --silent --show-error --proto '=https' --connect-timeout 10 --max-time 30 -o /dev/null -w '%{http_code}' "$deploy_origin/fr/login")
    [[ "$http_status" == 503 ]] || fail 'La maintenance doit encore protéger les visiteurs avant validation finale.'
    rm -f var/deployment-maintenance.json "$run_dir/probe.curl"
    http_status=$(curl --silent --show-error --proto '=https' --tlsv1.2 --connect-timeout 10 --max-time 30 -o "$run_dir/public-login.html" -w '%{http_code}' "$deploy_origin/fr/login")
    [[ "$http_status" == 200 ]] || fail 'La réouverture publique n’a pas renvoyé HTTP 200.'
    grep -Fq 'name="_username"' "$run_dir/public-login.html" || fail 'Le formulaire public est absent après réouverture.'
    maintenance=0
    printf 'SUCCESS\n' > "$run_dir/status"
    note "Déploiement validé : $target_sha. Sauvegarde conservée : $run_dir"
    note 'Les tâches cron/workers peuvent être réactivés. Aucun ancien backup supprimé automatiquement.'
}
deploy_main "$@"
