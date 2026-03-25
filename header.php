<?php
// Common header
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle : APP_NAME; ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
    
    <?php if (isset($googleMapsScript)): ?>
        <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo GOOGLE_MAPS_API_KEY; ?>&language=ar&region=EG&libraries=<?php echo $googleMapsScript; ?>"></script>
        <script>
            window.initGoogleMaps = window.initGoogleMaps || function() {};
            let googleMapsLoaded = false;
            if (typeof google !== 'undefined' && google.maps) {
                googleMapsLoaded = true;
            }
        </script>
    <?php endif; ?>
</head>
<body>
    <nav class="navbar navbar-expand-lg">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <?php if (file_exists('assets/images/logo.png')): ?>
                    <img src="assets/images/logo.png" alt="دومانسي" class="logo">
                <?php elseif (file_exists('assets/images/logo.jpg')): ?>
                    <img src="assets/images/logo.jpg" alt="دومانسي" class="logo">
                <?php else: ?>
                    <i class="bi bi-geo-alt-fill"></i>
                <?php endif; ?>
                <span class="logo-text"><?php echo APP_NAME; ?></span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="index.php">
                            <i class="bi bi-map"></i> الخريطة
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'customers.php' ? 'active' : ''; ?>" href="customers.php">
                            <i class="bi bi-people"></i> العملاء
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'drivers.php' ? 'active' : ''; ?>" href="drivers.php">
                            <i class="bi bi-truck"></i> السائقين
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'orders.php' ? 'active' : ''; ?>" href="orders.php">
                            <i class="bi bi-cart-check"></i> الطلبات اليومية
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>" href="reports.php">
                            <i class="bi bi-clipboard-data"></i> التقارير
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'factory.php' ? 'active' : ''; ?>" href="factory.php">
                            <i class="bi bi-building"></i> موقع المصنع
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
