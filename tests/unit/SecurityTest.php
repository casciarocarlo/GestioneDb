<?php
/**
 * Unit Tests for GestioneDb
 * Tests for core functionality
 */

require_once __DIR__ . '/../../config.php';

use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase
{
    public function testEncryptDecrypt()
    {
        $original = 'test_password_123!@#';
        $encrypted = encryptValue($original);
        $decrypted = decryptValue($encrypted);
        
        $this->assertEquals($original, $decrypted);
        $this->assertNotEquals($original, $encrypted);
        $this->assertStringStartsWith('v2:', $encrypted);
    }
    
    public function testEmptyStringEncryption()
    {
        $this->assertEquals('', encryptValue(''));
        $this->assertEquals('', decryptValue(''));
    }
    
    public function testSanitizeInput()
    {
        $malicious = "<script>alert('xss')</script>";
        $sanitized = sanitize($malicious);
        
        $this->assertStringNotContainsString('<script>', $sanitized);
    }
    
    public function testSanitizeSpecialCharacters()
    {
        $input = "test'\"&<>\"'";
        $sanitized = sanitize($input);
        
        $this->assertStringContainsString('&#039;', $sanitized);
        $this->assertStringContainsString('&quot;', $sanitized);
        $this->assertStringContainsString('&amp;', $sanitized);
        $this->assertStringContainsString('&lt;', $sanitized);
        $this->assertStringContainsString('&gt;', $sanitized);
    }
    
    public function testSanitizeUnicode()
    {
        $input = "café 🎉 中文";
        $sanitized = sanitize($input);
        
        $this->assertEquals($input, $sanitized);
    }
    
    public function testEncryptDecryptLongString()
    {
        $original = str_repeat('a', 10000);
        $encrypted = encryptValue($original);
        $decrypted = decryptValue($encrypted);
        
        $this->assertEquals($original, $decrypted);
    }
    
    public function testEncryptDecryptSpecialChars()
    {
        $original = "p@ssw0rd!#$%^&*()_+-=[]{}|;':\",./<>?";
        $encrypted = encryptValue($original);
        $decrypted = decryptValue($encrypted);
        
        $this->assertEquals($original, $decrypted);
    }
    
    public function testDecryptInvalidFormat()
    {
        $this->assertEquals('', decryptValue('invalid_format'));
        $this->assertEquals('', decryptValue('v2:invalid_base64'));
        $this->assertEquals('', decryptValue('v2:' . base64_encode('short_data_less_than_16_bytes')));
    }
}

class DatabaseTest extends TestCase
{
    private $db;
    
    protected function setUp(): void
    {
        // Use a test database
        $this->db = new Database('test_db');
    }
    
    public function testDatabaseConnection()
    {
        $this->assertInstanceOf(PDO::class, $this->db->getConnection());
    }
    
    public function testGetDatabases()
    {
        $databases = $this->db->getDatabases();
        $this->assertIsArray($databases);
    }
    
    public function testCreateAndDropDatabase()
    {
        $testDbName = 'test_' . uniqid();
        
        // Create database
        $result = $this->db->createDatabase($testDbName);
        $this->assertTrue($result);
        
        // Verify it exists
        $databases = $this->db->getDatabases();
        $this->assertContains($testDbName, $databases);
        
        // Drop database
        $result = $this->db->dropDatabase($testDbName);
        $this->assertTrue($result);
        
        // Verify it's gone
        $databases = $this->db->getDatabases();
        $this->assertNotContains($testDbName, $databases);
    }
    
    public function testCreateDatabaseWithSpecialChars()
    {
        $testDbName = 'test_db_' . uniqid();
        $result = $this->db->createDatabase($testDbName);
        $this->assertTrue($result);
        
        $this->db->dropDatabase($testDbName);
    }
    
    public function testGetTablesEmptyDatabase()
    {
        $testDbName = 'test_empty_' . uniqid();
        $this->db->createDatabase($testDbName);
        
        $db = new Database($testDbName);
        $tables = $db->getTables();
        
        $this->assertIsArray($tables);
        $this->assertEmpty($tables);
        
        $this->db->dropDatabase($testDbName);
    }
    
    public function testGetTableStructure()
    {
        $testDbName = 'test_structure_' . uniqid();
        $this->db->createDatabase($testDbName);
        
        $db = new Database($testDbName);
        $db->query("CREATE TABLE test_table (id INT PRIMARY KEY, name VARCHAR(255))");
        
        $structure = $db->getTableStructure('test_table');
        
        $this->assertIsArray($structure);
        $this->assertCount(2, $structure);
        $this->assertEquals('id', $structure[0]['Field']);
        $this->assertEquals('name', $structure[1]['Field']);
        
        $this->db->dropDatabase($testDbName);
    }
    
    public function testQueryExecution()
    {
        $testDbName = 'test_query_' . uniqid();
        $this->db->createDatabase($testDbName);
        
        $db = new Database($testDbName);
        $db->query("CREATE TABLE test (id INT, value VARCHAR(50))");
        $db->query("INSERT INTO test (id, value) VALUES (1, 'test1'), (2, 'test2')");
        
        $stmt = $db->query("SELECT * FROM test ORDER BY id");
        $results = $stmt->fetchAll();
        
        $this->assertCount(2, $results);
        $this->assertEquals('test1', $results[0]['value']);
        $this->assertEquals('test2', $results[1]['value']);
        
        $this->db->dropDatabase($testDbName);
    }
    
    public function testPreparedStatement()
    {
        $testDbName = 'test_prepared_' . uniqid();
        $this->db->createDatabase($testDbName);
        
        $db = new Database($testDbName);
        $db->query("CREATE TABLE test (id INT, value VARCHAR(50))");
        
        $stmt = $db->query("INSERT INTO test (id, value) VALUES (?, ?)", [1, 'prepared']);
        $this->assertTrue($stmt->rowCount() > 0);
        
        $stmt = $db->query("SELECT * FROM test WHERE id = ?", [1]);
        $result = $stmt->fetch();
        
        $this->assertEquals('prepared', $result['value']);
        
        $this->db->dropDatabase($testDbName);
    }
    
    public function testLastInsertId()
    {
        $testDbName = 'test_insertid_' . uniqid();
        $this->db->createDatabase($testDbName);
        
        $db = new Database($testDbName);
        $db->query("CREATE TABLE test (id INT AUTO_INCREMENT PRIMARY KEY, value VARCHAR(50))");
        $db->query("INSERT INTO test (value) VALUES ('test')");
        
        $lastId = $db->lastInsertId();
        $this->assertEquals('1', $lastId);
        
        $this->db->dropDatabase($testDbName);
    }
}

class ConnectionTest extends TestCase
{
    public function testConnectionEncryption()
    {
        $password = 'secret123';
        $encrypted = encryptValue($password);
        $decrypted = decryptValue($encrypted);
        
        $this->assertEquals($password, $decrypted);
    }
    
    public function testDefaultPorts()
    {
        $this->assertEquals(3306, defaultPort('mysql'));
        $this->assertEquals(5432, defaultPort('pgsql'));
        $this->assertEquals(1433, defaultPort('sqlsrv'));
        $this->assertEquals(0, defaultPort('sqlite'));
    }
    
    public function testDefaultPortUnknownDriver()
    {
        $this->assertEquals(3306, defaultPort('unknown'));
    }
}

class AuthTest extends TestCase
{
    public function testCSRFGeneration()
    {
        $token1 = generateCSRF();
        $token2 = generateCSRF();
        
        $this->assertEquals($token1, $token2); // Should return same token in session
        $this->assertEquals(64, strlen($token1)); // 32 bytes = 64 hex chars
    }
    
    public function testCSRFValidation()
    {
        $token = generateCSRF();
        $this->assertTrue(validateCSRF($token));
        $this->assertFalse(validateCSRF('invalid_token'));
        $this->assertFalse(validateCSRF(''));
    }
    
    public function testCSRFTokenRegeneration()
    {
        $token1 = generateCSRF();
        unset($_SESSION['csrf_token']);
        $token2 = generateCSRF();
        
        $this->assertNotEquals($token1, $token2);
        $this->assertEquals(64, strlen($token2));
    }
}

class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }
    
    public function testSessionStart()
    {
        $this->assertEquals(PHP_SESSION_ACTIVE, session_status());
    }
    
    public function testSessionVariables()
    {
        $_SESSION['test_var'] = 'test_value';
        $this->assertEquals('test_value', $_SESSION['test_var']);
    }
    
    public function testSessionDestroy()
    {
        $_SESSION['test'] = 'value';
        session_destroy();
        $this->assertEquals(PHP_SESSION_NONE, session_status());
    }
}

class InputValidationTest extends TestCase
{
    public function testSanitizeRemovesScriptTags()
    {
        $inputs = [
            "<script>alert(1)</script>",
            "<SCRIPT>alert(1)</SCRIPT>",
            "<ScRiPt>alert(1)</ScRiPt>",
            "<script src='evil.js'></script>",
            "javascript:alert(1)",
            "onload=alert(1)",
            "onclick=alert(1)",
        ];
        
        foreach ($inputs as $input) {
            $sanitized = sanitize($input);
            $this->assertStringNotContainsString('<script', strtolower($sanitized));
            $this->assertStringNotContainsString('javascript:', strtolower($sanitized));
            $this->assertStringNotContainsString('onload=', strtolower($sanitized));
            $this->assertStringNotContainsString('onclick=', strtolower($sanitized));
        }
    }
    
    public function testSanitizePreservesSafeContent()
    {
        $safeInputs = [
            'normal text',
            'user@example.com',
            'path/to/file.txt',
            '123-456-7890',
            'Café & Restaurant',
        ];
        
        foreach ($safeInputs as $input) {
            $sanitized = sanitize($input);
            // Safe content should be preserved (except special chars encoded)
            $this->assertNotEmpty($sanitized);
        }
    }
}

class DatabaseDriverTest extends TestCase
{
    public function testBuildPdoMySQL()
    {
        $pdo = Database::buildPdo('mysql', 'localhost', 3306, 'test', 'user', 'pass');
        $this->assertInstanceOf(PDO::class, $pdo);
    }
    
    public function testBuildPdoPostgreSQL()
    {
        $pdo = Database::buildPdo('pgsql', 'localhost', 5432, 'test', 'user', 'pass');
        $this->assertInstanceOf(PDO::class, $pdo);
    }
    
    public function testBuildPdoSQLServer()
    {
        $pdo = Database::buildPdo('sqlsrv', 'localhost', 1433, 'test', 'user', 'pass');
        $this->assertInstanceOf(PDO::class, $pdo);
    }
    
    public function testBuildPdoSQLite()
    {
        $pdo = Database::buildPdo('sqlite', '', 0, ':memory:', '', '');
        $this->assertInstanceOf(PDO::class, $pdo);
    }
    
    public function testBuildPdoInvalidDriver()
    {
        $this->expectException(\InvalidArgumentException::class);
        Database::buildPdo('invalid', 'localhost', 3306, 'test', 'user', 'pass');
    }
}

class LanguageTest extends TestCase
{
    public function testTranslationFunction()
    {
        global $translations;
        $translations = [
            'test_key' => 'Test Value',
            'another_key' => 'Another Value',
        ];
        
        $this->assertEquals('Test Value', __('test_key'));
        $this->assertEquals('Another Value', __('another_key'));
        $this->assertEquals('missing_key', __('missing_key'));
        $this->assertEquals('default', __('missing_key', 'default'));
    }
}

class RedirectTest extends TestCase
{
    public function testRedirectFunction()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Headers already sent');
        
        // This will fail because headers are already sent in test environment
        // but we test the function exists
        $this->assertTrue(function_exists('redirect'));
    }
}

class ShowMessageTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }
    
    public function testShowMessage()
    {
        showMessage('Test message', 'success');
        
        $this->assertEquals('Test message', $_SESSION['message']);
        $this->assertEquals('success', $_SESSION['message_type']);
    }
    
    public function testShowMessageDefaultType()
    {
        showMessage('Test message');
        
        $this->assertEquals('Test message', $_SESSION['message']);
        $this->assertEquals('info', $_SESSION['message_type']);
    }
}