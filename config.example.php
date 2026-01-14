<?php
// Configuration file template
// Copy this file to config.php and fill in your details

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'ice_cream_factory');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Google Maps API Key
// Get your API key from: https://console.cloud.google.com/google/maps-apis
// Enable: Maps JavaScript API, Geocoding API, Directions API
define('GOOGLE_MAPS_API_KEY', 'YOUR_API_KEY_HERE');

// Application settings
define('APP_NAME', 'نظام توزيع الآيس كريم');
define('TIMEZONE', 'Africa/Cairo');
date_default_timezone_set(TIMEZONE);

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
