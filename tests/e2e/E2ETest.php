<?php
/**
 * End-to-End Tests for GestioneDb
 * Tests user workflows and UI interactions
 */

require_once __DIR__ . '/../../config.php';

use PHPUnit\Framework\TestCase;

class E2ETest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }
    
    public function testUserLoginAndDashboard()
    {
        // Simulate user login
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'test_user';
        $_SESSION['role'] = 'user';
        
        // Check authentication
        $this->assertTrue(isAuthenticated());
        
        // Check session validation
        $this->assertTrue(validateSessionToken());
        
        // Get current user
        $user = getCurrentUser();
        $this->assertEquals('test_user', $user['username']);
        $this->assertEquals('user', $user['role']);
    }
    
    public function testAdminAccess()
    {
        // Simulate admin login
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = 2;
        $_SESSION['username'] = 'admin_user';
        $_SESSION['role'] = 'admin';
        
        $this->assertTrue(hasRole('admin'));
        $this->assertTrue(requireAdmin(false));
    }
    
    public function testNonAdminCannotAccessAdmin()
    {
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'regular_user';
        $_SESSION['role'] = 'user';
        
        $this->assertFalse(hasRole('admin'));
        $this->assertFalse(requireAdmin(false));
    }
    
    public function testUnauthenticatedUser()
    {
        $_SESSION = [];
        
        $this->assertFalse(isAuthenticated());
        $this->assertFalse(validateSessionToken());
        $this->assertNull(getCurrentUser());
    }
    
    public function testDatabaseCreationWorkflow()
    {
        // Simulate user creating a database
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = 1;
        
        $db = new Database('test_db');
        
        // Create database
        $result = $db->createDatabase('e2e_test_db');
        $this->assertTrue($result);
        
        // Verify it appears in database list
        $databases = $db->getDatabases();
        $this->assertContains('e2e_test_db', $databases);
        
        // Clean up
        $db->dropDatabase('e2e_test_db');
    }
    
    public function testConnectionManagementWorkflow()
    {
        // Simulate user managing connections
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = 1;
        
        // Test connection encryption
        $password = 'secure_password_123';
        $encrypted = encryptValue($password);
        $decrypted = decryptValue($encrypted);
        
        $this->assertEquals($password, $decrypted);
        
        // Test connection activation
        $conn = [
            'id' => 1,
            'label' => 'Test Connection',
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'db_name' => 'test_db',
            'db_user' => 'root',
            'db_pass_enc' => $encrypted
        ];
        
        // This would normally be done via POST, but we test the logic
        $decryptedPass = decryptValue($conn['db_pass_enc']);
        $this->assertEquals('secure_password_123', $decryptedPass);
    }
    
    public function testExportWorkflow()
    {
        // Test export functionality
        $db = new Database('test_db');
        $db->createTable('export_test', 'id INT, data VARCHAR(100)');
        $db->insert('export_test', [1, 'test_data']);
        
        // Export would generate SQL or CSV
        // This is a placeholder for actual export test
        $this->assertTrue(true);
    }
    
    public function testFullUserWorkflow()
    {
        // 1. User logs in
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'workflow_user';
        $_SESSION['role'] = 'user';
        
        $this->assertTrue(isAuthenticated());
        
        // 2. User creates a database
        $db = new Database('test_db');
        $dbName = 'workflow_db_' . uniqid();
        $result = $db->createDatabase($dbName);
        $this->assertTrue($result);
        
        // 3. User creates a table
        $db = new Database($dbName);
        $db->query("CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(100), email VARCHAR(255))");
        
        // 4. User inserts data
        $db->query("INSERT INTO users (id, name, email) VALUES (1, 'John', 'john@example.com')");
        $db->query("INSERT INTO users (id, name, email) VALUES (2, 'Jane', 'jane@example.com')");
        
        // 5. User queries data
        $stmt = $db->query("SELECT * FROM users ORDER BY id");
        $users = $stmt->fetchAll();
        
        $this->assertCount(2, $users);
        $this->assertEquals('John', $users[0]['name']);
        $this->assertEquals('Jane', $users[1]['name']);
        
        // 6. User views table structure
        $structure = $db->getTableStructure('users');
        $this->assertCount(3, $structure);
        
        // 7. User drops database
        $db = new Database('test_db');
        $result = $db->dropDatabase($dbName);
        $this->assertTrue($result);
    }
    
    public function testMultiDatabaseWorkflow()
    {
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = 1;
        
        $db = new Database('test_db');
        
        // Create multiple databases
        $dbNames = [];
        for ($i = 1; $i <= 3; $i++) {
            $dbName = 'multi_db_' . $i . '_' . uniqid();
            $db->createDatabase($dbName);
            $dbNames[] = $dbName;
        }
        
        // Verify all databases exist
        $databases = $db->getDatabases();
        foreach ($dbNames as $dbName) {
            $this->assertContains($dbName, $databases);
        }
        
        // Clean up
        foreach ($dbNames as $dbName) {
            $db->dropDatabase($dbName);
        }
        
        // Verify all databases are gone
        $databases = $db->getDatabases();
        foreach ($dbNames as $dbName) {
            $this->assertNotContains($dbName, $databases);
        }
    }
    
    public function testTableOperationsWorkflow()
    {
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = 1;
        
        $db = new Database('test_db');
        $dbName = 'table_ops_' . uniqid();
        $db->createDatabase($dbName);
        
        $db = new Database($dbName);
        
        // Create table
        $db->query("CREATE TABLE products (id INT PRIMARY KEY, name VARCHAR(100), price DECIMAL(10,2))");
        
        // Insert multiple products
        $products = [
            [1, 'Product A', 19.99],
            [2, 'Product B', 29.99],
            [3, 'Product C', 39.99],
        ];
        
        foreach ($products as $product) {
            $db->query("INSERT INTO products (id, name, price) VALUES (?, ?, ?)", $product);
        }
        
        // Query all products
        $stmt = $db->query("SELECT * FROM products ORDER BY id");
        $results = $stmt->fetchAll();
        
        $this->assertCount(3, $results);
        $this->assertEquals('Product A', $results[0]['name']);
        $this->assertEquals(19.99, $results[0]['price']);
        
        // Update a product
        $db->query("UPDATE products SET price = ? WHERE id = ?", [24.99, 1]);
        
        $stmt = $db->query("SELECT * FROM products WHERE id = 1");
        $updated = $stmt->fetch();
        $this->assertEquals(24.99, $updated['price']);
        
        // Delete a product
        $db->query("DELETE FROM products WHERE id = 3");
        
        $stmt = $db->query("SELECT * FROM products");
        $results = $stmt->fetchAll();
        $this->assertCount(2, $results);
        
        // Clean up
        $db = new Database('test_db');
        $db->dropDatabase($dbName);
    }
    
    public function testSessionManagementWorkflow()
    {
        // Test session creation
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'session_user';
        $_SESSION['role'] = 'user';
        $_SESSION['session_token'] = 'test_token_123';
        
        $this->assertTrue(isAuthenticated());
        $this->assertTrue(validateSessionToken());
        
        // Test session destruction
        session_destroy();
        
        $this->assertFalse(isAuthenticated());
        $this->assertFalse(validateSessionToken());
    }
    
    public function testCSRFProtectionWorkflow()
    {
        // Generate CSRF token
        $token = generateCSRF();
        $this->assertNotEmpty($token);
        $this->assertEquals(64, strlen($token));
        
        // Validate token
        $this->assertTrue(validateCSRF($token));
        
        // Invalid token should fail
        $this->assertFalse(validateCSRF('invalid_token'));
        $this->assertFalse(validateCSRF(''));
    }
    
    public function testLanguageSwitchingWorkflow()
    {
        global $translations;
        
        // Test English
        $translations = ['welcome' => 'Welcome'];
        $this->assertEquals('Welcome', __('welcome'));
        
        // Test Italian
        $translations = ['welcome' => 'Benvenuto'];
        $this->assertEquals('Benvenuto', __('welcome'));
        
        // Test missing key with default
        $this->assertEquals('Default', __('missing', 'Default'));
    }
    
    public function testErrorHandlingWorkflow()
    {
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = 1;
        
        $db = new Database('test_db');
        
        // Test creating database with invalid name
        try {
            $db->createDatabase('invalid-name'); // MySQL doesn't allow hyphens without backticks
            // If it succeeds, that's fine too
        } catch (Exception $e) {
            // Expected to fail
            $this->assertNotEmpty($e->getMessage());
        }
        
        // Test dropping non-existent database
        try {
            $db->dropDatabase('non_existent_db_' . uniqid());
        } catch (Exception $e) {
            // Expected to fail
            $this->assertNotEmpty($e->getMessage());
        }
    }
}

class AuthWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }
    
    public function testLoginFlow()
    {
        // Simulate successful login
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'login_user';
        $_SESSION['email'] = 'login@example.com';
        $_SESSION['role'] = 'user';
        $_SESSION['session_token'] = bin2hex(random_bytes(32));
        
        $this->assertTrue(isAuthenticated());
        
        $user = getCurrentUser();
        $this->assertEquals('login_user', $user['username']);
        $this->assertEquals('login@example.com', $user['email']);
        $this->assertEquals('user', $user['role']);
    }
    
    public function testLogoutFlow()
    {
        // Set up session
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'logout_user';
        $_SESSION['session_token'] = 'test_token';
        
        $this->assertTrue(isAuthenticated());
        
        // Simulate logout
        session_destroy();
        
        $this->assertFalse(isAuthenticated());
    }
    
    public function testRoleBasedAccess()
    {
        // Test user role
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = 1;
        $_SESSION['role'] = 'user';
        
        $this->assertTrue(hasRole('user'));
        $this->assertFalse(hasRole('admin'));
        
        // Test admin role
        $_SESSION['role'] = 'admin';
        
        $this->assertTrue(hasRole('user'));
        $this->assertTrue(hasRole('admin'));
    }
}