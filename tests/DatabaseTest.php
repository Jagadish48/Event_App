<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    public function testDatabaseConnection()
    {
        // This will load the database.php file which attempts to connect using PDO
        require_once __DIR__ . '/../config/database.php';
        
        // Assert that the $pdo variable exists and is a valid PDO instance
        global $pdo;
        $this->assertInstanceOf(\PDO::class, $pdo, "The database connection failed or PDO instance is not created.");
    }

    public function testDatabaseTablesExist()
    {
        global $pdo;
        if (!$pdo) {
            $this->markTestSkipped('Database connection failed, skipping table check.');
        }

        // Query to check if the 'users' table exists, which means our schema imported correctly
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        $result = $stmt->fetch();
        
        $this->assertNotEmpty($result, "The 'users' table does not exist in the database.");
    }
}
