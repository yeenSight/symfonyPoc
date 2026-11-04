# symfonyPoc

Application Symfony + PostgreSQL, conteneurisée avec Docker et déployée sur un VPS via GitHub Actions.

## Sommaire
1. [Développement](#1-développement)
2. [Architecture CI/CD](#2-architecture-cicd)
3. [Fichiers à ajouter au dépôt](#3-fichiers-à-ajouter-au-dépôt)
4. [Préparation du VPS](#4-préparation-du-vps)
5. [Clé SSH de déploiement](#5-clé-ssh-de-déploiement)
6. [Secrets GitHub](#6-secrets-github)
7. [Premier déploiement](#7-premier-déploiement)
8. [Dépannage](#8-dépannage)

---

## 1. Développement

Les identifiants ne sont jamais en dur : ils viennent de `.env.docker` (ignoré par git).

```bash
cp .env.docker.example .env.docker   # puis adapter les valeurs
```

### Dev local avec live reload
```bash
docker compose -f compose.dev.yaml --env-file .env.docker up -d database
symfony serve
```
Le `DATABASE_URL` local se place dans `.env.local` (hôte `127.0.0.1`, port 5432, mêmes identifiants que `.env.docker`).

### Stack complète en conteneurs (mode dev)
Fichiers : `Dockerfile.dev` et `compose.dev.yaml` (code monté en volume, port Postgres 5432 publié, Mailpit).
```bash
docker compose -f compose.dev.yaml --env-file .env.docker up --build -d
docker compose -f compose.dev.yaml --env-file .env.docker exec app php bin/console doctrine:migrations:migrate -n
```
Application : http://localhost:8080

Si votre uid n'est pas 1000 (`id -u`) : `--build-arg UID=… --build-arg GID=…`.

### Mode prod en local (test de l'image de production)
Fichiers : `Dockerfile.prod` et `compose.prod.yaml` (image via `APP_IMAGE`) :
```bash
docker build -f Dockerfile.prod -t poc:local-prod .
APP_IMAGE=poc:local-prod docker compose -f compose.prod.yaml --env-file .env.docker up -d
```
Les projets compose (`poc-dev`, `poc-prod`) et leurs volumes sont distincts.

### Tests
```bash
php bin/phpunit
```

---

## 2. Architecture CI/CD

```
push sur main ─► GitHub Actions
                  1. test   : PHPUnit avec un Postgres de service
                  2. build  : image Docker (cible prod) poussée sur ghcr.io
                  3. deploy : connexion SSH au VPS (utilisateur restreint)
                                └─► /opt/app/deploy.sh <sha>   (valeurs envoyées via stdin)
                                     - docker login ghcr.io + docker pull
                                     - extraction de compose.prod.yaml
                                     - docker compose up -d
                                     - migrations Doctrine
```

- **Toutes les valeurs (identifiants Postgres, `APP_SECRET`, token GHCR, infos SSH) sont des secrets GitHub Actions.** Aucune n'est stockée dans le dépôt ni à saisir à la main sur le VPS.
- À chaque déploiement, le workflow envoie ces valeurs au script via l'entrée standard SSH (jamais dans la ligne de commande, donc absentes de l'historique et de `ps`). Le script les écrit dans `/opt/app/.env` (root, `chmod 600`), régénéré à chaque déploiement.
- L'utilisateur `deploy` n'est **pas** dans le groupe `docker` (équivalent root). Sa clé SSH ne peut lancer qu'un seul script, via un sudo limité.

---

## 3. Fichiers à ajouter au dépôt

### 3.1 Fichiers Docker (déjà présents)

| Fichier | Usage |
|---|---|
| `Dockerfile.dev` / `compose.dev.yaml` | développement (volume monté, Mailpit, port 5432) |
| `Dockerfile.prod` / `compose.prod.yaml` | production (code embarqué, assets compilés, volumes `*_prod`) |

`compose.prod.yaml` est autonome, sans valeur par défaut pour les identifiants.

### 3.2 Image de production
`Dockerfile.prod` installe les dépendances sans les paquets de dev, compile les assets AssetMapper et prépare les droits de `var/` et `public/uploads`. Pour des paquets d'importmap locaux, ajoutez `php bin/console importmap:install` avant la compilation.

### 3.3 `.github/workflows/deploy.yml`
```yaml
name: CI/CD
on:
  push:
    branches: [main]
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16-alpine
        env: { POSTGRES_USER: app, POSTGRES_PASSWORD: app, POSTGRES_DB: app }
        ports: ["5432:5432"]
        options: >-
          --health-cmd "pg_isready -U app" --health-interval 5s
          --health-timeout 5s --health-retries 10
    env:
      APP_ENV: test
      DATABASE_URL: postgresql://app:app@127.0.0.1:5432/app?serverVersion=16&charset=utf8
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: "8.3", extensions: "pdo_pgsql, intl, zip" }
      - run: composer install --no-interaction --prefer-dist
      - run: php bin/console doctrine:database:create --if-not-exists
      - run: php bin/console doctrine:migrations:migrate -n --allow-no-migration
      - run: php bin/phpunit

  build:
    needs: test
    if: github.ref == 'refs/heads/main' && github.event_name == 'push'
    runs-on: ubuntu-latest
    permissions: { contents: read, packages: write }
    steps:
      - uses: actions/checkout@v4
      - id: img
        run: echo "name=ghcr.io/${GITHUB_REPOSITORY,,}" >> "$GITHUB_OUTPUT"
      - uses: docker/setup-buildx-action@v3
      - uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}
      - uses: docker/build-push-action@v6
        with:
          context: .
          file: Dockerfile.prod
          push: true
          tags: |
            ${{ steps.img.outputs.name }}:${{ github.sha }}
            ${{ steps.img.outputs.name }}:latest
          cache-from: type=gha
          cache-to: type=gha,mode=max

  deploy:
    needs: build
    runs-on: ubuntu-latest
    environment: production
    permissions: { contents: read, packages: read }
    steps:
      - name: Déploiement SSH
        env:
          KEY: ${{ secrets.VPS_SSH_KEY }}
          KNOWN: ${{ secrets.VPS_KNOWN_HOSTS }}
          POSTGRES_DB: ${{ secrets.POSTGRES_DB }}
          POSTGRES_USER: ${{ secrets.POSTGRES_USER }}
          POSTGRES_PASSWORD: ${{ secrets.POSTGRES_PASSWORD }}
          APP_SECRET: ${{ secrets.APP_SECRET }}
          REGISTRY_USER: ${{ github.actor }}
          REGISTRY_TOKEN: ${{ secrets.GITHUB_TOKEN }}
        run: |
          install -m 700 -d ~/.ssh
          echo "$KEY" > ~/.ssh/id_deploy && chmod 600 ~/.ssh/id_deploy
          echo "$KNOWN" > ~/.ssh/known_hosts
          IMAGE_NAME="ghcr.io/${GITHUB_REPOSITORY,,}"
          # Les valeurs transitent par stdin, jamais dans la ligne de commande
          printf '%s\n' \
            "IMAGE_NAME=$IMAGE_NAME" \
            "POSTGRES_DB=$POSTGRES_DB" \
            "POSTGRES_USER=$POSTGRES_USER" \
            "POSTGRES_PASSWORD=$POSTGRES_PASSWORD" \
            "APP_SECRET=$APP_SECRET" \
            "REGISTRY_USER=$REGISTRY_USER" \
            "REGISTRY_TOKEN=$REGISTRY_TOKEN" \
          | ssh -i ~/.ssh/id_deploy ${{ secrets.VPS_USER }}@${{ secrets.VPS_HOST }} "${{ github.sha }}"
```

---

## 4. Préparation du VPS

À faire une seule fois, en root (ou avec sudo).

### 4.1 Installer Docker (si absent)
```bash
command -v docker >/dev/null || curl -fsSL https://get.docker.com | sh
systemctl enable --now docker
docker --version && docker compose version
```

### 4.2 Créer l'utilisateur de déploiement
```bash
adduser --disabled-password --gecos "" deploy
install -d -o root -g root -m 755 /opt/app
```

### 4.3 Script de déploiement `/opt/app/deploy.sh`
Propriétaire root, `chmod 750`. Il lit les valeurs sur l'entrée standard (format `CLE=valeur`), n'accepte que les clés attendues, et régénère `/opt/app/.env`.
```bash
#!/usr/bin/env bash
set -euo pipefail
cd /opt/app
TAG="${1:-}"
[[ "$TAG" =~ ^[0-9a-f]{40}$ ]] || { echo "tag invalide"; exit 1; }

umask 077
INPUT=$(cat)
ALLOWED='^(IMAGE_NAME|POSTGRES_DB|POSTGRES_USER|POSTGRES_PASSWORD|APP_SECRET|REGISTRY_USER|REGISTRY_TOKEN)='
echo "$INPUT" | grep -E "$ALLOWED" > .env.new
for k in IMAGE_NAME POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD APP_SECRET REGISTRY_USER REGISTRY_TOKEN; do
  grep -q "^$k=." .env.new || { echo "valeur manquante : $k"; rm -f .env.new; exit 1; }
done
mv .env.new .env && chmod 600 .env

get() { grep "^$1=" .env | cut -d= -f2-; }
export APP_IMAGE="$(get IMAGE_NAME):$TAG"

get REGISTRY_TOKEN | docker login ghcr.io -u "$(get REGISTRY_USERenv.new
for k in IMAGE_NAME POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD APP_SECRET REGISTRY_USER REGISTRY_TOKEN; do
  grep -q "^$k=." .env.new || { echo "valeur manquante : $k"; rm -f .env.new; exit 1; }
done
mv .env.new .env && chmod 600 .env

get() { grep "^$1=" .env | cut -d= -f2-; }
export APP_IMAGE="$(get IMAGE_NAME):$TAG"

get REGISTRY_TOKEN | docker login ghcr.io -u "$(get REGISTRY_USER)" --password-stdin
docker pull "$APP_IMAGE"
for f in compose.prod.yaml; do
  docker run --rm --entrypoint cat "$APP_IMAGE" /var/www/html/$f > $f
done

DC="docker compose --env-file .env -f compose.prod.yaml"
$DC up -d --remove-orphans
$DC exec -T app php bin/console doctrine:migrations:migrate -n --allow-no-migration
docker logout ghcr.io
docker image prune -f
```
`REGISTRY_TOKEN` est le `GITHUB_TOKEN` du workflow, valable uniquement pendant l'exécution : aucun token longue durée à créer.

### 4.4 Point d'entrée SSH `/opt/app/ssh-entry.sh`
Propriétaire root, `chmod 755`.
```bash
#!/usr/bin/env bash
exec sudo /opt/app/deploy.sh "${SSH_ORIGINAL_COMMAND:-}"
```

### 4.5 Droit sudo limité
```bash
echo 'deploy ALL=(root) NOPASSWD: /opt/app/deploy.sh *' > /etc/sudoers.d/deploy
chmod 440 /etc/sudoers.d/deploy && visudo -c
```

### 4.6 Pas de fichier `.env` à créer
`/opt/app/.env` est généré par `deploy.sh` à chaque déploiement à partir des secrets GitHub. Ne le créez pas à la main : il serait écrasé.

### 4.7 Package GHCR
Le premier push crée le package en privé. Le `GITHUB_TOKEN` du workflow peut le lire pendant le déploiement : rien à configurer.

### 4.8 Pare-feu
```bash
ufw allow OpenSSH
ufw allow 8080/tcp
ufw enable
```

---

## 5. Clé SSH de déploiement

En local :
```bash
ssh-keygen -t ed25519 -f ./deploy_key -N "" -C "github-deploy"
```

Sur le VPS, autorisez la clé publique, limitée au script :
```bash
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
echo 'command="/opt/app/ssh-entry.sh",restrict '"$(cat deploy_key.pub)" > /home/deploy/.ssh/authorized_keys
chown deploy:deploy /home/deploy/.ssh/authorized_keys
chmod 600 /home/deploy/.ssh/authorized_keys
```
`restrict` interdit shell, tty et forwarding : cette clé ne peut que déclencher un déploiement.

Une fois la clé privée copiée dans GitHub (étape suivante), **supprimez `deploy_key` en local**. Ne la committez jamais.

---

## 6. Secrets GitHub

**Settings → Secrets and variables → Actions → New repository secret** :

| Secret | Valeur |
|---|---|
| `VPS_HOST` | IP ou nom du VPS |
| `VPS_USER` | `deploy` |
| `VPS_SSH_KEY` | contenu de `deploy_key` (clé privée) |
| `VPS_KNOWN_HOSTS` | résultat de `ssh-keyscan -t ed25519 <ip_vps>` |
| `POSTGRES_DB` | nom de la base, ex. `poc` |
| `POSTGRES_USER` | utilisateur Postgres, ex. `poc` |
| `POSTGRES_PASSWORD` | mot de passe fort : `openssl rand -hex 24` |
| `APP_SECRET` | secret Symfony : `openssl rand -hex 32` |

Il n'y a pas d'autre valeur à renseigner : le nom de l'image est déduit du dépôt et le token du registre est le `GITHUB_TOKEN` fourni automatiquement.

Les identifiants `app/app` du job `test` concernent uniquement une base jetable créée pour la CI : ce ne sont pas des valeurs de production.

Attention : changer `POSTGRES_PASSWORD`, `POSTGRES_USER` ou `POSTGRES_DB` après le premier déploiement ne modifie pas la base déjà initialisée dans le volume `database_data`. Choisissez-les avant le premier déploiement.

Optionnel : **Settings → Environments → `production`** pour exiger une validation manuelle avant le déploiement.

---

## 7. Premier déploiement

1. Vérifiez que tout est committé : `Dockerfile.*`, `compose.*.yaml`, `.github/workflows/deploy.yml`, `.dockerignore`.
2. Poussez sur `main`.
3. Suivez le workflow dans l'onglet **Actions**.
4. Contrôlez sur le VPS :
   ```bash
   cd /opt/app
   docker compose --env-file .env -f compose.prod.yaml ps
   docker compose --env-file .env -f compose.prod.yaml logs app --tail 30
   ```
5. Application : `http://<ip_vps>:8080`

Pour un rollback, relancez le job `deploy` d'un ancien run depuis l'onglet **Actions** (**Re-run jobs**). Le `.env` est régénéré à chaque déploiement depuis les secrets GitHub, donc il n'y a pas de déploiement manuel depuis le VPS.

---

## 8. Dépannage

| Symptôme | Piste |
|---|---|
| `required variable … is missing` | Ajouter `--env-file .env.docker` (en local) ou vérifier les secrets GitHub (VPS) |
| `permission denied … docker.sock` | Rouvrir la session après ajout au groupe `docker` (`newgrp docker` ou `wsl --shutdown`) |
| 404 hors page d'accueil | Règle `FallbackResource /index.php` du `Dockerfile.dev` / `Dockerfile.prod` absente |
| `mkdir(): Permission denied` sur les uploads | Vérifier l'uid de `www-data` (dev) et les droits de `public/uploads` |
| `pull access denied` sur le VPS | Vérifier `permissions: packages: read` du job `deploy` et que `REGISTRY_USER` / `REGISTRY_TOKEN` arrivent bien (sinon « valeur manquante » dans les logs) |
| `valeur manquante : …` | Le secret GitHub correspondant est absent ou vide (section 6) |
| SSH refuse la connexion en CI | Vérifier `VPS_KNOWN_HOSTS`, la clé publique dans `authorized_keys` et les droits de `/home/deploy/.ssh` |
| Tests CI en échec | Vérifier le suffixe `_test` de la base et les fixtures dans `doctrine.yaml` |

## Notes
- L'application est servie en HTTP. Pour le HTTPS, placez Caddy ou Traefik devant le conteneur.
- Persistance : la base dans le volume `database_data`, les uploads dans `uploads_data`.

