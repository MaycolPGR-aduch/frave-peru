#!/usr/bin/env bash
# Deploy the committed plugin (git archive, without export-ignore files) to the path
# written in ~/.frave-deploy/<clone folder>.path, keeping the previous version for rollback.
#
#   bash bin/deploy.sh              deploy HEAD
#   bash bin/deploy.sh --rollback   swap back to the previous version
set -euo pipefail

# One clone per environment: the clone folder names its settings, e.g. a clone in
# ~/repos/frave-theme reads ~/.frave-deploy/frave-theme.path.
NAME="$(basename "$(git rev-parse --show-toplevel)")"
REQUIRED_FILES=("frave-peru.php" "includes/checkout-fields.php" "includes/claims.php")
CONFIG_DIR="${FRAVE_DEPLOY_DIR:-$HOME/.frave-deploy}"
PATH_FILE="$CONFIG_DIR/$NAME.path"

if [ ! -f "$PATH_FILE" ]; then
  echo "Falta $PATH_FILE con la ruta del plugin, por ejemplo:" >&2
  echo "  /home/USUARIO/domains/DOMINIO/public_html/wp-content/plugins/frave-peru" >&2
  exit 1
fi
TARGET="$(head -n 1 "$PATH_FILE" | tr -d '[:space:]')"
case "$TARGET" in
  /*/wp-content/plugins/*) ;;
  *) echo "Ruta de destino no válida para un plugin: '$TARGET'" >&2; exit 1 ;;
esac

STAGE="$CONFIG_DIR/$NAME.new"
PREVIOUS="$CONFIG_DIR/$NAME.previous"

# Cached pages still reference the previous version (the theme's assets have hashed
# names and the old files are gone), so the page cache is purged after every swap.
# Hostinger's WordPress comes with LiteSpeed Cache and WP-CLI; elsewhere, purge by hand.
purge_cache() {
  local wp_root="${TARGET%%/wp-content/*}"
  if command -v wp >/dev/null 2>&1 && [ -f "$wp_root/wp-config.php" ]; then
    wp --path="$wp_root" cache flush >/dev/null 2>&1 || true
    if wp --path="$wp_root" plugin is-active litespeed-cache >/dev/null 2>&1 &&
      wp --path="$wp_root" litespeed-purge all >/dev/null 2>&1; then
      echo "Caché de LiteSpeed vaciada."
      return
    fi
  fi
  echo "Si el sitio usa caché de página, vacíala ahora desde el administrador."
}

if [ "${1:-}" = "--rollback" ]; then
  [ -d "$PREVIOUS" ] || { echo "No hay una versión anterior guardada." >&2; exit 1; }
  rm -rf "$STAGE"
  mv "$TARGET" "$STAGE"
  mv "$PREVIOUS" "$TARGET"
  mv "$STAGE" "$PREVIOUS"
  echo "Restaurada la versión $(cat "$TARGET/REVISION" 2>/dev/null || echo '?') en $TARGET"
  purge_cache
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
purge_cache
