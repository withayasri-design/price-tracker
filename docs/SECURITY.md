# Security Checklist

Production deployment security checklist for Price Tracker.

## Pre-Deployment

### Configuration

- [ ] **Remove install.php** - Delete after installation
- [ ] **Set APP_DEBUG=false** - Disable debug mode in production
- [ ] **Use strong database password** - Not empty or default
- [ ] **Configure HTTPS** - SSL certificate installed
- [ ] **Set secure session settings** - httponly, secure cookies

### File Permissions

```bash
# Recommended permissions
chmod 755 /var/www/price-tracker
chmod 644 /var/www/price-tracker/*.php
chmod 600 /var/www/price-tracker/.env
chmod 755 /var/www/price-tracker/logs
chmod 755 /var/www/price-tracker/cache
chmod -R 644 /var/www/price-tracker/config/*
```

### Directory Protection

Ensure these directories are not web-accessible:
- [ ] `/config/` - Contains database credentials
- [ ] `/core/` - Core PHP classes
- [ ] `/agents/` - Agent classes
- [ ] `/modules/` - Business logic
- [ ] `/database/` - SQL files and backups
- [ ] `/docs/` - Documentation
- [ ] `/tests/` - Test files
- [ ] `/vendor/` - Composer dependencies
- [ ] `/bin/` - CLI tools
- [ ] `/.env` - Environment variables

Verified in `.htaccess`:
```apache
<DirectoryMatch "^.*(config|core|agents|modules|database|docs|tests|vendor|bin).*$">
    Require all denied
</DirectoryMatch>
```

## Authentication & Authorization

### Password Security

- [x] **bcrypt hashing** - Using `password_hash()` with PASSWORD_DEFAULT
- [x] **Minimum 8 characters** - Enforced in validation
- [ ] **Password complexity** - Consider adding requirements
- [ ] **Account lockout** - Rate limit login attempts (use RateLimiter)

### Session Security

- [x] **Session regeneration** - On login
- [x] **Session timeout** - Configurable lifetime
- [ ] **Secure cookies** - Set in php.ini or .htaccess:
  ```ini
  session.cookie_httponly = 1
  session.cookie_secure = 1
  session.use_strict_mode = 1
  ```

### CSRF Protection

- [x] **CSRF tokens** - Generated per session
- [x] **Token validation** - On all POST requests
- [ ] **SameSite cookies** - Add `SameSite=Strict`

## Input Validation

### SQL Injection Prevention

- [x] **Prepared statements** - All queries use PDO prepared statements
- [x] **No string interpolation** - No direct variable insertion in SQL
- [ ] **Input validation** - Validate all user input types

### XSS Prevention

- [x] **Output escaping** - Using `htmlspecialchars()` on output
- [x] **Content-Type headers** - JSON APIs set proper headers
- [ ] **Content Security Policy** - Add CSP headers:
  ```apache
  Header set Content-Security-Policy "default-src 'self'; script-src 'self' cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' cdn.jsdelivr.net;"
  ```

### File Upload Security

- [ ] **Validate file types** - Check MIME types, not just extensions
- [ ] **Rename uploaded files** - Don't use original filenames
- [ ] **Store outside webroot** - Or block execution
- [ ] **Size limits** - Configure `upload_max_filesize`

## API Security

### Rate Limiting

- [x] **RateLimiter class** - Available in `core/RateLimiter.php`
- [ ] **Apply to endpoints** - Add to all public APIs
- [ ] **Configure limits** - Adjust per endpoint:
  ```php
  $limiter->setLimits('api', 60, 60);      // 60 req/min
  $limiter->setLimits('login', 5, 300);    // 5 attempts/5min
  $limiter->setLimits('scrape', 10, 60);   // 10 scrapes/min
  ```

### Authentication

- [x] **Session-based auth** - For web UI
- [ ] **API keys** - Consider for programmatic access
- [ ] **JWT tokens** - For stateless API auth

## Server Configuration

### Apache

```apache
# Hide server version
ServerTokens Prod
ServerSignature Off

# Security headers
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-XSS-Protection "1; mode=block"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"

# HSTS (after confirming HTTPS works)
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

### PHP

```ini
; Security settings for php.ini
expose_php = Off
display_errors = Off
log_errors = On
error_log = /var/log/php/error.log

; Session security
session.cookie_httponly = 1
session.cookie_secure = 1
session.use_strict_mode = 1
session.cookie_samesite = Strict

; Disable dangerous functions
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,curl_exec,curl_multi_exec,parse_ini_file,show_source

; File uploads
file_uploads = On
upload_max_filesize = 10M
max_file_uploads = 5
```

### MySQL/MariaDB

- [ ] **Dedicated database user** - Not root
- [ ] **Minimal privileges** - Only SELECT, INSERT, UPDATE, DELETE
- [ ] **Network restrictions** - Bind to localhost only
- [ ] **Strong root password** - Changed from default

```sql
-- Create dedicated user
CREATE USER 'price_tracker'@'localhost' IDENTIFIED BY 'strong_password_here';
GRANT SELECT, INSERT, UPDATE, DELETE ON price_tracker.* TO 'price_tracker'@'localhost';
FLUSH PRIVILEGES;
```

## External Services

### LINE Messaging API

- [ ] **Webhook signature verification** - Validate X-Line-Signature header
- [ ] **Store credentials securely** - In .env, not in code
- [ ] **HTTPS webhook URL** - Required by LINE

### Email (SMTP)

- [ ] **Use app passwords** - Not main account password
- [ ] **TLS encryption** - Port 587 with STARTTLS
- [ ] **Validate recipients** - Prevent email injection

## Logging & Monitoring

### Logging

- [x] **Agent logs** - Stored in database
- [x] **Error logs** - PHP errors logged
- [ ] **Access logs** - Apache/nginx access logs
- [ ] **Security events** - Log failed logins, suspicious activity

### Monitoring

- [x] **Health endpoint** - `/api/health.php`
- [ ] **Uptime monitoring** - External service (UptimeRobot, etc.)
- [ ] **Error alerting** - Notify on repeated errors
- [ ] **Queue monitoring** - Alert on stuck jobs

## Backup & Recovery

### Database Backups

- [x] **Backup script** - `cron/backup.php`
- [ ] **Scheduled backups** - Daily cron job
- [ ] **Off-site storage** - Copy to external location
- [ ] **Test restores** - Verify backups work

### Disaster Recovery

- [ ] **Document recovery steps** - Written procedures
- [ ] **Test recovery process** - Practice restore
- [ ] **Configuration backup** - .env and settings

## Dependency Security

### Composer

```bash
# Check for known vulnerabilities
composer audit

# Update dependencies
composer update

# Show outdated packages
composer outdated
```

- [ ] **Regular updates** - Weekly/monthly
- [ ] **Security advisories** - Subscribe to PHP/library alerts
- [ ] **Lock file** - Commit composer.lock for reproducibility

## Scraping Security

### Rate Limiting

- [x] **Per-platform limits** - Configurable in system_settings
- [x] **Batch delays** - Avoid overwhelming targets
- [ ] **Proxy rotation** - Consider for high volume

### User Agent

- [ ] **Identify as bot** - Be transparent
- [ ] **Respect robots.txt** - Check restrictions
- [ ] **Handle blocks gracefully** - Detect and alert

## Checklist Summary

### Critical (Must Fix Before Production)

1. [ ] Delete install.php
2. [ ] Set APP_DEBUG=false
3. [ ] Configure HTTPS
4. [ ] Set strong database password
5. [ ] Protect .env file (chmod 600)
6. [ ] Verify directory protection

### Important (Should Fix Soon)

1. [ ] Enable rate limiting on APIs
2. [ ] Configure security headers
3. [ ] Set up automated backups
4. [ ] Enable error logging
5. [ ] Create dedicated database user

### Recommended (Best Practices)

1. [ ] Set up monitoring
2. [ ] Configure CSP headers
3. [ ] Regular dependency updates
4. [ ] Security audit logging
5. [ ] Off-site backup storage

## Security Contact

If you discover a security vulnerability, please report it responsibly:
1. Do NOT create a public GitHub issue
2. Email details to: security@example.com
3. Allow 90 days for fix before disclosure

---

*Last updated: 2024*
