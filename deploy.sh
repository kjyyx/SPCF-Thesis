#!/bin/bash

# deploy.sh - Automated deployment script for Hostinger
# This script pulls the latest changes from Git, updates dependencies, and handles database migrations

set -e  # Exit on any error

echo "Starting deployment at $(date)"

# Configuration
PROJECT_DIR="/home/u710690202/domains/spcf-signum.com/public_html/SPCF-Thesis"
BACKUP_DIR="/home/u710690202/backups"
LOG_FILE="$PROJECT_DIR/deploy.log"

# Function to log messages
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_FILE"
}

# Create backup before deployment
log "Creating backup..."
mkdir -p "$BACKUP_DIR"
BACKUP_FILE="$BACKUP_DIR/backup_$(date +%Y%m%d_%H%M%S).tar.gz"
tar -czf "$BACKUP_FILE" -C "$PROJECT_DIR" . 2>/dev/null || log "Warning: Backup creation failed"
log "Backup created: $BACKUP_FILE"

# Navigate to project directory
cd "$PROJECT_DIR" || { log "Failed to change to project directory"; exit 1; }

# Pull latest changes from Git
log "Pulling latest changes from Git..."
if git pull origin main 2>&1 | tee -a "$LOG_FILE"; then
    log "Git pull successful"
else
    log "Git pull failed, but continuing with deployment"
fi

# Install/update Composer dependencies
log "Installing Composer dependencies..."
if command -v composer >/dev/null 2>&1; then
    composer install --no-dev --optimize-autoloader 2>&1 | tee -a "$LOG_FILE"
    log "Composer dependencies installed"
else
    log "Composer not found, skipping dependency installation"
fi

# Set proper permissions
log "Setting permissions..."
find "$PROJECT_DIR" -type f -name "*.php" -exec chmod 644 {} \;
find "$PROJECT_DIR" -type d -exec chmod 755 {} \;
chmod 755 "$PROJECT_DIR/deploy.sh"
chmod -R 775 "$PROJECT_DIR/uploads" 2>/dev/null || log "Warning: Could not set uploads permissions"
chmod -R 775 "$PROJECT_DIR/assets" 2>/dev/null || log "Warning: Could not set assets permissions"

# Check for database migration files
if [ -f "$PROJECT_DIR/schema.sql" ]; then
    log "Database schema file found. Please run manually if needed:"
    log "mysql -u YOUR_DB_USER -p YOUR_DB_NAME < $PROJECT_DIR/schema.sql"
fi

if [ -f "$PROJECT_DIR/add_status.sql" ]; then
    log "Additional SQL file found. Please run manually if needed:"
    log "mysql -u YOUR_DB_USER -p YOUR_DB_NAME < $PROJECT_DIR/add_status.sql"
fi

# Clear any PHP opcache if available
if php -r "echo function_exists('opcache_reset') ? 'yes' : 'no';" | grep -q "yes"; then
    log "Clearing PHP opcache..."
    php -r "opcache_reset();" 2>/dev/null || log "Warning: Could not clear opcache"
fi

# Test the application
log "Testing application..."
if curl -s -o /dev/null -w "%{http_code}" "https://spcf-signum.com/SPCF-Thesis/" | grep -q "200\|301\|302"; then
    log "Application test passed"
else
    log "Warning: Application test failed - please check manually"
fi

log "Deployment completed successfully at $(date)"
echo "===========================================" >> "$LOG_FILE"