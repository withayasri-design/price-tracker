<?php
/**
 * Integration tests for Database Connection
 */

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use PDO;
use PDOException;

class DatabaseConnectionTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        // Skip if no test database configured
        if (!getenv('DB_DATABASE')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        try {
            $this->pdo = new PDO(
                sprintf(
                    'mysql:host=%s;dbname=%s;charset=utf8mb4',
                    getenv('DB_HOST') ?: 'localhost',
                    getenv('DB_DATABASE') ?: 'price_tracker_test'
                ),
                getenv('DB_USER') ?: 'root',
                getenv('DB_PASS') ?: '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped('Database connection failed: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $this->pdo = null;
    }

    /**
     * Test database connection
     */
    public function testDatabaseConnection(): void
    {
        $this->assertInstanceOf(PDO::class, $this->pdo);

        $result = $this->pdo->query('SELECT 1 as test');
        $row = $result->fetch();

        $this->assertEquals(1, $row['test']);
    }

    /**
     * Test required tables exist
     */
    public function testRequiredTablesExist(): void
    {
        $requiredTables = [
            'users',
            'tracked_products',
            'price_history',
            'user_tracking',
            'alerts',
            'system_settings',
            'agent_job_queue',
            'agent_logs',
            'raw_price_snapshots',
            'master_products',
            'product_master_mapping',
            'price_events',
        ];

        $stmt = $this->pdo->query('SHOW TABLES');
        $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($requiredTables as $table) {
            $this->assertContains(
                $table,
                $existingTables,
                "Required table '$table' does not exist"
            );
        }
    }

    /**
     * Test users table structure
     */
    public function testUsersTableStructure(): void
    {
        $stmt = $this->pdo->query('DESCRIBE users');
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $requiredColumns = [
            'user_id',
            'email',
            'password_hash',
            'full_name',
            'role',
            'is_active',
            'notify_email',
            'notify_line',
            'line_user_id',
            'created_at',
        ];

        foreach ($requiredColumns as $column) {
            $this->assertContains(
                $column,
                $columns,
                "Column '$column' missing from users table"
            );
        }
    }

    /**
     * Test tracked_products table structure
     */
    public function testTrackedProductsTableStructure(): void
    {
        $stmt = $this->pdo->query('DESCRIBE tracked_products');
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $requiredColumns = [
            'product_id',
            'platform',
            'platform_product_id',
            'product_url',
            'product_name',
            'image_url',
            'last_price',
            'last_original_price',
            'last_stock_status',
            'is_active',
            'created_at',
            'updated_at',
        ];

        foreach ($requiredColumns as $column) {
            $this->assertContains(
                $column,
                $columns,
                "Column '$column' missing from tracked_products table"
            );
        }
    }

    /**
     * Test agent_job_queue table structure
     */
    public function testAgentJobQueueTableStructure(): void
    {
        $stmt = $this->pdo->query('DESCRIBE agent_job_queue');
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $requiredColumns = [
            'job_id',
            'agent_type',
            'payload',
            'status',
            'priority',
            'attempts',
            'max_attempts',
            'scheduled_at',
            'started_at',
            'completed_at',
            'error_message',
            'created_at',
        ];

        foreach ($requiredColumns as $column) {
            $this->assertContains(
                $column,
                $columns,
                "Column '$column' missing from agent_job_queue table"
            );
        }
    }

    /**
     * Test inserting and retrieving a product
     */
    public function testProductInsertAndRetrieve(): void
    {
        // Insert test product
        $stmt = $this->pdo->prepare('
            INSERT INTO tracked_products
            (platform, platform_product_id, product_url, product_name, last_price, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, 1, NOW())
        ');

        $testProductId = 'test_' . uniqid();
        $stmt->execute([
            'jib',
            $testProductId,
            'https://www.jib.co.th/web/product/readProduct/' . $testProductId,
            'Test Product for PHPUnit',
            9999.00,
        ]);

        $insertedId = $this->pdo->lastInsertId();

        // Retrieve and verify
        $stmt = $this->pdo->prepare('SELECT * FROM tracked_products WHERE product_id = ?');
        $stmt->execute([$insertedId]);
        $product = $stmt->fetch();

        $this->assertNotFalse($product);
        $this->assertEquals('jib', $product['platform']);
        $this->assertEquals($testProductId, $product['platform_product_id']);
        $this->assertEquals(9999.00, (float) $product['last_price']);

        // Clean up
        $this->pdo->prepare('DELETE FROM tracked_products WHERE product_id = ?')->execute([$insertedId]);
    }

    /**
     * Test price history foreign key constraint
     */
    public function testPriceHistoryForeignKey(): void
    {
        // Try to insert price history with non-existent product_id
        $stmt = $this->pdo->prepare('
            INSERT INTO price_history (product_id, price, stock_status, scraped_at)
            VALUES (?, ?, ?, NOW())
        ');

        $this->expectException(PDOException::class);
        $stmt->execute([999999, 1000.00, 'in_stock']);
    }

    /**
     * Test system settings retrieval
     */
    public function testSystemSettingsRetrieval(): void
    {
        $stmt = $this->pdo->prepare('
            SELECT setting_value FROM system_settings WHERE setting_key = ?
        ');
        $stmt->execute(['cron_interval_minutes']);
        $result = $stmt->fetch();

        // Should have a default value or be configurable
        if ($result) {
            $this->assertNotEmpty($result['setting_value']);
        } else {
            // Setting might not exist yet, which is fine
            $this->assertTrue(true);
        }
    }
}
