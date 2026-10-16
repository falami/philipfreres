# Philip Frères en local avec Docker

Depuis le dossier du projet, Docker Desktop démarré :

```bash
bash scripts/local-docker.sh up
```

Le groupe **philipfreres** contient l'application PHP/Apache, MySQL, Mailpit, phpMyAdmin et une passerelle Nginx.
Les adresses sont accessibles uniquement depuis le Mac :

| Service | Adresse |
| --- | --- |
| Application | http://localhost:8083/fr/login |
| Codes de connexion et autres e-mails | http://localhost:8027 |
| phpMyAdmin | http://localhost:8084 |

## Importer la copie de production

La base `philipfreres_dev` est initialement vide. Aucun compte ni jeu de démonstration n'est créé.
Télécharger l'export SQL depuis l'hébergement sur le Mac, puis importer **la copie locale** :

```bash
bash scripts/local-docker.sh import "$HOME/Downloads/database.sql.gz"
```

Les fichiers `.sql` non compressés fonctionnent également. Utiliser un export de tables sans
instruction `CREATE DATABASE` ou `USE` désignant le nom de la base de production. Le compte
d'import n'a accès qu'à `philipfreres_dev`. Les sauvegardes SQL créées par le script de
déploiement du projet conviennent normalement à cet usage.

Le script refuse une base déjà remplie ; il n'efface jamais automatiquement une copie existante.
Une erreur SQL peut laisser un import partiel : inspecter la base locale dans phpMyAdmin avant
de recommencer. Il est aussi possible d'importer directement depuis l'onglet Importer de
phpMyAdmin, après avoir sélectionné `philipfreres_dev` (limite 512 Mo).

Après import, utiliser son identifiant et son mot de passe habituels. Lire le code à six chiffres
dans **Mailpit**, même si le compte utilise une adresse Gmail ou professionnelle : les e-mails
restent dans Docker. La double vérification reste active.

Les uploads ne sont pas inclus dans un export SQL. Le volume Docker `philipfreres_uploads` est
séparé des fichiers MAMP. Les photos/documents référencés dans la base resteront absents tant
qu'une copie des uploads n'aura pas été ajoutée dans ce volume.

## Développement

Les modifications PHP/Twig/assets locales sont visibles dans le conteneur. Après une modification
de `config/packages`, redémarrer l'application pour recopier sa configuration :

```bash
bash scripts/local-docker.sh restart
```

Après modification de `composer.lock`, relancer `up` pour reconstruire les dépendances.

```bash
bash scripts/local-docker.sh status
bash scripts/local-docker.sh logs
bash scripts/local-docker.sh console cache:clear
bash scripts/local-docker.sh test
bash scripts/local-docker.sh stop
```

`stop` conserve les données. Ne pas utiliser `docker compose down -v` pour arrêter simplement
le projet : cette option supprimerait les volumes.

`test` exécute par défaut les tests de connexion et du dashboard sur leurs jeux de données
synthétiques en mémoire, indépendants de la copie MySQL importée.

## Isolation

Le fichier `compose.local.yaml` est volontairement distinct des anciens fichiers Compose générés
par Symfony. Le lanceur le sélectionne explicitement et ne lit pas le `.env` de l'application.
Les fichiers privés `.env*` du Mac ne sont pas montés dans l'application Docker. Les clés Stripe,
Google, INSEE et reCAPTCHA de production ne sont pas reprises. Le réseau de l'application est `internal` :
seule la passerelle Nginx rejoint également le réseau de publication des ports, avec des destinations
fixes vers les services locaux. PHP, MySQL, Mailpit et phpMyAdmin n'ont pas d'accès Internet sortant. Les fonctions nécessitant ces API externes
ne sont donc pas disponibles dans cet environnement. Le navigateur peut encore charger les
bibliothèques CSS/JS publiques utilisées par l'interface.

Les identifiants MySQL inclus dans Compose sont dédiés à ce poste de développement. MySQL n'est
pas publié sur un port hôte, et les interfaces web sont liées à `127.0.0.1`. Aucun cron ou worker
métier n'est démarré. La configuration Apache locale ignore le `.htaccess` de production pour
ne pas rediriger localhost vers le site en ligne.

Les fichiers SQL peuvent être conservés hors du projet ou sous `docker/local/imports/`, ignoré
par Git. Ne pas les déposer dans `public/`.
