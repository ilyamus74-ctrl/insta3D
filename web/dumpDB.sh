
#!/usr/bin/env bash
set -euo pipefail
IFS=$'\n\t'
umask 077

CFG="/home/makler/web/configs/connectDB.php"
OUT_DIR="/home/makler/web/MySqlDump"

fail(){ echo "ERROR: $*" >&2; exit 1; }

# Вытащить значение переменной из PHP файла вида: $var = "value"; или 'value';
get_php_var() {
  local var="$1"
  sed -nE "s/^[[:space:]]*[$]${var}[[:space:]]*=[[:space:]]*['\"]([^'\"]*)['\"][[:space:]]*;.*/\1/p" "$CFG" | head -n1
}

command -v mysqldump >/dev/null 2>&1 || fail "mysqldump not found"
command -v gzip >/dev/null 2>&1 || fail "gzip not found"
[[ -f "$CFG" ]] || fail "Config not found: $CFG"
mkdir -p "$OUT_DIR"

DB_HOST="$(get_php_var dblocation || true)"
DB_NAME="$(get_php_var dbname     || true)"
DB_USER="$(get_php_var dbuser     || true)"
DB_PASS="$(get_php_var dbpasswd   || true)"
DB_PORT="$(get_php_var dbport     || true)"
DB_PORT="${DB_PORT:-3306}"

[[ -n "$DB_HOST" ]] || fail "Can't parse \$dblocation from $CFG"
[[ -n "$DB_NAME" ]] || fail "Can't parse \$dbname from $CFG"
[[ -n "$DB_USER" ]] || fail "Can't parse \$dbuser from $CFG"
[[ -n "$DB_PASS" ]] || fail "Can't parse \$dbpasswd from $CFG"

TS="$(date +%Y%m%d_%H%M%S)"
SCHEMA_FILE="${OUT_DIR}/${DB_NAME}_schema_${TS}.sql.gz"
FULL_FILE="${OUT_DIR}/${DB_NAME}_full_${TS}.sql.gz"

TMP_CNF="$(mktemp)"
trap 'rm -f "$TMP_CNF"' EXIT
chmod 600 "$TMP_CNF"

cat > "$TMP_CNF" <<EOF
[client]
user=${DB_USER}
password=${DB_PASS}
host=${DB_HOST}
port=${DB_PORT}
EOF

# 1) Только структура
mysqldump --defaults-extra-file="$TMP_CNF" \
  --no-data --routines --triggers --events \
  --default-character-set=utf8mb4 \
  "$DB_NAME" | gzip -9 > "$SCHEMA_FILE"

# 2) Структура + данные
mysqldump --defaults-extra-file="$TMP_CNF" \
  --single-transaction --quick --lock-tables=false \
  --routines --triggers --events \
  --default-character-set=utf8mb4 \
  "$DB_NAME" | gzip -9 > "$FULL_FILE"

##  --no-data \

echo "OK:"
echo "  $SCHEMA_FILE"
echo "  $FULL_FILE"