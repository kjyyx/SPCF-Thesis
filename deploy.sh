#!/usr/bin/env bash
# deploy.sh - Improved deployment script for Hostinger (manual + can be called if needed)

set -euo pipefail  # Exit on error, undefined vars, pipe failures

PROJECT_DIR="/home/u710690202/domains/spcf-signum.com/public_html/SPCF-Thesis"
LOG_FILE="$PROJECT_DIR/deploy.log"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"
}

log "Starting deployment"

cd "$PROJECT_DIR" || { log "ERROR: Cannot cd to $PROJECT_DIR"; exit 1; }

# Pull latest changes
log "Running git pull..."
if ! git pull origin main >> "$LOG_FILE" 2>&1; then
    log "ERROR: git pull failed"
    exit 1
fi

# Composer (only if needed)
if [ -f "composer.json" ] && command -v composer >/dev/null 2>&1; then
    log "Running composer install..."
    composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist >> "$LOG_FILE" 2>&1 || {
        log "WARNING: composer install had issues (check log)"
    }
fi

# Permissions (uploads often needs write access)
log "Setting permissions..."
chmod -R 755 "$PROJECT_DIR"
find "$PROJECT_DIR/uploads" -type d -exec chmod 775 {} + 2>/dev/null || true
find "$PROJECT_DIR/uploads" -type f -exec chmod 664 {} + 2>/dev/null || true

# Optional: touch .htaccess to help invalidate opcode cache (shared hosting trick)
touch .htaccess 2>/dev/null || true

log "Deployment finished successfully"