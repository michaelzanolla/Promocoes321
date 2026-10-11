#!/usr/bin/env bash
# Backup do banco, para o cron diário do cPanel. Mantém 7 dias em ~/promocoes-shared/backups.
#
# Uso: bash ~/promocoes/deploy/backup.sh NOME_DO_BANCO
# Usuário e senha ficam em ~/promocoes-shared/backup.cnf (permissão 600), nunca na linha do cron:
#   [client]
#   user=USUARIO_DO_BANCO
#   password="SENHA"
set -euo pipefail
umask 077

DB=${1:?Uso: backup.sh NOME_DO_BANCO}
DIR=$HOME/promocoes-shared/backups
FILE=$DIR/$DB-$(date +%F).sql.gz
mkdir -p "$DIR"

# Grava num arquivo temporário: um dump que falhou no meio nunca substitui um backup bom.
mysqldump --defaults-extra-file="$HOME/promocoes-shared/backup.cnf" --single-transaction --no-tablespaces "$DB" | gzip > "$FILE.tmp"
mv "$FILE.tmp" "$FILE"
find "$DIR" -name '*.sql.gz' -mtime +7 -delete
