#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
app=/home/vibe1331/apps/philipfreres
backup=/home/vibe1331/backups/philipfreres/20261015T234044Z-cUgtq0
state=/home/vibe1331/.local/state/philipfreres-deploy
armed=0
rescue=''
marker=''
body=''
tmp=''
stop() { printf 'ARRÊT : %s\n' "$*" >&2; exit 1; }
finish() {
    result=$?
    trap - EXIT INT TERM HUP
    set +e
    if [[ $result -ne 0 && $armed -eq 1 ]]; then
        if mv -f -- "$rescue" "$app/public/.htaccess" && mv -f -- "$marker" "$app/var/deployment-maintenance.json"; then
            printf 'Échec du contrôle : maintenance réactivée, sauvegardes inchangées.\n' >&2
        else
            printf 'ERREUR : vérifier immédiatement la maintenance Apache ; ne pas relancer le déploiement.\n' >&2
        fi
    fi
    for file in "$tmp" "$rescue" "$marker" "$body"; do [[ -z "$file" ]] || rm -f -- "$file"; done
    exit "$result"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP
[[ -f "$state/deploy.lock" && ! -L "$state/deploy.lock" ]] || stop 'Verrou de déploiement attendu absent.'
exec 9>"$state/deploy.lock"
flock -n 9 || stop 'Un déploiement est encore en cours.'
cd "$app"
[[ $(pwd -P) == "$app" && $(git rev-parse --show-toplevel) == "$app" ]] || stop 'Racine applicative inattendue.'
[[ -f var/deployment-maintenance.json && ! -L var/deployment-maintenance.json ]] || stop 'Le marqueur de cette maintenance est absent.'
for file in "$backup/manifest.txt" "$backup/original-public/index.php" "$backup/original-public/.htaccess"; do
    [[ -f "$file" && ! -L "$file" ]] || stop 'Originaux de restauration absents ou invalides.'
done
before=$(awk -F= '$1 == "before" {print $2}' "$backup/manifest.txt")
recorded_app=$(awk -F= '$1 == "app" {print $2}' "$backup/manifest.txt")
[[ "$before" =~ ^[0-9a-f]{40}$ && "$recorded_app" == "$app" && $(git rev-parse HEAD) == "$before" ]] || stop 'Le commit ou le chemin a changé ; restauration automatique refusée.'
[[ ! -e "$backup/SHA256SUMS" && ! -e "$backup/pending-migrations.txt" ]] || stop 'Le déploiement a dépassé la phase attendue.'
grep -Fq 'connect-timeout' "$backup/deployment.log" || stop 'Ce journal ne correspond pas à l’erreur attendue.'
git diff --cached --quiet || stop 'Modifications Git préparées présentes.'
git diff --quiet -- . ':(exclude)public/index.php' ':(exclude)public/.htaccess' || stop 'D’autres fichiers applicatifs ont changé.'
for guard in index.php .htaccess; do
    [[ $(git hash-object "$backup/original-public/$guard") == $(git rev-parse "HEAD:public/$guard") ]] || stop "Original non conforme au commit : $guard"
done
rescue=$(mktemp "$app/public/.recovery-apache-XXXXXX")
printf 'ErrorDocument 503 "Philip Freres - maintenance en cours"\nRewriteEngine On\nRewriteRule ^ - [R=503,L]\n' > "$rescue"
chmod 644 "$rescue"
marker=$(mktemp "$app/var/.recovery-marker-XXXXXX")
printf '{}\n' > "$marker"
chmod 600 "$marker"
body=$(mktemp "$app/var/.recovery-response-XXXXXX")
armed=1
status=$(curl --silent --show-error --proto '=https' --tlsv1.2 --connect-timeout 10 --max-time 20 -o "$body" -w '%{http_code}' https://app.philipfreres.com/fr/login)
[[ "$status" == 503 ]] || stop 'La maintenance HTTP 503 n’est pas confirmée.'
# Apache garde le site fermé pendant la restauration du front-controller.
tmp=$(mktemp "$app/public/.recovery-index-XXXXXX")
cat "$backup/original-public/index.php" > "$tmp"
chmod 644 "$tmp"
mv -f -- "$tmp" "$app/public/index.php"
tmp=$(mktemp "$app/public/.recovery-final-XXXXXX")
cat "$backup/original-public/.htaccess" > "$tmp"
chmod 644 "$tmp"
rm -f -- "$app/var/deployment-maintenance.json"
mv -f -- "$tmp" "$app/public/.htaccess"
status=$(curl --silent --show-error --proto '=https' --tlsv1.2 --connect-timeout 10 --max-time 20 -o "$body" -w '%{http_code}' https://app.philipfreres.com/fr/login)
[[ "$status" == 200 ]] && grep -Fq 'name="_username"' "$body" || stop 'La connexion publique ne répond pas correctement.'
armed=0
printf 'Application précédente remise en ligne. Aucun code métier ni base de données restauré. Sauvegardes conservées.\n'
