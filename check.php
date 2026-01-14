<?php
// Simple diagnostic page to check setup
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>فحص الإعدادات</h2>";
echo "<hr>";

// Check PHP version
echo "<p><strong>إصدار PHP:</strong> " . phpversion() . "</p>";

// Check if config file exists
if (file_exists('config.php')) {
    echo "<p style='color: green;'>✓ ملف config.php موجود</p>";
    require_once 'config.php';
    echo "<p><strong>اسم التطبيق:</strong> " . APP_NAME . "</p>";
} else {
    echo "<p style='color: red;'>✗ ملف config.php غير موجود</p>";
    exit;
}

// Check database connection
echo "<hr><h3>فحص قاعدة البيانات</h3>";
try {
    require_once 'db.php';
    $db = getDB();
    echo "<p style='color: green;'>✓ الاتصال بقاعدة البيانات نجح</p>";
    
    // Check if tables exist
    $tables = ['factory', 'customers', 'drivers', 'daily_orders'];
    foreach ($tables as $table) {
        try {
            $result = $db->query("SELECT COUNT(*) FROM $table");
            $count = $result->fetchColumn();
            echo "<p style='color: green;'>✓ جدول $table موجود ($count سجل)</p>";
        } catch (PDOException $e) {
            echo "<p style='color: red;'>✗ جدول $table غير موجود</p>";
        }
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ خطأ في الاتصال بقاعدة البيانات: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>يرجى التحقق من:</p>";
    echo "<ul>";
    echo "<li>قاعدة البيانات موجودة: " . DB_NAME . "</li>";
    echo "<li>اسم المستخدم: " . DB_USER . "</li>";
    echo "<li>تم تشغيل ملف database.sql</li>";
    echo "</ul>";
}

// Check Google Maps API Key
echo "<hr><h3>فحص Google Maps API</h3>";
if (defined('GOOGLE_MAPS_API_KEY') && GOOGLE_MAPS_API_KEY !== 'YOUR_API_KEY_HERE') {
    echo "<p style='color: green;'>✓ مفتاح Google Maps API موجود</p>";
} else {
    echo "<p style='color: orange;'>⚠ مفتاح Google Maps API غير مضبوط</p>";
}

// Check file permissions
echo "<hr><h3>فحص الملفات</h3>";
$files = ['index.php', 'customers.php', 'drivers.php', 'orders.php', 'factory.php', 'db.php'];
foreach ($files as $file) {
    if (file_exists($file)) {
        echo "<p style='color: green;'>✓ $file موجود</p>";
    } else {
        echo "<p style='color: red;'>✗ $file غير موجود</p>";
    }
}

// Check assets
echo "<hr><h3>فحص الملفات الثابتة</h3>";
if (file_exists('assets/css/style.css')) {
    echo "<p style='color: green;'>✓ ملف CSS موجود</p>";
} else {
    echo "<p style='color: red;'>✗ ملف CSS غير موجود</p>";
}

echo "<hr>";
echo "<p><a href='index.php'>العودة للصفحة الرئيسية</a></p>";
?>
