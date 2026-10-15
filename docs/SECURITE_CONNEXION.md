# Connexion sécurisée — Philip Frères

## Fonctionnement livré

Le mot de passe ne donne plus accès aux pages métier. Le firewall Symfony passe par Scheb TwoFactorBundle, puis un code e-mail de six chiffres est exigé. La session intermédiaire ne peut accéder qu'au formulaire de code, à son renvoi et à la déconnexion ; le voter métier refuse aussi ce jeton intermédiaire.

- Code aléatoire, usage unique, validité de 10 minutes, 5 essais maximum ; une demande complète expire après 30 minutes.
- Renvoi après 60 secondes, avec invalidation immédiate du code précédent. Échec SMTP : aucun code utilisable et aucun accès accordé.
- Limites partagées entre sessions par compte : 5 envois et 10 vérifications en 15 minutes, complétées par des limites par IP et le throttling du mot de passe.
- « Rester connecté sur cet appareil » sélectionné par défaut, désactivable sur un appareil partagé. Le cookie n'est créé qu'après le code correct.
- Connexion mémorisée pendant au plus 90 jours depuis sa création. Les validateurs aléatoires sont hachés en base, renouvelés lors de la reprise, et liés au mot de passe, à l'adresse, aux rôles, à l'état du compte et à la version de sécurité.
- Reprises simultanées : famille bornée de validateurs pour une génération de 60 secondes, écriture conditionnelle avec nouvelles tentatives bornées ; la génération précédente expire après une grâce fixe de 60 secondes.
- Déconnexion explicite en POST avec CSRF : révocation du jeton de cet appareil. « Déconnecter tous les appareils » incrémente atomiquement la version de sécurité, invalidant sessions et cookies au prochain contrôle.
- Compte suspendu ou banni : refus du mot de passe, du code et des reprises de connexion. Les changements de mot de passe et d'identité invalident les sessions existantes.
- Réinitialisation : jeton aléatoire stocké sous forme de hash, validité d'une heure, consommation atomique à usage unique, limites compte/IP et réponse publique identique pour une adresse inconnue. Nouveau mot de passe de 12 à 128 caractères. L'inscription ne remplace jamais un compte existant.

Les écrans de connexion, de code, de sécurité, d'accès restreint et de récupération utilisent les ressources CSS/JS locales. Les pages sensibles ont des en-têtes anti-cache et anti-cadrage et une CSP stricte sur les écrans autonomes. Les liens de compte sont générés pour **https://app.philipfreres.com**, indépendamment de l'en-tête Host reçu.

## Mise en production

Le code et les deux migrations ont été préparés dans le dépôt local. L'environnement distant n'a pas été déployé par cette intervention.

1. Sauvegarder la base et déployer ensemble le code, `composer.lock`, les migrations et `public/css/auth.css` / `public/js/auth.js`.
2. Utiliser PHP 8.2 ou plus, `APP_ENV=prod`, `APP_DEBUG=0`, un `APP_SECRET` propre au déploiement et un `MAILER_DSN` de production provenant de la configuration privée. Ne pas recopier les secrets du développement.
3. Installer les versions verrouillées avec `composer install --no-dev --optimize-autoloader`.
4. Vérifier l'historique des migrations de la base cible. Les deux changements nécessaires sont `DoctrineMigrations\Version20261016010000` (colonne `utilisateur.security_version`) et `DoctrineMigrations\Version20261016011000` (table `remembered_device`). Ne pas appliquer aveuglément d'anciennes migrations non exécutées. Sur une base déjà alignée, appliquer ces deux versions dans cet ordre avec `doctrine:migrations:execute --up`.
5. Vérifier HTTPS, le certificat et le domaine exact `app.philipfreres.com`. En production, les autres hôtes sont refusés, HTTP redirige vers HTTPS, les cookies sont Secure et HSTS est activé sur HTTPS.
6. Si TLS se termine sur un reverse proxy, déclarer **uniquement** ses adresses fiables et ses en-têtes dans la configuration Symfony (`SYMFONY_TRUSTED_PROXIES`, `SYMFONY_TRUSTED_HEADERS`). Vérifier que `Request::isSecure()` voit HTTPS pour éviter une boucle de redirection ; ne pas faire confiance à tous les clients.
7. Autoriser l'expéditeur `no-reply@philipfreres.com` chez le fournisseur SMTP et vérifier sa configuration DNS SPF/DKIM/DMARC. Tester la réception réelle et les délais sur une boîte de contrôle avant ouverture. Les tests automatisés remplacent le transport : ils ne démontrent pas la délivrabilité réelle.
8. Vider/réchauffer le cache de production et tester mot de passe → code → application, reprise après fermeture du navigateur, renvoi, déconnexion et récupération.

Les cookies ont de nouveaux noms (`PHILIPFRERESSESSIDV2`, `PHILIPFRERES_REMEMBER_V2`) : les anciens utilisateurs devront se reconnecter une fois pour activer le nouveau dispositif. Les anciens liens de réinitialisation stockés en clair ne sont plus acceptés ; une nouvelle demande est nécessaire.

## Exploitation

Le stockage de session configuré est natif avec verrouillage de fichier dans `var/sessions/<env>`. Les permissions doivent être limitées au processus PHP. Cette configuration cible un serveur applicatif ; pour plusieurs instances, prévoir un stockage partagé avec verrouillage, ainsi qu'un cache de limitation et des verrous partagés. Ne pas activer plusieurs instances avec des sessions ou compteurs indépendants.

Conserver le stockage des limitations entre déploiements ; éviter de purger les compteurs lors d'une attaque. Les erreurs de transport sont journalisées sans leur contenu ni leurs identifiants de connexion. Ne pas exposer le profiler Symfony en production : les outils de développement peuvent contenir le contenu des e-mails. La table des appareils est purgée progressivement des jetons expirés à chaque nouvel appareil mémorisé.

La sécurité par e-mail dépend aussi de la sécurité de la boîte du destinataire. Elle n'offre pas la résistance au phishing d'une passkey. Aucune garantie d'invulnérabilité n'est déduite des contrôles réalisés.

## Validation et limites de l'audit

La suite complète passe : **105 tests, 718 assertions**, sans erreur ni dépréciation. Les tests d'intégration exécutent le vrai firewall contre SQLite en mémoire, avec des comptes synthétiques et un mailer simulé. Ils couvrent les tentatives de contournement avant le code, expirations, renvois, quotas, CSRF, comptes bloqués, cookies persistants, rotation, révocation, récupération à usage unique et origine des liens. Les tests du provider couvrent aussi les entrelacements de rotations, les bornes de validité et le stockage des seuls hashes. Il ne s'agit pas d'un test de charge concurrent entre processus MySQL de production.

Commandes :

```sh
php vendor/bin/phpunit
php bin/console lint:container
php bin/console lint:yaml config --parse-tags
php bin/console lint:twig templates
composer audit
```

L'audit Composer effectué le 16 octobre 2026 ne signale plus de vulnérabilité connue dans les versions verrouillées après mise à jour. Cela ne constitue pas un audit de pénétration indépendant ni une validation du serveur de production.

Les parcours locaux ont été rejoués en lecture seule : connexion, listes et formulaires métier, tableaux de bord, choix d'entité, pages superadministrateur et restrictions employé. Les corrections incluent le contrôle de l'entité réellement modifiée, les rattachements actifs, les redirections sûres, la sélection d'entité sans écriture métier et les vues superadministrateur bloquées. Deux défauts simples de l'audit initial ont aussi été corrigés : la relation inverse inexistante ALX (mapping et schéma Doctrine désormais valides) et la redirection après suppression TOTAL (rejet CSRF vérifié sans suppression).

Les dix autres anomalies de l'audit initial ne sont pas annoncées comme résolues : création d'organisme incomplète, imports TOTAL (colonnes et valeurs numériques), scripts de photo engin/utilisateur, suppression de photo matériel, pagination des collections, calcul des intervalles de consommation, colonnes et bornes de dates de la carte carburant. Ces modules nécessitent un lot distinct de corrections fonctionnelles et de tests métier ; la refonte livrée ici porte sur la sécurité et les parcours d'accès.

Le déploiement protégé et la restauration sont décrits dans [DEPLOIEMENT.md](DEPLOIEMENT.md). Les compteurs sont désormais stockés dans `var/security/rate-limits`, hors du cache compilé, avec des clés stables pour le mot de passe : un nettoyage du cache ne lève plus les blocages. La suite complète après ce durcissement du déploiement comprend 156 tests et 902 assertions.

Références techniques : [fonctionnement Scheb TwoFactorBundle](https://symfony.com/bundles/SchebTwoFactorBundle/7.x/index.html), [recommandations d'authentification OWASP](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html).
