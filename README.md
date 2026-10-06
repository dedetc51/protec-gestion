# Protec-Gestion

Fondation sécurisée de l’application de gestion d’une association de protection civile, construite avec Laravel 13 et PostgreSQL 18.

> **Dépôt public :** ne jamais versionner de mots de passe, clés, fichiers `.env`, sauvegardes ou données réelles.

## Développement local

Prérequis : PHP 8.3+, Composer 2, Node.js 24/npm et PostgreSQL 18, ou Docker avec Compose.

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm ci
npm run build
php artisan test
```

L’administrateur initial est créé avec `php artisan protec:ensure-initial-admin`. En production, renseigner `INITIAL_ADMIN_NAME`, `INITIAL_ADMIN_EMAIL` et `INITIAL_ADMIN_PASSWORD` uniquement dans l’environnement d’exécution.

## Conteneurs

Après création d’un `.env` non versionné :

```sh
docker compose build
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan protec:ensure-initial-admin
```

Seul Nginx est publié, sur `127.0.0.1:8080` par défaut. PostgreSQL reste sur le réseau privé Compose et aucun port `5432` n’est publié. Les processus Laravel disposent d’un réseau de sortie distinct afin de vérifier les nouveaux mots de passe auprès de l’API Pwned Passwords par k-anonymat : seul le préfixe de cinq caractères du condensat SHA-1 est transmis. Si cette vérification est indisponible ou invalide, le changement de mot de passe est refusé explicitement. La route `/up` vérifie l’application et la base sans exposer de détails techniques.

## Journal de sécurité

Les connexions, refus de connexion, déconnexions et changements du mot de passe initial sont conservés dans `audit_events`. Ces événements sont immuables et leurs métadonnées suivent une liste blanche afin d’exclure les secrets. La durée de conservation doit être fixée avec l’association avant toute purge automatisée ; jusque-là, aucune suppression automatique n’est exécutée.
