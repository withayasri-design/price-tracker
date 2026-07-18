# Contributing to Price Tracker

Thank you for your interest in contributing to Price Tracker! This document provides guidelines and instructions for contributing.

## Code of Conduct

- Be respectful and inclusive
- Focus on constructive feedback
- Help others learn and grow

## Getting Started

### Prerequisites

- PHP 8.2+
- MariaDB 10.5+ or MySQL 8.0+
- Composer
- Git

### Local Development Setup

1. **Fork and clone the repository**
   ```bash
   git clone https://github.com/YOUR_USERNAME/price-tracker.git
   cd price-tracker
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Configure environment**
   ```bash
   cp .env.example .env
   # Edit .env with your local database credentials
   ```

4. **Set up database**
   ```bash
   mysql -u root -p < database/schema.sql
   ```

5. **Start development server**
   ```bash
   php -S localhost:8000
   # Or use: make dev
   ```

### Using Docker

```bash
# Start all services
docker-compose up -d

# With phpMyAdmin
docker-compose --profile dev up -d

# View logs
docker-compose logs -f
```

## Development Guidelines

### Code Style

- Follow PSR-12 coding standards
- Use meaningful variable and function names
- Add PHPDoc comments for classes and public methods
- Keep functions focused and small (< 50 lines ideally)

### File Organization

```
price-tracker/
├── agents/          # Agent pipeline classes
├── api/             # JSON API endpoints
├── assets/          # CSS, JS, images
├── config/          # Configuration files
├── core/            # Core utilities (Auth, CSRF, Queue)
├── cron/            # Scheduled tasks
├── database/        # SQL schema and migrations
├── modules/         # Business logic modules
│   ├── matching/    # Product matching
│   ├── notification/# Email and LINE notifications
│   ├── scraping/    # Platform adapters
│   └── tracking/    # Product tracking
├── pages/           # PHP pages (views)
└── tests/           # PHPUnit tests
```

### Naming Conventions

| Type | Convention | Example |
|------|------------|---------|
| Classes | PascalCase | `ScraperAgent` |
| Methods | camelCase | `processJob()` |
| Variables | camelCase | `$productId` |
| Constants | UPPER_SNAKE | `MAX_RETRY_COUNT` |
| Database tables | snake_case | `tracked_products` |
| Database columns | snake_case | `created_at` |
| Files | PascalCase for classes | `ScraperAgent.php` |

### Database

- Always use prepared statements with PDO
- Never interpolate user input into queries
- Use transactions for multi-step operations
- Add indexes for frequently queried columns

```php
// Good
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);

// Bad - SQL injection risk!
$pdo->query("SELECT * FROM users WHERE email = '$email'");
```

### Security

- Validate and sanitize all user input
- Use CSRF tokens for all forms
- Hash passwords with `password_hash()`
- Escape output with `htmlspecialchars()`
- Never commit secrets or credentials

## Making Changes

### Branching Strategy

- `master` - Production-ready code
- `develop` - Integration branch for features
- `feature/*` - New features
- `fix/*` - Bug fixes
- `hotfix/*` - Urgent production fixes

### Commit Messages

Follow conventional commits format:

```
type(scope): short description

[optional body]

[optional footer]
```

Types:
- `feat` - New feature
- `fix` - Bug fix
- `docs` - Documentation
- `style` - Formatting (no code change)
- `refactor` - Code restructuring
- `test` - Adding tests
- `chore` - Maintenance tasks

Examples:
```
feat(scraping): add PowerBuy adapter

fix(auth): prevent session fixation attack

docs(readme): update installation instructions
```

### Pull Request Process

1. **Create a feature branch**
   ```bash
   git checkout -b feature/my-feature
   ```

2. **Make your changes**
   - Write clean, documented code
   - Add tests for new functionality
   - Update documentation if needed

3. **Run tests locally**
   ```bash
   composer test
   # Or: make test
   ```

4. **Commit your changes**
   ```bash
   git add .
   git commit -m "feat(scope): description"
   ```

5. **Push and create PR**
   ```bash
   git push origin feature/my-feature
   ```
   Then create a Pull Request on GitHub.

6. **PR Requirements**
   - Clear description of changes
   - All tests passing
   - No merge conflicts
   - Code review approval

## Adding a New Platform Adapter

1. Create adapter file in `modules/scraping/adapters/`:
   ```php
   <?php
   // NewPlatformAdapter.php

   namespace Modules\Scraping\Adapters;

   class NewPlatformAdapter extends BaseAdapter implements PlatformAdapterInterface
   {
       protected string $platformName = 'newplatform';
       protected string $baseUrl = 'https://www.newplatform.com';

       public function scrape(string $productId): ?array
       {
           // Implementation
       }

       public function parseProductId(string $url): ?string
       {
           // Implementation
       }
   }
   ```

2. Register in `ScrapingService.php`:
   ```php
   $this->registerAdapter(new NewPlatformAdapter());
   ```

3. Add URL pattern to `UrlParser.php`

4. Add rate limit setting to `system_settings`

5. Write tests in `tests/Unit/`

## Testing

### Running Tests

```bash
# All tests
composer test

# Unit tests only
composer test:unit

# Integration tests
composer test:integration

# With coverage
composer test:coverage
```

### Writing Tests

- Place unit tests in `tests/Unit/`
- Place integration tests in `tests/Integration/`
- Name test files as `*Test.php`
- Name test methods as `testSomething()`

```php
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MyFeatureTest extends TestCase
{
    public function testItDoesTheThing(): void
    {
        // Arrange
        $input = 'test';

        // Act
        $result = myFunction($input);

        // Assert
        $this->assertEquals('expected', $result);
    }
}
```

## Reporting Issues

### Bug Reports

Include:
- PHP version
- Database version
- Steps to reproduce
- Expected vs actual behavior
- Error messages/logs

### Feature Requests

Include:
- Use case description
- Proposed solution
- Alternative solutions considered

## Questions?

- Open a GitHub issue for questions
- Check existing issues first
- Be patient - maintainers are volunteers

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
