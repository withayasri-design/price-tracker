# Price Tracker

Multi-platform price tracking system for Thai e-commerce websites. Monitor product prices, receive alerts when prices drop, and compare prices across platforms.

## Features

- **Multi-Platform Support**: Track prices from 7 Thai retail websites
  - IT/Electronics: JIB, Banana IT, Advice, Power Buy
  - Home Improvement: Global House, HomePro, Thai Watsadu

- **Price Alerts**: Get notified when prices hit your target
  - LINE notifications with rich Flex Messages
  - Email notifications with HTML templates

- **Cross-Platform Comparison**: Compare prices for the same product across different stores

- **Price History**: View historical price charts and statistics

- **Agent Pipeline**: Automated processing system
  - ScraperAgent: Fetches prices from platforms
  - DataCleaningAgent: Matches products across platforms
  - PriceDiffAgent: Detects price drops and promotions
  - AlertDispatchAgent: Sends notifications

## Tech Stack

- **Backend**: PHP 8.2 (Native, no frameworks)
- **Database**: MariaDB/MySQL with PDO
- **Frontend**: Bootstrap 5.3, Chart.js, Vanilla JavaScript
- **Notifications**: LINE Messaging API, PHPMailer

## Requirements

- PHP 8.2+
- MariaDB 10.5+ or MySQL 8.0+
- Composer
- Apache with mod_rewrite (or nginx)

## Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/withayasri-design/price-tracker.git
   cd price-tracker
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Configure environment**
   ```bash
   cp .env.example .env
   # Edit .env with your database and API credentials
   ```

4. **Create database**
   ```bash
   mysql -u root -p < database/schema.sql
   ```

5. **Set up cron jobs**
   ```bash
   # Add to crontab
   * * * * * php /path/to/cron/run_agent_queue.php >> /var/log/agent_queue.log 2>&1
   */30 * * * * php /path/to/cron/run_scheduled_scrape.php >> /var/log/scrape.log 2>&1
   ```

6. **Configure web server**
   - Point document root to the project directory
   - Ensure `.htaccess` is enabled (Apache) or configure nginx equivalent

## Project Structure

```
price-tracker/
├── agents/                 # Agent pipeline (scraper, cleaning, diff, dispatch)
├── api/                    # JSON API endpoints
├── assets/                 # CSS, JS, images
├── config/                 # Database and LINE configuration
├── core/                   # Auth, CSRF, Queue utilities
├── cron/                   # Scheduled tasks
├── database/               # SQL schema
├── docs/                   # Specification documents
├── modules/
│   ├── matching/           # Cross-platform product matching
│   ├── notification/       # LINE and Email notifiers
│   ├── scraping/           # Platform adapters
│   └── tracking/           # Product tracking service
└── pages/                  # PHP pages (login, dashboard, etc.)
```

## Configuration

### Environment Variables (.env)

```env
# Database
DB_HOST=localhost
DB_NAME=price_tracker
DB_USER=root
DB_PASS=

# Email (SMTP)
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your@email.com
SMTP_PASS=your_app_password

# LINE Messaging API
LINE_CHANNEL_ACCESS_TOKEN=your_token
LINE_CHANNEL_SECRET=your_secret
```

### LINE Setup

1. Create a LINE Official Account at [LINE Developers](https://developers.line.biz/)
2. Enable Messaging API
3. Add Channel Access Token and Secret to admin settings
4. Set webhook URL to `https://yourdomain.com/api/notifications/line_webhook.php`

## Usage

### Adding Products

1. Log in to the dashboard
2. Go to "Products" page
3. Paste a product URL from any supported platform
4. Set target price or discount percentage
5. Save - the system will start tracking automatically

### Viewing Price History

1. Click on any tracked product
2. View the price chart (7, 30, or 90 days)
3. See min/max/average prices

### Comparing Prices

1. Go to "Compare" page
2. Products matched across platforms will appear
3. Click to see side-by-side comparison with best price highlighted

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/products/add.php` | POST | Add product tracking |
| `/api/products/list.php` | GET | List tracked products |
| `/api/products/refresh.php` | POST | Refresh product price |
| `/api/products/history.php` | GET | Get price history |
| `/api/agents/queue_status.php` | GET | Agent queue statistics |
| `/api/events/list.php` | GET | List price events |

## Supported Platforms

| Platform | URL Pattern | Status |
|----------|-------------|--------|
| JIB | `jib.co.th/web/product/readProduct/*` | Ready |
| Banana IT | `bananait.co.th/product/*` | Ready |
| Advice | `advice.co.th/product/*` | Ready |
| Power Buy | `powerbuy.co.th/*/product/*` | Ready |
| Global House | `globalhouse.co.th/product/*` | Ready |
| HomePro | `homepro.co.th/p/*` | Ready |
| Thai Watsadu | `thaiwatsadu.com/product/*` | Ready |

## Development

### Running locally

```bash
# Start PHP development server
php -S localhost:8000

# Or use XAMPP/MAMP
```

### Running tests

```bash
composer test
```

### Adding a new platform adapter

1. Create `modules/scraping/adapters/NewPlatformAdapter.php`
2. Implement `PlatformAdapterInterface`
3. Register in `ScrapingService::registerDefaultAdapters()`
4. Add URL pattern to `UrlParser.php`

## License

MIT License - see [LICENSE](LICENSE) for details.

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

## Acknowledgments

- Built with PHP and Bootstrap
- Uses Chart.js for price visualization
- LINE Messaging API for notifications
