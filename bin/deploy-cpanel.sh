#!/usr/bin/env bash
# Deploy the committed plugin (git archive, without export-ignore files) to the path
# written in ~/.frave-deploy/<clone folder>.path, keeping the previous version for rollback.
#
#   bash bin/deploy-cpanel.sh              deploy HEAD
#   bash bin/deploy-cpanel.sh --rollback   swap back to the previous version
set -euo pipefail

# One clone per environment: the clone folder names its settings, e.g. a clone in
# ~/repositories/frave-theme-staging reads ~/.frave-deploy/frave-theme-staging.path.
NAME="$(basename "$(git rev-parse --show-toplevel)")"
REQUIRED_FILES=("frave-peru.php" "includes/checkout-fields.php" "includes/claims.php")
CONFIG_DIR="${FRAVE_DEPLOY_DIR:-$HOME/.frave-deploy}"
PATH_FILE="$CONFIG_DIR/$NAME.path"

if [ ! -f "$PATH_FILE" ]; then
  echo "Falta $PATH_FILE con la ruta del plugin, por ejemplo:" >&2
  echo "  /home/USUARIO/public_html/wp-content/plugins/frave-peru" >&2
  exit 1
fi
TARGET="$(head -n 1 "$PATH_FILE" | tr -d '[:space:]')"
case "$TARGET" in
  /*/wp-content/plugins/*) ;;
  *) echo "Ruta de destino no válida para un plugin: '$TARGET'" >&2; exit 1 ;;
esac

STAGE="$CONFIG_DIR/$NAME.new"
PREVIOUS="$CONFIG_DIR/$NAME.previous"

if [ "${1:-}" = "--rollback" ]; then
  [ -d "$PREVIOUS" ] || { echo "No hay una versión anterior guardada." >&2; exit 1; }
  rm -rf "$STAGE"
  mv "$TARGET" "$STAGE"
  mv "$PREVIOUS" "$TARGET"
  mv "$STAGE" "$PREVIOUS"
  echo "Restaurada la versión $(cat "$TARGET/REVISION" 2>/dev/null || echo '?') en $TARGET"
  exit 0
fi

rm -rf "$STAGE"
mkdir -p "$STAGE"
git archive --format=tar HEAD | tar -x -C "$STAGE"
git rev-parse --short HEAD > "$STAGE/REVISION"

for file in "${REQUIRED_FILES[@]}"; do
  [ -f "$STAGE/$file" ] || { echo "Falta $file en el commit; no se despliega." >&2; rm -rf "$STAGE"; exit 1; }
done

# Swap directories: the site sees either the old or the new version, never a mix.
rm -rf "$PREVIOUS"
mkdir -p "$(dirname "$TARGET")"
[ -d "$TARGET" ] && mv "$TARGET" "$PREVIOUS"
mv "$STAGE" "$TARGET"
echo "Desplegado $(cat "$TARGET/REVISION") en $TARGET"
