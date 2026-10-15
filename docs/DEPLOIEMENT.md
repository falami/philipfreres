# Déploiement protégé sur o2switch

Le lanceur `deploy.sh` remplace l'ancien enchaînement `git pull / composer / migrate`. Il cible par défaut `$HOME/apps/philipfreres`, la branche `main` de `origin` et **https://app.philipfreres.com**. Les sauvegardes sont créées dans **`$HOME/backups/philipfreres/<date-UTC>-<identifiant>/`**, à côté des sauvegardes déjà visibles dans cPanel. Aucun autre dossier de sauvegarde n'est modifié.

Cette livraison prépare le mécanisme. Aucun déploiement ni sauvegarde distante n'a été exécuté depuis le poste local.

## Installer cette version

Tous les fichiers de cette livraison doivent être versionnés et publiés sur `origin/main` : le script seul ne suffit pas. Sont notamment requis `scripts/deploy-database.php`, `scripts/deploy-maintenance.php`, `deploy/migrations.allowlist`, `public/index.php`, `public/.htaccess`, la configuration des services et des limiteurs, les migrations de sécurité et les ressources de connexion.

Placer une copie du nouveau `deploy.sh` dans **`$HOME/bin/deploy-philipfreres`** via le gestionnaire de fichiers ou SSH, hors du dépôt applicatif. Le script lit une version distante précise, contrôle que l'avancement depuis la version installée est linéaire et refuse les modifications locales et fichiers non suivis. Déplacer l'ancien `deploy.sh` hors du dépôt s'il n'est pas versionné. Ne pas effacer ni forcer les modifications locales pour contourner un refus.

Dans cPanel, le domaine doit pointer vers **`$HOME/apps/philipfreres/public`**, jamais vers la racine du dépôt. PHP web et PHP CLI doivent utiliser une version compatible (8.2 minimum). Outils nécessaires : Bash, Git, Composer 2, PHP, `mysqldump` ou `mariadb-dump`, `flock`, `rsync`, `curl`, `tar`, `gzip` et `sha256sum`.

La configuration privée de production doit exister dans `.env.local.php`, `.env.local` ou `.env.prod.local` : `APP_ENV=prod`, `APP_DEBUG=0`, secret propre à la production d'au moins 32 caractères, base correcte et transport SMTP actif. Le lanceur ne régénère pas `.env.local.php`, pour préserver les secrets qui pourraient n'exister que dans ce fichier. Il compare une empreinte de la configuration base/secret/SMTP avant et après installation et bloque les migrations si elle change.

Lancer d'abord :

```bash
bash "$HOME/bin/deploy-philipfreres" --check
```

Ce contrôle vérifie les outils, Git, la présence des fichiers cibles, la configuration privée, l'accès en lecture à MySQL, les moteurs InnoDB et l'espace disque estimé. Il crée un dossier privé de journal/candidat et récupère les objets Git ; il ne coupe pas le site, ne sauvegarde pas encore et ne modifie aucune donnée métier. Il ne valide pas la délivrabilité SMTP, le quota utilisateur cPanel, le résultat des migrations ou le comportement HTTPS final.

Suspendre ensuite les tâches cron, imports et workers qui peuvent écrire dans cette application. Fermer les opérations d'administration en cours. Vérifier dans cPanel l'espace restant et la limite du compte : `df` ne mesure pas toujours le quota cPanel. Puis lancer :

```bash
DEPLOY_WRITERS_PAUSED=1 bash "$HOME/bin/deploy-philipfreres"
```

`DEPLOY_WRITERS_PAUSED=1` atteste que ces écritures externes sont arrêtées, ou qu'aucun worker/cron n'existe. Le script ne peut pas découvrir ni arrêter automatiquement les tâches configurées dans cPanel. Il attend par défaut **120 secondes** après activation de la maintenance pour laisser finir les requêtes déjà engagées. Ce délai doit dépasser la durée maximale réelle des requêtes ; si des imports peuvent durer davantage, les arrêter proprement et adapter `DEPLOY_DRAIN_SECONDS` (minimum 30). Cette précaution est particulièrement nécessaire à la toute première installation, lorsque l'ancien code ne possède pas encore de garde.

## Garanties et séquence

1. Verrou exclusif `flock` : un seul déploiement à la fois. Arrêt en cas de branche divergente, de modification locale, de collision avec un fichier privé ou de lien symbolique de données non sauvegardable.
2. Candidat provenant du SHA exact récupéré dans un dossier privé. Pas de fusion Git implicite, de `reset --hard` ni de `rsync --delete` sur les fichiers de l'application.
3. Maintenance **HTTP 503** autonome : les anciens `index.php` et `.htaccess` sont copiés séparément, puis remplacés temporairement par des gardes indépendants de Symfony/Composer. Le 503 est vérifié sur le domaine public avant toute modification du code métier ou de la base.
4. Archive complète de l'application, y compris `.git`, `vendor`, les fichiers privés, les uploads, `public/dist`, les sessions et les compteurs. Seuls le cache compilé, les journaux et le marqueur temporaire sont exclus. Les originaux des deux gardes sont conservés dans `original-public/` : l'archive elle-même contient volontairement les gardes 503.
5. Dump MySQL transactionnel avec données binaires, triggers, routines et événements ; les tables non InnoDB sont refusées. Le mot de passe passe par un fichier temporaire `0600`, jamais dans la ligne de commande. Les options client externes sont isolées. Une configuration TLS/custom non transposable est refusée plutôt que dégradée.
6. Contrôle `gzip`, lecture de l'archive, présence des tables SQL, puis empreintes SHA-256 vérifiées. **Aucune installation de code ou migration ne commence avant ces vérifications.** Elles vérifient l'intégrité des fichiers, pas la réussite d'une restauration : tester régulièrement une restauration séparée.
7. Copie contrôlée du SHA validé ; suppression limitée aux anciens fichiers suivis retirés du commit cible ; mise à jour Git par comparaison de l'ancien SHA. Les fichiers privés ignorés et les données restent en place.
8. Composer depuis le verrou, sans scripts automatiques ; contrôle de la plateforme et audit de dépendances. Nettoyage du cache compilé, sans effacer `var/security/rate-limits` ni `var/sessions`.
9. Comparaison de toutes les migrations en attente avec `deploy/migrations.allowlist`, simulation, puis exécution des seules versions autorisées. Une ancienne migration inconnue bloque le déploiement. Ne jamais marquer toutes les migrations exécutées pour contourner ce contrôle : rapprocher l'historique du schéma réel.
10. Ressources publiques, AssetMapper, cache de production, validation Symfony/Twig/Doctrine, contrôle HTTPS du formulaire et des ressources, absence de debug, CSP/HSTS, maintien du 503 pour les visiteurs sans sonde, puis réouverture et dernier contrôle public HTTP 200.

Les dossiers de sauvegarde sont privés (`0700`), les fichiers créés privés (`0600`). Les ressources publiques générées restent lisibles par Apache. Aucun `chmod 777` n'est utilisé. Aucun ancien backup n'est supprimé automatiquement. Copier régulièrement une sauvegarde vers un stockage externe protégé : une copie sur le même hébergement ne couvre pas sa perte complète.

## En cas d'échec

Le journal `deployment.log` et le fichier `status` indiquent le résultat. Le script laisse une maintenance Apache 503, même si le garde PHP est encore présent dans un ancien OPcache. Il supprime les fichiers temporaires de connexion MySQL et de sonde. Il **ne restaure pas automatiquement la base** : les migrations DDL MySQL peuvent avoir été partiellement appliquées et une restauration aveugle pourrait détruire des données.

Un OPcache web configuré avec `validate_timestamps=0` exige une invalidation ou un redémarrage du processus PHP depuis l'hébergement avant validation. Un `opcache_reset()` lancé en CLI ne vide pas le cache du processus web. Le test sans sonde vérifie que les visiteurs sont bien bloqués, et un échec réinstalle la barrière Apache.

Si le site est resté en maintenance, ne pas simplement supprimer le marqueur. Examiner le journal privé et la phase atteinte. Avant la copie du code, les originaux sont dans `original-public/`; après le début d'une migration, vérifier la base avant de remettre un ancien code en service. Les commandes contenant des secrets et les logs ne doivent pas être collés dans une conversation publique.

## Restaurer une sauvegarde

Cette procédure est volontairement manuelle, car elle remplace des données. Conserver le site en maintenance et les workers arrêtés pendant toute l'opération.

1. Choisir le dossier exact correspondant à l'état voulu. Vérifier les archives avant toute restauration :

   ```bash
   cd "$HOME/backups/philipfreres/DATE-IDENTIFIANT"
   sha256sum --check SHA256SUMS
   gzip -t application.tar.gz database.sql.gz
   ```

2. Sauvegarder séparément l'état en échec si des données doivent être conservées. Extraire l'archive applicative dans un **répertoire vide privé**, jamais par-dessus une version différente : des fichiers ajoutés après le backup resteraient autrement présents.
3. Restaurer le SQL dans une **base vide isolée**, avec le client MySQL adapté et un fichier de connexion privé. Le dump comporte `CREATE DATABASE`/`USE` pour le nom original (`--databases`) : ne pas l'importer sur le serveur de production sans vérifier et adapter explicitement la destination. Une simple sélection `mysql autre_base` ne remplace pas les instructions `USE` du dump. Faire intervenir l'administrateur pour une restauration sur place ou vers un autre nom.
4. Configurer la copie restaurée pour sa base restaurée, contrôler le schéma et les données, puis réinstaller/réchauffer son cache avec les versions de `vendor` sauvegardées. Vérifier les fichiers utilisateurs et la connexion sur un environnement isolé avant bascule.
5. Les deux fichiers `original-public/index.php` et `original-public/.htaccess` sont ceux d'avant déploiement : les remettre dans la copie restaurée uniquement au moment contrôlé de la remise en service. Garder une barrière de maintenance extérieure pendant les vérifications si l'ancienne version ne possède pas le garde PHP.
6. Basculer ensemble les fichiers et la base cohérents, vérifier HTTPS, le formulaire et un parcours métier, puis seulement réactiver cron/workers. Conserver la sauvegarde initiale et l'état en échec jusqu'à validation.

Le script ne fournit pas une transaction atomique couvrant Git, MySQL et le système de fichiers ; il garantit une fermeture contrôlée et une sauvegarde vérifiée avant les étapes risquées. Le compte cPanel, les droits SQL de sauvegarde, le certificat, le proxy éventuel et la restauration réelle doivent être validés sur l'hébergement.

## Tests réalisés

- Suite PHP complète : **156 tests, 902 assertions**, incluant l'authentification, les helpers de sauvegarde, le garde de maintenance et la conservation des quotas après un vrai nettoyage du cache.
- **18 scénarios de déploiement simulé** : vrai dépôt Git local, archives `tar/gzip`, copie `rsync` et empreintes SHA-256 ; services PHP/Composer/MySQL/HTTP simulés. Les échecs de sauvegarde, de dépendances, de migration, de configuration et de contrôle HTTP bloquent la réouverture. PHP web en développement, debug actif et ressource redirigée sont également refusés.
- Vérification PHP-CGI locale du statut 503 et des en-têtes du garde avant chargement de Symfony.

Les simulations ne constituent pas un déploiement sur o2switch ni un essai de restauration de la base distante. Le SMTP réel et la configuration Apache/OPcache du serveur restent à vérifier lors de l'installation.

Références : [déploiement Symfony 7.4](https://symfony.com/doc/7.4/deployment.html), [héberger Symfony chez o2switch](https://faq.o2switch.fr/guides/php/heberger-application-symfony/).
