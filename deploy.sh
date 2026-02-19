#!/bin/bash
# deploy.sh - Simple deployment for Hostinger

PROJECT_DIR="/home/u710690202/domains/spcf-signum.com/public_html/SPCF-Thesis"
LOG_FILE="$PROJECT_DIR/deploy.log"

echo "Starting deployment at $(date)" >> "$LOG_FILE"

cd "$PROJECT_DIR" || { echo "Failed to cd to $PROJECT_DIR"; exit 1; }

# Pull latest from Git
git pull origin main >> "$LOG_FILE" 2>&1

# Optional: install/update composer dependencies
if command -v composer >/dev/null 2>&1; then
    composer install --no-dev --optimize-autoloader >> "$LOG_FILE" 2>&1
fi

# Set basic permissions
chmod -R 755 "$PROJECT_DIR"
chmod -R 775 "$PROJECT_DIR/uploads" 2>/dev/null

echo "Deployment finished at $(date)" >> "$LOG_FILE"
