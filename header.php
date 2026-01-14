<?php
// Common header with inline styles - No external CSS file needed
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle : APP_NAME; ?></title>
    
    <!-- Bootstrap 5 from CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- Custom Pink Theme Styles - Inline -->
    <style>
        :root {
            --pink-primary: #ff6b9d;
            --pink-light: #ffb3d1;
            --pink-dark: #e91e63;
            --pink-lighter: #ffe6f0;
            --white: #ffffff;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            direction: rtl;
            text-align: right;
            background-color: #fafafa;
        }

        /* Navbar - Small & Pink */
        .navbar {
            min-height: 40px;
            padding-top: 0.15rem;
            padding-bottom: 0.15rem;
            background: linear-gradient(135deg, var(--pink-primary) 0%, var(--pink-dark) 100%) !important;
            box-shadow: 0 2px 4px rgba(233, 30, 99, 0.2);
        }

        .navbar-nav {
            margin-right: 0 !important;
            margin-left: auto;
        }

        .navbar-nav .nav-link {
            padding: 0.25rem 0.6rem;
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.95) !important;
            transition: all 0.3s;
        }

        .navbar-nav .nav-link:hover,
        .navbar-nav .nav-link.active {
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: 4px;
            color: var(--white) !important;
        }

        .navbar-nav .nav-link i {
            font-size: 0.85rem;
            margin-left: 4px;
        }

        /* Logo - Small */
        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 0.15rem 0.4rem;
        }

        .navbar-brand .logo {
            height: 50px;
            width: 50px;
            object-fit: contain;
            border-radius: 50%;
        }

        .navbar-brand .logo-text {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--white) !important;
        }

        .navbar-brand i {
            font-size: 0.95rem;
            color: var(--white);
        }

        /* Forms RTL */
        .form-control, .form-select {
            text-align: right;
        }

        .form-check {
            text-align: right;
            padding-right: 1.5em;
            padding-left: 0;
            position: relative;
        }

        .form-check-input {
            position: absolute;
            right: 0;
            margin-right: 0;
            margin-left: 0;
        }

        .form-check-label {
            padding-right: 0;
            margin-right: 0;
        }

        table {
            direction: rtl;
        }

        /* Cards - Pink Theme */
        .card {
            box-shadow: 0 2px 8px rgba(255, 107, 157, 0.15);
            margin-bottom: 20px;
            border: 1px solid var(--pink-lighter);
            background-color: var(--white);
        }

        .card-header {
            font-weight: bold;
            background: linear-gradient(135deg, var(--pink-primary) 0%, var(--pink-dark) 100%) !important;
            color: var(--white) !important;
            border-bottom: none;
            padding: 0.6rem 1rem;
            font-size: 0.95rem;
        }

        .card-header.bg-primary {
            background: linear-gradient(135deg, var(--pink-primary) 0%, var(--pink-dark) 100%) !important;
        }

        .card-header.bg-success {
            background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%) !important;
        }

        .card-header.bg-info {
            background: linear-gradient(135deg, #00bcd4 0%, #0097a7 100%) !important;
        }

        /* Buttons - Pink Theme */
        .btn {
            margin-left: 5px;
            margin-right: 0;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--pink-primary) 0%, var(--pink-dark) 100%);
            border: none;
            color: var(--white);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--pink-dark) 0%, #c2185b 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(233, 30, 99, 0.3);
        }

        .btn:last-child {
            margin-left: 0;
        }

        /* Form Inputs - Pink Focus */
        .form-control:focus,
        .form-select:focus {
            border-color: var(--pink-primary);
            box-shadow: 0 0 0 0.2rem rgba(255, 107, 157, 0.25);
        }

        .form-check-input:checked {
            background-color: var(--pink-primary);
            border-color: var(--pink-primary);
        }

        /* Badge - Pink */
        .badge.bg-primary {
            background-color: var(--pink-primary) !important;
        }

        /* Table Hover - Pink */
        .table-hover tbody tr:hover {
            background-color: var(--pink-lighter);
        }

        /* Scrollbar - Pink */
        .border {
            scrollbar-width: thin;
            scrollbar-color: var(--pink-primary) #f1f1f1;
        }

        .border::-webkit-scrollbar {
            width: 8px;
        }

        .border::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        .border::-webkit-scrollbar-thumb {
            background: var(--pink-primary);
            border-radius: 4px;
        }

        .border::-webkit-scrollbar-thumb:hover {
            background: var(--pink-dark);
        }

        /* List & Alert RTL */
        .list-group-item {
            text-align: right;
        }

        .alert {
            text-align: right;
        }
        
        /* Language switcher */
        .btn-group .btn {
            min-width: 40px;
            font-weight: 600;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container-fluid {
                padding: 10px;
            }
            
            #map, #routeMap {
                height: 400px !important;
            }
        }
    </style>
    
    <?php if (isset($googleMapsScript)): ?>
        <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo GOOGLE_MAPS_API_KEY; ?>&language=ar&region=EG&libraries=<?php echo $googleMapsScript; ?>"></script>
        <script>
            // Fallback if callback doesn't fire
            window.initGoogleMaps = window.initGoogleMaps || function() {};
            let googleMapsLoaded = false;
            
            // Check if Google Maps is already loaded
            if (typeof google !== 'undefined' && google.maps) {
                googleMapsLoaded = true;
            }
        </script>
    <?php endif; ?>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
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
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'factory.php' ? 'active' : ''; ?>" href="factory.php">
                            <i class="bi bi-building"></i> موقع المصنع
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
