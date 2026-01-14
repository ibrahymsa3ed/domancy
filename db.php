<?php
// Database connection file
require_once 'config.php';

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            // Include port in DSN if defined
            $host = DB_HOST;
            if (defined('DB_PORT') && !empty(DB_PORT)) {
                $host .= ';port=' . DB_PORT;
            }
            $dsn = "mysql:host=" . $host . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Better error handling - log error and show user-friendly message
            error_log("Database connection error: " . $e->getMessage());
            $errorMsg = "خطأ في الاتصال بقاعدة البيانات. يرجى التحقق من إعدادات قاعدة البيانات في ملف config.php";
            if (ini_get('display_errors')) {
                $errorMsg .= "<br><small>Error: " . htmlspecialchars($e->getMessage()) . "</small>";
            }
            die("<div style='padding: 20px; font-family: Arial; direction: rtl; text-align: right;'><h2>خطأ في الاتصال</h2><p>" . $errorMsg . "</p><p>يرجى التأكد من:</p><ul><li>قاعدة البيانات موجودة</li><li>بيانات الاتصال صحيحة في config.php</li><li>تم تشغيل ملف database.sql</li></ul></div>");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }

    // Prevent cloning
    private function __clone() {}

    // Prevent unserialization
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

// Helper function to get database connection
function getDB() {
    return Database::getInstance()->getConnection();
}
