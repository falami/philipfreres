#!/usr/bin/env bash
# Reprise ciblée du refus de Version20261023010000, depuis une sauvegarde vérifiée.
# Installer ce fichier HORS du dépôt avant exécution sur le serveur.
resume_main() {
    set -Eeuo pipefail
    umask 077
    export PATH="$HOME/bin:$PATH" APP_ENV=prod APP_DEBUG=0
    export PHILIP_DEPLOY_APP_DIR="${PHILIP_DEPLOY_APP_DIR:-$HOME/apps/philipfreres}"
    fail() { printf 'ARRÊT : %s\n' "$*" >&2; exit 1; }
    note() { printf '\n%s\n' "$*" >&3; printf '\n%s\n' "$*"; }
    [[ $# == 1 && ${DEPLOY_WRITERS_PAUSED:-0} == 1 ]] || fail 'Usage : DEPLOY_WRITERS_PAUSED=1 bash resume-tender-deployment.sh /chemin/backup-en-echec'
    for tool in git php composer curl flock sha256sum mktemp grep awk; do command -v "$tool" >/dev/null || fail "Outil absent : $tool"; done
    cd "$PHILIP_DEPLOY_APP_DIR"
    PHILIP_DEPLOY_APP_DIR=$(pwd -P)
    for path in public var public/index.php public/.htaccess; do [[ ! -L "$path" ]] || fail "Lien symbolique refusé : $path"; done
    run_dir=$(cd "$1" && pwd -P)
    [[ "$run_dir" != "$PHILIP_DEPLOY_APP_DIR" && ! -L var/deployment-maintenance.json && -f var/deployment-maintenance.json ]] || fail 'Maintenance locale absente ou invalide.'
    [[ -f "$run_dir/manifest.txt" && -f "$run_dir/SHA256SUMS" && -f "$run_dir/deployment.log" ]] || fail 'Sauvegarde incomplète.'
    [[ $(cat "$run_dir/status") == FAILED ]] || fail 'Cette reprise exige un déploiement en échec.'
    grep -Fq 'Migration en attente non autorisée (DoctrineMigrations\Version20261023010000)' "$run_dir/deployment.log" || fail 'Ce script ne correspond pas à cet échec.'
    [[ $(awk -F= '$1=="app" {print substr($0,5)}' "$run_dir/manifest.txt") == "$PHILIP_DEPLOY_APP_DIR" ]] || fail 'Sauvegarde d’une autre application.'
    target_sha=$(awk -F= '$1=="target" {print $2}' "$run_dir/manifest.txt")
    [[ "$target_sha" =~ ^[0-9a-f]{40}$ && $(git rev-parse HEAD) == "$target_sha" ]] || fail 'Le commit installé a changé : reprise refusée.'
    [[ $(git symbolic-ref --short HEAD) == main ]] || fail 'Branche inattendue.'
    state_dir="${PHILIP_DEPLOY_STATE_DIR:-$HOME/.local/state/philipfreres-deploy}"
    [[ -d "$state_dir" && ! -L "$state_dir" ]] || fail 'Répertoire de verrou absent ou invalide.'
    exec 9>"$state_dir/deploy.lock"
    flock -n 9 || fail 'Un déploiement est déjà en cours.'
    git diff --quiet -- . ':!public/index.php' ':!public/.htaccess' || fail 'Modifications locales hors gardes de maintenance.'
    git diff --cached --quiet || fail 'Index Git modifié.'
    [[ -z $(git ls-files --others --exclude-standard) ]] || fail 'Fichier non suivi : placer le script de reprise hors du dépôt.'
    [[ $(sha256sum migrations/Version20261023010000.php | awk '{print $1}') == 59f7008e793e5e2ae7b2048edf83d825019f3e4874dc34c85bcf78a8cca46612 ]] || fail 'La migration diffère de la version examinée.'
    (cd "$run_dir" && sha256sum --check SHA256SUMS) >/dev/null
    exec 3>&1
    exec >> "$run_dir/resume-tender.log" 2>&1
    maintenance=1
    on_exit() {
        result=$?
        trap - EXIT INT TERM HUP
        set +e
        rm -f "$run_dir/probe.curl"
        if [[ $result -ne 0 ]]; then
            printf '{}\n' > var/deployment-maintenance.json
            chmod 600 var/deployment-maintenance.json
            rescue_file=$(mktemp public/.deploy-rescue-XXXXXX)
            printf 'ErrorDocument 503 "Philip Freres - maintenance en cours"\nRewriteEngine On\nRewriteRule ^ - [R=503,L]\n' > "$rescue_file"
            chmod 644 "$rescue_file"
            mv "$rescue_file" public/.htaccess
            printf 'FAILED\n' > "$run_dir/status"
            printf 'Reprise interrompue. Maintenance conservée. Journal : %s/resume-tender.log\n' "$run_dir" >&3
            tail -n 20 "$run_dir/resume-tender.log" >&3
        fi
        exit "$result"
    }
    trap on_exit EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM HUP
    deploy_origin='https://app.philipfreres.com'
    http_status=$(curl --silent --show-error --proto '=https' --tlsv1.2 --max-time 30 -o /dev/null -w '%{http_code}' "$deploy_origin/fr/login")
    [[ "$http_status" == 503 ]] || fail 'Maintenance HTTPS non confirmée.'
    note 'Sauvegarde et commit vérifiés. Contrôle de la configuration et de la seule migration autorisée.'
    db_helper="$PHILIP_DEPLOY_APP_DIR/scripts/deploy-database.php"
    php "$db_helper" preflight
    composer check-platform-reqs --no-dev
    composer audit --no-dev
    printf 'DoctrineMigrations\\Version20261023010000\n' > "$run_dir/resume-migrations.allowlist"
    php "$db_helper" pending-migrations "$run_dir/resume-migrations.allowlist" > "$run_dir/resume-pending.txt"
    while IFS= read -r migration; do
        [[ -n "$migration" ]] || continue
        php bin/console doctrine:migrations:execute --up "$migration" --dry-run --no-interaction --env=prod --no-debug
        php bin/console doctrine:migrations:execute --up "$migration" --no-interaction --env=prod --no-debug
    done < "$run_dir/resume-pending.txt"
    php "$db_helper" pending-migrations "$run_dir/resume-migrations.allowlist" > "$run_dir/resume-pending.txt"
    [[ ! -s "$run_dir/resume-pending.txt" ]] || fail 'Une migration reste en attente.'
    candidate=$(mktemp -d "$run_dir/resume-public-XXXXXX")
    mkdir "$candidate/public"
    git show "$target_sha:public/index.php" > "$candidate/public/index.php"
    git show "$target_sha:public/.htaccess" > "$candidate/public/.htaccess"
    probe=$(php -r 'echo bin2hex(random_bytes(32));')
    printf '%s' "$probe" | php -r '$s=stream_get_contents(STDIN); echo json_encode(["probe_hash"=>hash("sha256",$s)], JSON_THROW_ON_ERROR);' > var/deployment-maintenance.json
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
resume_main "$@"
