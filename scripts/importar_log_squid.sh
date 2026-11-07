#!/bin/bash
# Reconstrói o agregado diário usando a tabela canônica preenchida pelo importador.
# É idempotente: nenhuma linha de access.log é somada duas vezes.
set -euo pipefail
GWOS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="/usr/bin/php"
[ -x "$PHP_BIN" ] || PHP_BIN="$(command -v php || true)"
[ -n "$PHP_BIN" ] || { echo "PHP CLI não encontrado." >&2; exit 1; }
exec "$PHP_BIN" "$GWOS_DIR/scripts/parsear_logs.php"
