<?php
// Language management system
session_start();

// Default language
$defaultLang = 'ar';

// Get language from session or default
$currentLang = $_SESSION['lang'] ?? $defaultLang;

// Validate language
if (!in_array($currentLang, ['ar', 'en'])) {
    $currentLang = $defaultLang;
}

// Handle language switch
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'])) {
    $_SESSION['lang'] = $_GET['lang'];
    $currentLang = $_GET['lang'];
    
    // Redirect to same page without lang parameter
    $redirectUrl = strtok($_SERVER["REQUEST_URI"], '?');
    if (isset($_GET['date'])) {
        $redirectUrl .= '?date=' . $_GET['date'];
    }
    header('Location: ' . $redirectUrl);
    exit;
}

// Load translation file
$translations = [];
$langFile = __DIR__ . '/lang/' . $currentLang . '.php';
if (file_exists($langFile)) {
    require_once $langFile;
} else {
    // Fallback to Arabic
    require_once __DIR__ . '/lang/ar.php';
}

// Translation function
function t($key, $default = '') {
    global $translations;
    return $translations[$key] ?? $default ?: $key;
}

// Set direction based on language
$dir = $currentLang === 'ar' ? 'rtl' : 'ltr';
$textAlign = $currentLang === 'ar' ? 'right' : 'left';
