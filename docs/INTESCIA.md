# Marchés suivis — Intescia / DoubleTrade

Le module est accessible dans **Marchés suivis**, pour les administrateurs de l’entité. Aucune connexion, synchronisation automatique ni alerte n’est activée par défaut. Les données existantes de l’application ne sont pas modifiées.

## Installation

1. Déployer le code et la migration additive `DoctrineMigrations\Version20261021090000` avec le processus de sauvegarde habituel. Elle est ajoutée à `deploy/migrations.allowlist` et crée uniquement quatre tables `insight_*`. Ne pas exécuter `doctrine:schema:update --force`. Le retour arrière est volontairement refusé pour ne pas supprimer les marchés importés.
2. Vérifier l’extension PHP **sodium**. Configurer une clé aléatoire de 32 octets encodés en base64 dans `INTESCIA_ENCRYPTION_KEY`, via les variables privées du serveur. Elle chiffre les identifiants OAuth et les jetons en base. La conserver avec les sauvegardes privées ; sa perte impose une reconnexion. Ne pas la changer arbitrairement, ni la committer.

Pour générer la clé **sans l’afficher**, depuis la racine de l’application de production, si les variables sont gérées par `.env.prod.local` et qu’il n’existe pas de configuration compilée :

```bash
(
  set +x
  set -eu
  umask 077
  php <<'PHP'
<?php
$p = '.env.prod.local';
if (is_file('.env.local.php') || is_link($p)) {
    fwrite(STDERR, "Arrêt : configuration compilée ou lien symbolique ; utiliser la gestion des secrets du serveur.\n");
    exit(1);
}
$old = is_file($p) ? file_get_contents($p) : '';
if ($old === false || preg_match('/^\s*INTESCIA_ENCRYPTION_KEY\s*=/m', $old)) {
    fwrite(STDERR, "Arrêt : fichier illisible ou clé déjà présente ; ne pas remplacer la clé.\n");
    exit(1);
}
$key = base64_encode(random_bytes(32));
if (file_put_contents($p, $old."\nINTESCIA_ENCRYPTION_KEY='".$key."'\n", LOCK_EX) === false || !chmod($p, 0600)) {
    fwrite(STDERR, "Échec d’écriture de la configuration privée.\n"); exit(1);
}
echo "Clé privée enregistrée, sans affichage.\n";
PHP
)
```

En développement, utiliser `.env.dev.local` dans ce script au lieu de `.env.prod.local`. Si des variables sont injectées par Docker, elles priment sur les fichiers Dotenv. Le réseau du conteneur local doit autoriser HTTPS vers `sso.intescia.com` et `v1.api.intescia.dev` pour une connexion réelle ; ne pas supprimer l’isolation de la base ou de Mailpit. Les tests simulés n’exigent pas d’accès réseau.

3. Configurer `APP_URL` avec l’origine publique correcte, par exemple `https://app.philipfreres.com` (sans chemin ni barre finale). Conserver `MAILER_DSN` et un `AUTH_MAIL_FROM` autorisé par le SMTP. Le module réutilise ce transport et n’introduit aucun autre fournisseur de mail.
4. Vider/réchauffer le cache avec la procédure normale. Si `.env.local.php` existe, adapter/recompiler la configuration privée avec la procédure de déploiement, sans afficher les secrets.
5. Dans **Connexion**, copier exactement l’URL de retour affichée et la déclarer dans l’application OAuth Intescia. Pour l’entité 1 en production : `https://app.philipfreres.com/fr/administrateur/1/marches/oauth/retour`.
6. Saisir **Client ID** et **Client Secret**, puis continuer chez Intescia. Le mot de passe DoubleTrade n’est jamais demandé à l’application. La connexion utilise Authorization Code + PKCE S256, un état à usage unique valable dix minutes et le renouvellement des jetons.
7. Dans **Paramètres**, choisir les statuts et les destinataires, vérifier le délai et le plafond d’appels, puis demander l’import. Les opportunités doivent être créées par le compte connecté ou lui être assignées dans DoubleTrade. Ce n’est pas un export automatique de toute l’organisation.

## Exécution planifiée

Lancer toutes les cinq minutes, depuis la racine de l’application, avec le même utilisateur système que les autres tâches PHP. Adapter le chemin du binaire PHP de production :

```cron
*/5 * * * * cd /home/vibe1331/apps/philipfreres && APP_ENV=prod APP_DEBUG=0 /CHEMIN/ABSOLU/DE/PHP bin/console app:intescia:run --no-interaction >> var/log/intescia-cron.log 2>&1
```

Le répertoire `var/intescia-locks` doit être inscriptible par PHP web et CLI ; conserver ses verrous hors du cache applicatif. Le verrou local convient à l’installation sur un seul serveur. En déploiement multi-nœuds, remplacer Flock par un verrou partagé avant d’activer les tâches sur plusieurs machines.

- Un clic **Importer la sélection** met la demande en attente du prochain passage.
- L’option **Synchroniser toutes les heures** effectue un import au plus une fois par heure.
- Les rappels partent au premier passage dans la fenêtre choisie (précision de cinq minutes, plus la durée d’un import). Ils ne partent jamais après l’échéance.
- Une importation incomplète suspend les alertes et désactive la synchronisation automatique. Corriger le problème, relancer l’import, puis réactiver l’option si souhaité.
- Un import complet de moins de 24 heures est nécessaire à l’envoi : cela évite des rappels fondés sur une sélection devenue ancienne.
- Suspendre ce cron avec les autres writers pendant les déploiements et restaurations.
- Faire tourner et protéger `var/log/intescia-cron.log`, surveiller le code de sortie et le dernier import complet. Le module ne crée pas le cron à votre place.

Simulation des rappels, sans API ni SMTP ni écritures métier :

```bash
APP_ENV=prod APP_DEBUG=0 php bin/console app:intescia:run --tenant=1 --dry-run
```

Exécution manuelle d’un import **déjà demandé depuis l’interface** et des éventuelles alertes activées :

```bash
APP_ENV=prod APP_DEBUG=0 php bin/console app:intescia:run --tenant=1
```

## Champs et crédits

Documentation consultée :
- https://support-insight.intescia.com/api-marches-suivis-insight/
- https://documentation.v1.api.intescia.dev/contractIntegration/
- https://documentation.v1.api.intescia.dev/swagger/ (OpenAPI `intescia-api-v1.json`)

Le tarif « un crédit par ligne, toutes colonnes comprises » **n’est pas confirmé** dans ces pages. Confirmer avec Intescia les coûts des recherches paginées, fiches, lots, rafraîchissements et droits de l’abonnement avant l’activation automatique. Aucun achat de crédits n’est effectué par ce module.

Le compteur est un nombre d’appels métier HTTP, **pas un compteur de crédits**. Les échanges OAuth ne font pas partie de ce compteur. Les listes de statuts nécessitent également un appel lors de la connexion/actualisation. Aucun appel métier n’est fait lors de la simple consultation des fiches et du tableau.

- La sélection vient de `POST /me/deal-opportunities`, filtrée par statuts et non archivée, avec toutes les valeurs `additionalFields` documentées et les rôles d’interlocuteurs.
- `GET /contracts/{id}` enrichit chaque nouveau marché une seule fois. Les lots suivis sont distingués du marché parent ; plusieurs lots du même marché partagent la fiche de détail.
- `GET /contracts/{id}/lots` récupère toutes les pages, si activé. Une reprise conserve les pages déjà achetées.
- Toutes les réponses de suivi et de détail, leurs champs inconnus et les pages de lots sont conservées en JSON. Les informations sont inspectables et recherchables dans la DataTable **Tous les champs** de chaque fiche, puis exportables en JSON. Les documents liés ne sont pas téléchargés : leurs références sont conservées.
- Pour les champs personnalisés, renseigner les `customFieldConfigurationIds` communiqués par Intescia si nécessaires. Le module ne peut pas inventer des colonnes que l’API/abonnement ne renvoie pas.
- Les synchronisations suivantes actualisent le suivi, ses statuts et sa date de réponse. Elles ne rachètent pas la fiche enrichie. La date de récupération du détail est visible.
- Le bouton **Recharger la fiche et les lots** demande explicitement un nouvel enrichissement au prochain import, avec appels supplémentaires. Les versions précédentes restent stockées dans le JSON. Il est disponible pour les lignes de la sélection active.
- Toute la page de résultats reçue est enregistrée avant les appels d’enrichissement : une erreur de crédit ou de droits ne perd pas ces informations.
- En cas de plafond atteint, les fiches/pages déjà récupérées restent en base. Le prochain import repart de la recherche, et réutilise le cache. Pour les sélections très volumineuses, augmenter le plafond ou réduire les statuts ; les pages de recherche elles-mêmes sont rappelées.
- Les erreurs 402, 403, 429 et les délais réseau interrompent l’import ; aucune boucle de réessai payante automatique. Les redirections de marchés fusionnés sont limitées au même hôte Intescia.

## Alertes et sécurité

La date de référence est `response` (réception des **offres**), et non `posting` (candidatures), `publication`, `deadline` générique ou les dates de travaux. Une date manquante/invalide ne déclenche pas d’alerte. Pour un lot, la date du marché parent ne se substitue jamais à celle du lot. Les dates sont stockées en UTC, affichées en Europe/Paris.

Les destinataires sont contrôlés à l’enregistrement et à l’envoi : appartenance active à la même entité, utilisateur non banni/non désactivé, adresse valide. L’envoi individuel ne révèle pas la liste des destinataires. La fiche web nécessite toujours les droits administrateur ; les autres destinataires reçoivent les informations principales dans le message.

Le registre est unique par marché, destinataire et échéance. Modifier le délai seul ne renvoie pas un rappel déjà envoyé. Une échéance reportée peut donner lieu à un nouveau rappel. Un envoi est réservé avant SMTP ; après une coupure ou une réponse SMTP incertaine, il est affiché **À vérifier** et n’est pas relancé automatiquement. « Envoyé au serveur SMTP » ne garantit pas l’arrivée dans la boîte de réception. Vérifier les journaux du fournisseur avant toute intervention sur un envoi incertain ; ne pas effacer le registre pour relancer en masse.

Les paramètres, données, exports et actions sont isolés par entité et protégés par `ENTITE_ADMIN`. Les formulaires utilisent CSRF. Les identifiants et jetons sont chiffrés, les messages d’erreur sont expurgés, et le profiler est désactivé sur les routes du module pour ne pas enregistrer les secrets des formulaires. Les données externes sont échappées à l’affichage.

Déconnecter désactive les automatismes et supprime les jetons locaux, mais conserve les identifiants de l’application chiffrés et les données métier. Révoquer également l’accès chez Intescia si nécessaire. Aucune suppression automatique des marchés hors sélection : ils restent consultables, sans rappels.

## Vérification avant production

```bash
php vendor/bin/phpunit tests/Service/Intescia tests/Integration/InsightAccessTest.php --do-not-cache-result
php bin/console lint:container
php bin/console lint:twig templates/administrateur/marches templates/emails/marches
node --check public/js/insight.js
```

Les tests utilisent SQLite en mémoire, un serveur HTTP simulé et un transport mail simulé. La recette réelle exige les identifiants et l’option API Intescia : connecter un compte, choisir une petite sélection, comparer titre/acheteur/date/lot au portail, confirmer le coût observé avec Intescia, contrôler un rappel avec des destinataires de test autorisés, puis activer la planification. Aucun appel réel ni envoi réel n’est nécessaire aux tests automatisés.

## Récapitulatif périodique des marchés à venir

Déployer aussi la migration additive `DoctrineMigrations\Version20261021170000` (registre distinct `insight_digest_delivery`, aucune suppression). Dans Paramètres, activer le récapitulatif, choisir 1, 2 ou 7 jours, une date de premier envoi et une heure Europe/Paris. Le jour de la date initiale détermine le jour hebdomadaire. Les destinataires sont communs aux rappels ; les deux options sont indépendantes et désactivées par défaut pour le récapitulatif.

Le même cron `app:intescia:run` exécute les récapitulatifs, sans nouvelle tâche ni appel API propre au récapitulatif. Précision : prochain passage après l’heure (environ 5 minutes plus traitement). Les changements d’heure sont pris en compte. Une exécution manquée peut être rattrapée le jour prévu, jamais en rafale pour les jours antérieurs. Changer l’heure ne renvoie pas un second message le même jour au même destinataire.

Seuls les marchés actifs de l’entité avec une date future sont inclus, par échéance croissante. Pas d’e-mail vide. Un import complet de moins de 24 heures et une connexion autorisée sont requis ; activer la synchronisation horaire pour une utilisation autonome. Les jours restants sont arrondis au supérieur sur les heures restantes ; moins de 24 h est signalé. Les couleurs et le logo proviennent de l’entité ; le logo local est embarqué dans le message. Chaque destinataire reçoit un message individuel HTML et texte. L’historique distingue les envois acceptés par SMTP et ceux à vérifier ; pas de relance automatique après un envoi incertain.

`--dry-run` compte également les récapitulatifs éligibles sans les envoyer ni écrire le registre. Les tests n’appellent ni Intescia ni un serveur SMTP réel.

## Analyse et préparation de la réponse

La fiche marché comporte une analyse des données déjà importées et un espace **Préparer ma réponse**. L’analyse distingue les champs structurés, les extraits du descriptif et les recommandations. Les sources ouvrent les champs correspondants. Les lots, phases, tranches, plafonds et calendriers sont affichés lorsque les données le permettent ; les incohérences et informations manquantes sont signalées. Les pondérations ne sont présentées comme une répartition en pourcentage que si chaque poids est compris entre 0 et 100 et leur somme vaut 100. Une date de renouvellement estimée par Intescia reste distincte d’une échéance contractuelle.

La préparation conserve la décision, le responsable, l’objectif interne, les actions et leurs preuves, les références documentaires, les hypothèses et jusqu’à 40 postes de chiffrage. Les montants sont HT, arrondis au centime par ligne ; la marge affichée est le prix de vente moins les seuls coûts saisis. Les lots et tranches figurant dans le même chiffrage sont additionnés, sans présumer qu’ils seront tous commandés.

Le registre documentaire conserve jusqu’à 30 références (titre, lien HTTP(S), état, version/page/note). Il ne télécharge pas les fichiers, ne lit pas le DCE et ne fait pas d’OCR. L’analyse n’invente ni prix concurrents, ni chance de succès, ni obligations absentes des données. Les plans, pièces et réponses de l’acheteur doivent être consultés pour compléter la préparation.

### Installation

Déployer la migration additive `DoctrineMigrations\Version20261023010000` avec la procédure de migration habituelle. Elle crée uniquement `insight_preparation`. Exemple d’exécution ciblée, une fois par base, depuis la racine du projet :

```bash
APP_ENV=prod APP_DEBUG=0 /usr/local/bin/php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261023010000' --up --no-interaction
```

La consultation reste disponible si cette migration n’a pas encore été exécutée ; seul l’espace de sauvegarde affiche une demande de mise à jour. Aucune modification du cron n’est nécessaire. La simple consultation et la sauvegarde n’appellent pas l’API Intescia.

Les administrateurs de l’entité partagent la préparation. Une sauvegarde concurrente est refusée sans écraser les données précédentes. Les imports conservent les notes et le chiffrage. Les modifications détectées sur l’objet, les critères, les dates ou les lots demandent une relecture ; un changement survenu après l’ouverture du formulaire exige de recharger la fiche. Les 30 dernières sauvegardes conservent un historique de date, auteur, décision et catégories modifiées, sans restauration automatique d’une version antérieure.
