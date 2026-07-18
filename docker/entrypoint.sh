#!/bin/bash
set -e

echo "=== Price Tracker Container Starting ==="

# Create required directories
mkdir -p /var/www/html/logs
mkdir -p /var/www/html/cache
mkdir -p /var/www/html/uploads
mkdir -p /var/www/html/temp

# Set permissions
chown -R www-data:www-data /var/www/html/logs
chown -R www-data:www-data /var/www/html/cache
chown -R www-data:www-data /var/www/html/uploads
chown -R www-data:www-data /var/www/html/temp

# Wait for database to be ready
if [ -n "$DB_HOST" ]; then
    echo "Waiting for database at $DB_HOST..."
    max_tries=30
    counter=0

    until php -r "
        \$host = getenv('DB_HOST');
        \$port = getenv('DB_PORT') ?: 3306;
        \$user = getenv('DB_USER');
        \$pass = getenv('DB_PASS');

        try {
            new PDO(\"mysql:host=\$host;port=\$port\", \$user, \$pass);
            exit(0);
        } catch (Exception \$e) {
            exit(1);
        }
    " 2>/dev/null; do
        counter=$((counter + 1))
        if [ $counter -ge $max_tries ]; then
            echo "Error: Could not connect to database after $max_tries attempts"
            exit 1
        fi
        echo "Database not ready, waiting... ($counter/$max_tries)"
        sleep 2
    done

    echo "Database connection successful!"
fi

# Run database migrations if schema file exists and DB is configured
if [ -f /var/www/html/database/schema.sql ] && [ -n "$DB_HOST" ]; then
    echo "Checking database schema..."

    php -r "
        require_once '/var/www/html/vendor/autoload.php';

        \$host = getenv('DB_HOST');
        \$name = getenv('DB_NAME');
        \$user = getenv('DB_USER');
        \$pass = getenv('DB_PASS');

        try {
            \$pdo = new PDO(\"mysql:host=\$host\", \$user, \$pass);
            \$pdo->exec(\"CREATE DATABASE IF NOT EXISTS \$name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\");
            \$pdo->exec(\"USE \$name\");

            // Check if tables exist
            \$result = \$pdo->query(\"SHOW TABLES LIKE 'users'\");
            if (\$result->rowCount() === 0) {
                echo \"Running initial schema...\\n\";
                \$schema = file_get_contents('/var/www/html/database/schema.sql');
                \$schema = preg_replace('/--.*$/m', '', \$schema);
                \$statements = array_filter(array_map('trim', explode(';', \$schema)));

                foreach (\$statements as \$stmt) {
                    if (!empty(\$stmt)) {
                        \$pdo->exec(\$stmt);
                    }
                }
                echo \"Schema created successfully!\\n\";
            } else {
                echo \"Database already initialized.\\n\";
            }
        } catch (Exception \$e) {
            echo \"Database error: \" . \$e->getMessage() . \"\\n\";
        }
    "
fi

# Start cron daemon
echo "Starting cron daemon..."
service cron start

echo "=== Container Ready ==="

# Execute the main command
exec "$@"
