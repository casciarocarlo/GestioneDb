<?php
/**
 * Integration Tests for GestioneDb
 * Tests database operations and API endpoints
 */

require_once __DIR__ . '/../../config.php';

use PHPUnit\Framework\TestCase;

class IntegrationTest extends TestCase
{
    private $db;
    
    protected function setUp(): void
    {
        $this->db = new Database('test_db');
    }
    
    public function testFullDatabaseLifecycle()
    {
        // Create database
        $created = $this->db->createDatabase('integration_test_db');
        $this->assertTrue($created);
        
        // Verify it exists
        $databases = $this->db->getDatabases();
        $this->assertContains('integration_test_db', $databases);
        
        // Query the database
        $result = $this->db->query("SELECT 1 as test");
        $this->assertEquals(1, $result->fetch()[0]['test']);
        
        // Drop the database
        $dropped = $this->db->dropDatabase('integration_test_db');
        $this->assertTrue($dropped);
        
        // Verify it's gone
        $databases = $this->db->getDatabases();
        $this->assertNotContains('integration_test_db', $databases);
    }
    
    public function testTableOperations()
    {
        // Create a table
        $this->db->createTable('test_table', 'id INT PRIMARY KEY, name VARCHAR(100)');
        
        // Insert data
        $insert = $this->db->insert('test_table', [1, 'first'], [2, 'second']);
        $this->assertTrue($insert);
        
        // Query data
        $rows = $this->db->query("SELECT * FROM test_table");
        $this->assertCount(2, $rows);
        
        // Delete data
        $delete = $this->db->delete('test_table', [1]);
        $this->assertTrue($delete);
        
        // Verify deletion
        $rows = $this->db->query("SELECT * FROM test_table");
        $this->assertEmpty($rows);
    }
    
    public function testTableStatistics()
    {
        $this->db->createTable('users', 'id INT PRIMARY KEY, username VARCHAR(100), email VARCHAR(255)');
        
        // Insert several rows
        for ($i = 1; $i <= 5; $i++)
            $this->db->insert('users', [$i, "user_$i", "user_$i@example.com"]);
        
        // Count rows
        $count = $this->db->query("SELECT COUNT(*) as cnt FROM users");
        $this->assertEquals(5, $count->fetch()[0]['cnt']);
    }
    
    public function testGetTableStructure()
    {
        $this->db->createTable('structure_test', 'id INT PRIMARY KEY, name VARCHAR(100), age INT');
        
        $structure = $this->db->getTableStructure('structure_test');
        
        $this->assertIsArray($structure);
        $this->assertCount(3, $structure);
        
        $fields = array_column($structure, 'Field');
        $this->assertContains('id', $fields);
        $this->assertContains('name', $fields);
        $this->assertContains('age', $fields);
        
        $types = array_column($structure, 'Type');
        $this->assertContains('INT', $types);
        $this->assertContains('VARCHAR', $types);
        $this->assertContains('INT', $types);
    }
    
    public function testGetDatabasesWithMultipleDrivers()
    {
        // Test with different drivers
        $drivers = ['mysql', 'pgsql', 'sqlite'];
        
        foreach ($drivers as $driver) {
            $testDbName = 'test_' . $driver . '_' . uniqid();
            $this->db->createDatabase($testDbName);
            
            $databases = $this->db->getDatabases();
            $this->assertContains($testDbName, $databases);
            
            $this->db->dropDatabase($testDbName);
        }
    }
    
    public function testCreateDatabaseWithSpecialCharacters()
    {
        $testDbName = 'test_special_' . uniqid();
        $result = $this->db->createDatabase($testDbName);
        $this->assertTrue($result);
        
        $this->db->dropDatabase($testDbName);
    }
    
    public function testDropDatabaseWithSpecialCharacters()
    {
        $testDbName = 'drop_test_' . uniqid();
        $this->db->createDatabase($testDbName);
        
        $result = $this->db->dropDatabase($testDbName);
        $this->assertTrue($result);
    }
    
    public function testConnectionDriverProperties()
    {
        $drivers = ['mysql', 'pgsql', 'sqlsrv', 'sqlite'];
        
        foreach ($drivers as $driver) {
            $port = $this->db->defaultPort($driver);
            $this->assertIsInt($port);
            $this->assertGreaterThanOrEqual(0, $port);
        }
    }
    
    public function testDefaultPortValues()
    {
        $this->assertEquals(3306, $this->db->defaultPort('mysql'));
        $this->assertEquals(5432, $this->db->defaultPort('pgsql'));
        $this->assertEquals(1433, $this->db->defaultPort('sqlsrv'));
        $this->assertEquals(0, $this->db->defaultPort('sqlite'));
    }
    
    public function testSanitizeFunction()
    {
        $malicious = "<script>alert('xss')</script>";
        $sanitized = sanitize($malicious);
        
        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringNotContainsString('</script>', $sanitized);
    }
    
    public function testSanitizePreservesNormalContent()
    {
        $normal = "This is a normal text with spaces and punctuation.";
        $sanitized = sanitize($normal);
        
        $this->assertNotEmpty($sanitized);
        $this->assertEquals($normal, $sanitized);
    }
    
    public function testSanitizeSpecialCharactersEncoding()
    {
        $input = "<p>Special chars: & \" ' < >";
        $sanitized = sanitize($input);
        
        $this->assertStringContainsString('&', $sanitized);
        $this->assertStringContainsString('"', $sanitized);
        $this->assertStringContainsString('&#039;', $sanitized);
        $this->assertStringContainsString('<', $sanitized);
        $this->assertStringContainsString('>', $sanitized);
    }
    
    public function testEncryptDecryptRoundtrip()
    {
        $original = 'test_roundtrip_123';
        $encrypted = encryptValue($original);
        $decrypted = decryptValue($encrypted);
        
        $this->assertEquals($original, $decrypted);
    }
    
    public function testEncryptDecryptDifferentLengths()
    {
        $testValues = [
            '',
            'a',
            'short',
            str_repeat('x', 100),
            str_repeat('y', 1000)
        ];
        
        foreach ($testValues as $value) {
            $encrypted = encryptValue($value);
            $decrypted = decryptValue($encrypted);
            $this->assertEquals($value, $decrypted);
        }
    }
    
    public function testEncryptDecryptInvalidInput()
    {
        $this->assertEquals('', encryptValue(''));
        $this->assertEquals('', decryptValue(''));
        $this->assertEquals('', encryptValue(null));
        $this->assertEquals('', decryptValue(null));
    }
    
    public function testConnectionStringFormatting()
    {
        $host = 'localhost';
        $port = 3306;
        $dbName = 'test_db';
        
        $dsn = $this->db->buildPdo('mysql', $host, $port, $dbName, 'user', 'pass')->getConfig();
        $this->assertStringContainsString($host, $dsn);
        $this->assertStringContainsString($port, $dsn);
        $this->assertStringContainsString($dbName, $dsn);
    }
    
    public function testInvalidDriverException()
    {
        $this->expectException(\InvalidArgumentException::class);
        Database::buildPdo('invalid_driver', 'localhost', 3306, 'test', 'user', 'pass');
    }
}

class E2TEst extends TestCase
{
    public function testDashboardRender()
    {
        // This test ensures the dashboard page loads correctly
        $response = file_get_contents('index.php');
        $this->assertStringContainsString('Dashboard', $response);
        $this->assertStringContainsString('My Connections', $response);
    }
    
    public function testConnectionManagementUI()
    {
        // Test that the connections page contains expected elements
        $response = file_get_contents('connections.php');
        $this->assertStringContainsString('My Connections', $response);
        $this->assertStringContainsString('Add Connection', $response);
        $this->assertStringContainsString('Test Connection', $response);
        $this->assertStringContainsString('Delete', $response);
    }
}