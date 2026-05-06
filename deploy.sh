#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Script de post-despliegue para Talent Filter.
#
# Se ejecuta desde la raíz del proyecto (DEPLOY_ROOT) tras la extracción del
# tarball en el método SSH fallback del workflow .github/workflows/deploy.yml.
#
# Tareas:
#   1. Crear estructura de uploads/ (originals, pages, unified) si no existe.
#   2. Ajustar ownership y permisos.
#   3. Instalar dependencias de composer (--no-dev, optimizado).
#   4. (Opcional) Recargar PHP-FPM para limpiar opcache si está disponible.
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

DEPLOY_ROOT="${DEPLOY_ROOT:-$(pwd)}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"
PHP_BIN="${PHP_BIN:-php}"

log() { printf '[deploy.sh] %s\n' "$*"; }

cd "$DEPLOY_ROOT"
log "DEPLOY_ROOT=$DEPLOY_ROOT"

# ─────────────────────────────────────────────────────────────────────────────
# 1) Estructura de uploads/
# ─────────────────────────────────────────────────────────────────────────────
log "Asegurando estructura de uploads/"
mkdir -p uploads/originals uploads/pages uploads/unified

# ─────────────────────────────────────────────────────────────────────────────
# 2) Ownership y permisos
# ─────────────────────────────────────────────────────────────────────────────
if id "$WEB_USER" >/dev/null 2>&1; then
  log "Ajustando ownership a ${WEB_USER}:${WEB_GROUP}"
  chown -R "${WEB_USER}:${WEB_GROUP}" "$DEPLOY_ROOT" 2>/dev/null || \
    log "AVISO: chown falló (¿usuario sin permisos?). Continuando."
else
  log "AVISO: usuario ${WEB_USER} no existe en el sistema, saltando chown."
fi

log "Permisos: 755 dirs / 644 files"
find "$DEPLOY_ROOT" -type d -not -path '*/\.git*' -exec chmod 755 {} \; 2>/dev/null || true
find "$DEPLOY_ROOT" -type f -not -path '*/\.git*' -exec chmod 644 {} \; 2>/dev/null || true

log "Permisos especiales para uploads/ (775)"
chmod -R 775 uploads/ 2>/dev/null || true

# ─────────────────────────────────────────────────────────────────────────────
# 3) Composer install
# ─────────────────────────────────────────────────────────────────────────────
COMPOSER_BIN=""
for candidate in /usr/local/bin/composer /usr/bin/composer composer; do
  if command -v "$candidate" >/dev/null 2>&1; then
    COMPOSER_BIN="$candidate"
    break
  fi
done

if [ -z "$COMPOSER_BIN" ] && [ -f composer.phar ]; then
  COMPOSER_BIN="$PHP_BIN composer.phar"
fi

if [ -z "$COMPOSER_BIN" ]; then
  log "AVISO: composer no encontrado, saltando instalación de dependencias."
else
  log "Instalando dependencias con: $COMPOSER_BIN"
  if [ -n "${WEB_USER:-}" ] && id "$WEB_USER" >/dev/null 2>&1 && [ "$(id -u)" = "0" ]; then
    sudo -u "$WEB_USER" -H bash -lc "cd $(printf '%q' "$DEPLOY_ROOT") && $COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction --prefer-dist"
  else
    $COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction --prefer-dist
  fi
  log "Dependencias instaladas."
fi

# ─────────────────────────────────────────────────────────────────────────────
# 4) Recargar PHP-FPM (limpia opcache) si el servicio existe
# ─────────────────────────────────────────────────────────────────────────────
if command -v systemctl >/dev/null 2>&1; then
  PHP_FPM_SERVICE="$(systemctl list-units --type=service --no-legend 2>/dev/null \
    | awk '{print $1}' | grep -E '^php[0-9.]*-fpm\.service$' | head -n1 || true)"

  if [ -n "$PHP_FPM_SERVICE" ]; then
    log "Recargando $PHP_FPM_SERVICE"
    systemctl reload "$PHP_FPM_SERVICE" 2>/dev/null || \
      log "AVISO: no se pudo recargar $PHP_FPM_SERVICE (¿permisos?)."
  else
    log "PHP-FPM no detectado vía systemctl, saltando reload."
  fi
fi

log "✅ deploy.sh completado correctamente."
