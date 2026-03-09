# SPCF Thesis - Sign-um Document Workflow System

A PHP + MySQL web application for document routing, approvals, notifications, event handling, and publication/material workflows.

## Tech Stack

- PHP (Composer-managed dependencies)
- MySQL/MariaDB (via XAMPP)
- Apache (mod_rewrite)
- JavaScript + Bootstrap frontend

## Requirements

### 1. Software

- XAMPP (Apache + MySQL + PHP)
- Composer (latest stable)
- Git (optional, for cloning)

### 2. PHP Version

- PHP 8.1 or newer is recommended.

### 3. Required PHP Extensions

Enable these in your XAMPP PHP configuration (`php.ini`):

- `pdo_mysql`
- `mbstring`

## Project Setup (Windows + XAMPP)

### 1. Place the project in `htdocs`

Expected path:

`C:\xampp\htdocs\SPCF-Thesis`

### 2. Install Composer dependencies

Open PowerShell in the project root and run:

```powershell
composer install
```

### 3. Create `.env`

Create a `.env` file in the project root (`C:\xampp\htdocs\SPCF-Thesis\.env`) using this template:

```env
DB_HOST=localhost
DB_USER=root
DB_PASS=
DB_NAME=spcf_thesis_db
BASE_URL=http://localhost/SPCF-Thesis/
SITE_NAME=Sign-um
ENVIRONMENT=development

# Optional (current implementation still references this for DOCX->PDF conversion)
CLOUDCONVERT_API_KEY=
```

Notes:

- `BASE_URL` must be set. The app stops if it is missing.
- Use `ENVIRONMENT=development` locally to see errors.

### 4. Create and import database

1. Start `Apache` and `MySQL` in XAMPP Control Panel.
2. Open `http://localhost/phpmyadmin`.
3. Create a database named `spcf_thesis_db` (or use your preferred name and match it in `.env`).
4. Import one SQL file:

- `schema.sql` for structure-only baseline, or
- `spcf_thesis_clean.sql` if you want a fuller prebuilt dataset.

If needed, apply additional migration scripts in this repo manually using phpMyAdmin SQL tab.

### 5. Apache rewrite settings

This project depends on URL rewriting via `.htaccess`.

Confirm Apache has:

- `mod_rewrite` enabled
- `AllowOverride All` for your `htdocs` directory

### 6. Local HTTPS redirect note

The included `.htaccess` currently forces HTTPS. If your localhost does not have SSL configured, comment out this block in `.htaccess` during local development:

```apache
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### 7. Run the app

Open:

- `http://localhost/SPCF-Thesis/`
- or `http://localhost/SPCF-Thesis/?page=login`

## Common Commands

```powershell
# Install dependencies
composer install

# Update dependencies (optional)
composer update
```

## Common Issues

### `BASE_URL is not defined in .env`

- Ensure `.env` exists in the project root.
- Ensure `BASE_URL` is present and not empty.

### Composer errors about missing PHP extensions

- Enable required extensions in `C:\xampp\php\php.ini`.
- Restart Apache after editing `php.ini`.

### App redirects to `https://localhost/...` and fails

- Comment out HTTPS force rules in `.htaccess` for local environment.

### Database connection failed

- Verify `DB_HOST`, `DB_USER`, `DB_PASS`, and `DB_NAME` in `.env`.
- Confirm MySQL is running in XAMPP.
- Confirm database is imported.

## Security Notes

- Never commit `.env` or real credentials/API keys.
- Rotate any credentials that were accidentally exposed.

## Deployment Notes

For production:

- Set `ENVIRONMENT=production`
- Use HTTPS-enabled host
- Keep `.env` server-side only
- Use strong DB credentials and restricted permissions
