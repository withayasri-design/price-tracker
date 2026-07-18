# Price Tracker Makefile
# Common commands for development and deployment

.PHONY: help install dev test lint clean docker-up docker-down docker-logs db-migrate db-seed

# Default target
help:
	@echo "Price Tracker - Available Commands"
	@echo ""
	@echo "Development:"
	@echo "  make install     - Install PHP dependencies"
	@echo "  make dev         - Start PHP development server"
	@echo "  make test        - Run all tests"
	@echo "  make test-unit   - Run unit tests only"
	@echo "  make test-cov    - Run tests with coverage report"
	@echo "  make lint        - Check PHP syntax"
	@echo "  make clean       - Remove generated files"
	@echo ""
	@echo "Docker:"
	@echo "  make docker-up   - Start Docker containers"
	@echo "  make docker-down - Stop Docker containers"
	@echo "  make docker-logs - View container logs"
	@echo "  make docker-build - Rebuild Docker image"
	@echo "  make docker-shell - Open shell in app container"
	@echo ""
	@echo "Database:"
	@echo "  make db-migrate  - Run database schema"
	@echo "  make db-seed     - Insert sample data"
	@echo "  make db-reset    - Drop and recreate database"
	@echo ""
	@echo "Agents:"
	@echo "  make queue       - Process agent queue"
	@echo "  make scrape      - Run scraper agent"
	@echo "  make cleanup     - Run cleanup job"
	@echo "  make backup      - Backup database"
	@echo ""
	@echo "Documentation:"
	@echo "  make docs        - Open API documentation"

# ===================
# Development
# ===================

install:
	composer install

dev:
	@echo "Starting development server at http://localhost:8000"
	php -S localhost:8000

test:
	composer test

test-unit:
	composer test:unit

test-integration:
	composer test:integration

test-cov:
	composer test:coverage
	@echo "Coverage report generated in coverage/"

lint:
	@echo "Checking PHP syntax..."
	@find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l 2>/dev/null | grep -v "No syntax errors"
	@echo "Syntax check complete!"

clean:
	rm -rf vendor/
	rm -rf coverage/
	rm -rf .phpunit.cache/
	rm -f .phpunit.result.cache
	rm -rf logs/*.log
	rm -rf cache/*
	rm -rf temp/*

# ===================
# Docker
# ===================

docker-up:
	docker-compose up -d
	@echo ""
	@echo "Services started:"
	@echo "  App: http://localhost:8080"
	@echo "  DB:  localhost:3307"

docker-up-dev:
	docker-compose --profile dev up -d
	@echo ""
	@echo "Services started:"
	@echo "  App:        http://localhost:8080"
	@echo "  phpMyAdmin: http://localhost:8081"
	@echo "  DB:         localhost:3307"

docker-down:
	docker-compose down

docker-logs:
	docker-compose logs -f

docker-build:
	docker-compose build --no-cache

docker-shell:
	docker-compose exec app bash

docker-restart:
	docker-compose restart app

# ===================
# Database
# ===================

db-migrate:
	@echo "Running database schema..."
	mysql -u root -p price_tracker < database/schema.sql
	@echo "Schema applied!"

db-seed:
	@echo "Inserting sample data..."
	mysql -u root -p price_tracker < database/seed.sql
	@echo "Seed data inserted!"

db-reset:
	@echo "WARNING: This will drop and recreate the database!"
	@read -p "Are you sure? [y/N] " confirm && [ "$$confirm" = "y" ]
	mysql -u root -p -e "DROP DATABASE IF EXISTS price_tracker; CREATE DATABASE price_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
	mysql -u root -p price_tracker < database/schema.sql
	@echo "Database reset complete!"

# Docker database commands
docker-db-migrate:
	docker-compose exec db mysql -u price_tracker -psecret price_tracker < database/schema.sql

docker-db-seed:
	docker-compose exec db mysql -u price_tracker -psecret price_tracker < database/seed.sql

# ===================
# Agents
# ===================

queue:
	php cron/run_agent_queue.php

scrape:
	php cron/run_scheduled_scrape.php

cleanup:
	php cron/cleanup.php

backup:
	php cron/backup.php

# Docker agent commands
docker-queue:
	docker-compose exec app php cron/run_agent_queue.php

docker-scrape:
	docker-compose exec app php cron/run_scheduled_scrape.php

# ===================
# Utilities
# ===================

health:
	@curl -s http://localhost:8080/api/health.php | php -r 'echo json_encode(json_decode(file_get_contents("php://stdin")), JSON_PRETTY_PRINT);'

status:
	@echo "=== Price Tracker Status ==="
	@echo ""
	@echo "PHP Version:"
	@php -v | head -1
	@echo ""
	@echo "Composer Packages:"
	@composer show --installed 2>/dev/null | wc -l | xargs echo "  Installed:"
	@echo ""
	@echo "Docker Containers:"
	@docker-compose ps 2>/dev/null || echo "  Not running"

# Create necessary directories
dirs:
	mkdir -p logs cache uploads temp database/backups
	chmod 755 logs cache uploads temp database/backups

# API Documentation
docs:
	@echo "Opening API documentation..."
	@python -m webbrowser "http://localhost:8000/api/docs.php" 2>/dev/null || \
	 xdg-open "http://localhost:8000/api/docs.php" 2>/dev/null || \
	 open "http://localhost:8000/api/docs.php" 2>/dev/null || \
	 echo "Visit: http://localhost:8000/api/docs.php"
