# Changelog

All notable changes to Price Tracker will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2024-01-15

### Added

#### Core Features
- Multi-platform price tracking for 7 Thai retail websites
  - IT/Electronics: JIB, Banana IT, Advice, Power Buy
  - Home Improvement: Global House, HomePro, Thai Watsadu
- User authentication with session management
- Role-based access control (admin/user)
- CSRF protection on all forms

#### Agent Pipeline
- ScraperAgent: Automated price fetching from platforms
- DataCleaningAgent: Product matching and normalization
- PriceDiffAgent: Price change detection
- AlertDispatchAgent: Notification delivery

#### Notifications
- Email notifications via SMTP
- LINE notifications via Messaging API
- Configurable notification preferences per user
- Rich Flex Messages for LINE alerts

#### User Interface
- Responsive dashboard with price statistics
- Product management (add, edit, delete, refresh)
- Cross-platform price comparison view
- Price history charts (7/30/90 days)
- User profile and settings

#### Admin Features
- System settings configuration
- Agent queue monitoring
- Master product management
- System log viewer
- Notification testing

#### API
- RESTful API endpoints
- OpenAPI 3.0 documentation
- Health check endpoint
- Data export (CSV/JSON)
- Rate limiting

#### DevOps
- Docker support with docker-compose
- GitHub Actions CI/CD pipeline
- PHPUnit test suite
- Database migration system
- CLI management tool
- Automated backup script

#### Documentation
- README with installation guide
- Contributing guidelines
- Security checklist
- API documentation (Swagger UI)

### Security
- Password hashing with bcrypt
- Prepared statements for all SQL queries
- XSS prevention with output escaping
- Session security hardening
- Rate limiting for API endpoints

---

## [Unreleased]

### Planned
- Shopee/Lazada integration (requires headless browser)
- Affiliate link injection
- Daily/weekly digest emails
- Price prediction with ML
- Browser extension
- Mobile app (React Native)

---

## Version History

| Version | Date | Description |
|---------|------|-------------|
| 1.0.0 | 2024-01-15 | Initial release |

---

## Upgrade Guide

### From Pre-release to 1.0.0

1. Backup your database:
   ```bash
   php cron/backup.php
   ```

2. Pull latest changes:
   ```bash
   git pull origin master
   ```

3. Install dependencies:
   ```bash
   composer install
   ```

4. Run migrations:
   ```bash
   php database/migrate.php
   ```

5. Clear cache:
   ```bash
   php bin/cli.php cache:clear
   ```

6. Verify configuration:
   ```bash
   php bin/cli.php config:check
   ```
