#!/usr/bin/env bash
# Publica o último commit na HostGator por SSH.
#
# Uso (Git Bash, na raiz do projeto):   deploy/deploy.sh promocoes
# "promocoes" é o Host do ~/.ssh/config (servidor, usuário, porta e chave: README, seção Produção).
# Opcional: REMOTE_PHP (padrão php; ex.: /opt/cpanel/ea-php83/root/usr/bin/php)
#
# No PC: compila os assets e instala as dependências (sem as de desenvolvimento) numa cópia do commit.
# No servidor (sem Node nem Composer): descompacta em ~/promocoes.new, liga o .env e o storage de
# ~/promocoes-shared, roda as migrations e troca as pastas. A versão anterior fica em ~/promocoes.old.
set -euo pipefail

TARGET=${1:?Uso: deploy/deploy.sh promocoes}
REMOTE_PHP=${REMOTE_PHP:-php}

composer_run() {
    if command -v composer >/dev/null 2>&1; then composer "$@"; else php "$USERPROFILE/bin/composer.phar" "$@"; fi
}

if [ -n "$(git status --porcelain)" ]; then
    echo "Há alterações não commitadas: o deploy publica só o que está commitado. Faça o commit antes." >&2
    exit 1
fi

REV=$(git rev-parse --short HEAD)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
mkdir "$WORK/app"

echo "→ Compilando os assets"
npm run build

echo "→ Montando a versão $REV"
git archive HEAD | tar -x -C "$WORK/app"
cp -r public/build "$WORK/app/public/"
# CACHE_STORE=array: os scripts do Composer não podem depender do banco local.
(cd "$WORK/app" && CACHE_STORE=array composer_run install --no-dev --optimize-autoloader --no-interaction --quiet)
echo "$REV" > "$WORK/app/REVISION"
tar -czf "$WORK/release.tgz" -C "$WORK/app" .

echo "→ Enviando ($(du -h "$WORK/release.tgz" | cut -f1))"
scp "$WORK/release.tgz" "$TARGET:promocoes-release.tgz"

echo "→ Publicando no servidor"
ssh "$TARGET" "bash -s -- '$REMOTE_PHP'" <<'REMOTE'
set -euo pipefail
PHP=$1
APP=$HOME/promocoes NEW=$HOME/promocoes.new OLD=$HOME/promocoes.old SHARED=$HOME/promocoes-shared

if [ ! -f "$SHARED/.env" ]; then
    echo "Falta $SHARED/.env: crie a partir de deploy/env.production.example (README, seção Produção)." >&2
    exit 1
fi

rm -rf "$NEW" && mkdir "$NEW"
tar -xzf "$HOME/promocoes-release.tgz" -C "$NEW" && rm "$HOME/promocoes-release.tgz"

# .env e storage (sessões, logs, imagens enviadas) são compartilhados entre as versões.
[ -d "$SHARED/storage" ] || cp -r "$NEW/storage" "$SHARED/storage"
rm -rf "$NEW/storage"
ln -s "$SHARED/storage" "$NEW/storage"
ln -s "$SHARED/.env" "$NEW/.env"

# Migrations rodam com a versão antiga ainda no ar: elas não podem quebrar o código anterior.
(cd "$NEW" && "$PHP" artisan migrate --force)

rm -rf "$OLD"
if [ -d "$APP" ]; then mv "$APP" "$OLD"; fi
mv "$NEW" "$APP"

# Caches só depois da troca: guardam caminhos absolutos (~/promocoes, não ~/promocoes.new).
cd "$APP"
"$PHP" artisan storage:link
"$PHP" artisan optimize
# O cache de configuração contém as mesmas senhas do .env.
chmod 600 bootstrap/cache/config.php
echo "Publicado: versão $(cat REVISION)"
REMOTE
