<?php
require_once 'db.php';

$message = '';
$messageType = '';

// Initialize variables
$factoryLat = 30.0444; // Default Cairo coordinates
$factoryLng = 31.2357;
$customers = [];
$todayOrderIds = [];
$todayOrdersByDriver = [];
$todayOrdersByCustomer = [];
$driverColors = [];
$todayDrivers = [];
$todayOrdersForAssign = [];
$drivers = [];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if ($_POST['action'] === 'assign_driver') {
            $order_id = $_POST['order_id'] ?? 0;
            $driver_id = $_POST['driver_id'] ?? 0;
            if ($order_id && $driver_id) {
                $stmt = getDB()->prepare("UPDATE daily_orders SET driver_id = ?, status = 'assigned' WHERE id = ?");
                $stmt->execute([$driver_id, $order_id]);
                $message = "تم تعيين السائق بنجاح";
                $messageType = "success";
            }
        }
    }
    // Get factory location
    $factory = getDB()->query("SELECT * FROM factory LIMIT 1")->fetch();
    if ($factory) {
        $factoryLat = $factory['latitude'];
        $factoryLng = $factory['longitude'];
    }

    // Get all customers
    $customers = getDB()->query("SELECT * FROM customers ORDER BY name")->fetchAll();

    // Get active drivers
    $drivers = getDB()->query("SELECT * FROM drivers WHERE is_active = 1 ORDER BY name")->fetchAll();

    // Get today's orders
    $today = date('Y-m-d');
    $orders = getDB()->prepare("SELECT customer_id FROM daily_orders WHERE order_date = ?");
    $orders->execute([$today]);
    $todayOrderIds = $orders->fetchAll(PDO::FETCH_COLUMN);

    // Get today's orders for assignment list
    $todayOrdersList = getDB()->prepare("
        SELECT o.id, o.driver_id, c.name AS customer_name, d.name AS driver_name
        FROM daily_orders o
        JOIN customers c ON o.customer_id = c.id
        LEFT JOIN drivers d ON o.driver_id = d.id
        WHERE o.order_date = ?
        ORDER BY o.id
    ");
    $todayOrdersList->execute([$today]);
    $todayOrdersForAssign = $todayOrdersList->fetchAll();

    // Get today's orders with driver routes + customer mapping
    $routesStmt = getDB()->prepare("
        SELECT o.id, o.customer_id, o.driver_id, c.name, c.address, c.latitude, c.longitude, c.phone, d.name AS driver_name, d.color AS driver_color
        FROM daily_orders o
        JOIN customers c ON o.customer_id = c.id
        LEFT JOIN drivers d ON o.driver_id = d.id
        WHERE o.order_date = ?
        ORDER BY o.driver_id, o.id
    ");
    $routesStmt->execute([$today]);
    $routeRows = $routesStmt->fetchAll();
    foreach ($routeRows as $row) {
        $driverId = $row['driver_id'];
        if ($driverId) {
            if (!isset($todayOrdersByDriver[$driverId])) {
                $todayOrdersByDriver[$driverId] = [];
            }
            $todayOrdersByDriver[$driverId][] = $row;
        }
        if (!empty($row['driver_id']) && !empty($row['driver_name'])) {
            $todayDrivers[$row['driver_id']] = $row['driver_name'];
        }
        $todayOrdersByCustomer[$row['customer_id']] = [
            'driver_name' => $row['driver_name'] ?? null,
            'driver_id' => $row['driver_id'] ?? null,
            'driver_color' => $row['driver_color'] ?? null,
        ];
    }

    // Get driver colors
    $driverColors = getDB()->query("SELECT id, color FROM drivers")->fetchAll();

    // Mark customers with orders today
    foreach ($customers as &$customer) {
        $customer['has_order_today'] = in_array($customer['id'], $todayOrderIds);
    }
    unset($customer);
} catch (PDOException $e) {
    // Database error - show friendly message
    error_log("Database error: " . $e->getMessage());
    $customers = [];
    $todayOrderIds = [];
    $todayOrdersByDriver = [];
    $todayOrdersByCustomer = [];
    $driverColors = [];
    $todayDrivers = [];
    $todayOrdersForAssign = [];
    $drivers = [];
}

// Set variables for header
$pageTitle = APP_NAME . ' - الخريطة';
$googleMapsScript = 'places,geometry';
require_once 'header.php';
?>

    <div class="container-fluid mt-3">
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <div class="row">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-filter"></i> الفلاتر</h5>
                    </div>
                    <div class="card-body">
                        <div class="btn-group w-100 mb-3" role="group" aria-label="تصفية سريعة">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="filterAllBtn">الكل</button>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="filterTodayBtn">طلبات اليوم</button>
                        </div>
                        <div class="mb-3">
                            <div class="fw-bold mb-2">مسارات اليوم</div>
                            <?php if (empty($todayDrivers)): ?>
                                <div class="text-muted small">لا يوجد سائقين معينين اليوم</div>
                            <?php else: ?>
                                <?php foreach ($todayDrivers as $driverId => $driverName): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="small"><?php echo htmlspecialchars($driverName); ?></span>
                                        <button type="button" class="btn btn-sm btn-outline-primary route-toggle-btn" data-driver-id="<?php echo $driverId; ?>">
                                            إخفاء المسار
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <div class="fw-bold mb-2">تعيين السائقين اليوم</div>
                            <?php if (empty($todayOrdersForAssign)): ?>
                                <div class="text-muted small">لا توجد طلبات اليوم</div>
                            <?php else: ?>
                                <div class="border rounded p-2" style="max-height: 250px; overflow-y: auto;">
                                    <?php foreach ($todayOrdersForAssign as $order): ?>
                                        <div class="mb-2">
                                            <div class="small fw-bold"><?php echo htmlspecialchars($order['customer_name']); ?></div>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="assign_driver">
                                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                <select name="driver_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                                    <?php if (!$order['driver_id']): ?>
                                                        <option value="">اختر سائق</option>
                                                    <?php endif; ?>
                                                    <?php foreach ($drivers as $driver): ?>
                                                        <option value="<?php echo $driver['id']; ?>" <?php echo ($order['driver_id'] == $driver['id']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($driver['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                            <div class="text-muted small">
                                                السائق الحالي: <?php echo $order['driver_name'] ? htmlspecialchars($order['driver_name']) : 'غير معين'; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <hr>
                        <div class="mb-2">
                            <span class="badge bg-success me-2">●</span>
                            <small>طلبات اليوم</small>
                        </div>
                        <div class="mb-2">
                            <span class="badge bg-secondary me-2">●</span>
                            <small>بدون طلبات</small>
                        </div>
                        <div class="mb-2">
                            <span class="badge bg-danger me-2">●</span>
                            <small>دومانسي</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-9">
                <div id="map" style="height: 80vh; width: 100%; border-radius: 8px;"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/routes.js"></script>
    <script>
        // Initialize map
        const factoryLocation = { lat: <?php echo $factoryLat; ?>, lng: <?php echo $factoryLng; ?> };
        const map = new google.maps.Map(document.getElementById('map'), {
            zoom: 10,
            center: factoryLocation,
            mapTypeControl: true,
            streetViewControl: true,
            fullscreenControl: true
        });

        // Add factory marker
        const factoryMarker = new google.maps.Marker({
            position: factoryLocation,
            map: map,
            title: 'دومانسي',
            icon: {
                url: 'http://maps.google.com/mapfiles/ms/icons/red-dot.png'
            }
        });

        const factoryInfoWindow = new google.maps.InfoWindow({
            content: '<div class="text-center"><strong>دومانسي</strong></div>'
        });
        factoryMarker.addListener('click', () => {
            factoryInfoWindow.open(map, factoryMarker);
        });

        // Customer markers
        const customerMarkers = [];
        const customerData = <?php echo json_encode($customers, JSON_UNESCAPED_UNICODE); ?>;
        const todayOrdersByDriver = <?php echo json_encode($todayOrdersByDriver, JSON_UNESCAPED_UNICODE); ?>;
        const todayOrdersByCustomer = <?php echo json_encode($todayOrdersByCustomer, JSON_UNESCAPED_UNICODE); ?>;
        const driverColors = <?php echo json_encode($driverColors, JSON_UNESCAPED_UNICODE); ?>;
        const driverColorMap = {};
        driverColors.forEach(driver => {
            if (driver.color) {
                driverColorMap[driver.id] = driver.color;
            }
        });

        customerData.forEach(customer => {
            const lat = parseFloat(customer.latitude);
            const lng = parseFloat(customer.longitude);
            const hasValidCoords = Number.isFinite(lat) && Number.isFinite(lng);
            const position = hasValidCoords ? { lat, lng } : factoryLocation;
            const orderInfo = todayOrdersByCustomer[customer.id] || null;
            const pinColor = orderInfo && orderInfo.driver_id
                ? getDriverColor(orderInfo.driver_id)
                : '#6c757d';
            const marker = new google.maps.Marker({
                position: position,
                map: map,
                title: customer.name,
                icon: {
                    path: "M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5S13.38 11.5 12 11.5z",
                    fillColor: pinColor,
                    fillOpacity: 1,
                    strokeColor: '#ffffff',
                    strokeWeight: 1,
                    scale: 1.2,
                    anchor: new google.maps.Point(12, 22)
                },
                visible: true
            });
            const driverLabel = orderInfo
                ? `السائق: ${orderInfo.driver_name ? orderInfo.driver_name : 'غير معين'}`
                : null;
            const infoWindow = new google.maps.InfoWindow({
                content: `
                    <div>
                        <strong>${customer.name}</strong><br>
                        ${customer.phone ? '📞 ' + customer.phone + '<br>' : ''}
                        ${customer.address}<br>
                        ${hasValidCoords ? '' : '<small class="text-muted">⚠️ بدون إحداثيات دقيقة</small><br>'}
                        ${customer.has_order_today ? '<span class="badge bg-success">طلبات اليوم</span><br>' : ''}
                        ${driverLabel ? `<small>${driverLabel}</small>` : ''}
                    </div>
                `
            });

            marker.addListener('click', () => {
                infoWindow.open(map, marker);
            });

            customerMarkers.push({
                marker: marker,
                hasOrder: customer.has_order_today,
                infoWindow: infoWindow
            });
        });

        /** @type {Object<string, google.maps.DirectionsRenderer[]>} One or more polylines per driver when chunked */
        const dailyRouteRenderers = {};

        function renderDailyRoutes() {
            const directionsService = new google.maps.DirectionsService();
            const tasks = Object.keys(todayOrdersByDriver).map(driverId => {
                const driverOrders = todayOrdersByDriver[driverId];
                if (!driverOrders || driverOrders.length === 0) {
                    return Promise.resolve();
                }
                return RovanaRoutes.renderDriverRoute(
                    directionsService,
                    map,
                    factoryLocation,
                    driverOrders,
                    getDriverColor(driverId)
                ).then(renderers => {
                    dailyRouteRenderers[driverId] = renderers;
                });
            });
            Promise.all(tasks);
        }

        function toggleMainRoute(driverId) {
            const isVisible = !!dailyRouteRenderers[driverId] && dailyRouteRenderers[driverId].length > 0;
            if (isVisible) {
                RovanaRoutes.clearRenderers(dailyRouteRenderers[driverId]);
                delete dailyRouteRenderers[driverId];
                updateRouteToggleButton(driverId, false);
            } else {
                const driverOrders = todayOrdersByDriver[driverId];
                if (!driverOrders || driverOrders.length === 0) return;

                const directionsService = new google.maps.DirectionsService();
                RovanaRoutes.renderDriverRoute(
                    directionsService,
                    map,
                    factoryLocation,
                    driverOrders,
                    getDriverColor(driverId)
                ).then(renderers => {
                    dailyRouteRenderers[driverId] = renderers;
                    updateRouteToggleButton(driverId, renderers.length > 0);
                });
            }
        }

        function updateRouteToggleButton(driverId, isVisible) {
            const btn = document.querySelector(`.route-toggle-btn[data-driver-id="${driverId}"]`);
            if (!btn) return;
            btn.classList.toggle('btn-primary', isVisible);
            btn.classList.toggle('btn-outline-primary', !isVisible);
            btn.textContent = isVisible ? 'إخفاء المسار' : 'عرض المسار';
        }

        function getDriverColor(driverId) {
            const mapped = driverColorMap[driverId];
            if (mapped) return mapped;
            const fallback = [
                '#e6194b', '#3cb44b', '#ffe119', '#4363d8', '#f58231',
                '#911eb4', '#46f0f0', '#f032e6', '#bcf60c', '#fabebe',
                '#008080', '#e6beff', '#9a6324', '#fffac8', '#800000',
                '#aaffc3', '#808000', '#ffd8b1', '#000075', '#808080'
            ];
            const idNum = parseInt(driverId, 10) || 0;
            return fallback[idNum % fallback.length];
        }

        function updateMapBounds() {
            const bounds = new google.maps.LatLngBounds();
            let hasAny = false;

            // Always include factory
            bounds.extend(factoryLocation);
            hasAny = true;

            customerMarkers.forEach(item => {
                if (item.marker.getVisible()) {
                    bounds.extend(item.marker.getPosition());
                    hasAny = true;
                }
            });

            if (hasAny) {
                map.fitBounds(bounds);
            }
        }

        function applyCustomerFilters() {
            const showAll = currentFilter === 'all';
            const showToday = currentFilter === 'today';

            customerMarkers.forEach(item => {
                if (showAll) {
                    item.marker.setVisible(true);
                } else if (showToday) {
                    item.marker.setVisible(item.hasOrder);
                } else {
                    item.marker.setVisible(false);
                }
            });

            updateMapBounds();
        }

        function setFilterMode(mode) {
            currentFilter = mode;
            applyCustomerFilters();
            updateFilterButtons();
        }

        function updateFilterButtons() {
            const allBtn = document.getElementById('filterAllBtn');
            const todayBtn = document.getElementById('filterTodayBtn');
            if (!allBtn || !todayBtn) return;
            const isAll = currentFilter === 'all';
            allBtn.classList.toggle('btn-primary', isAll);
            allBtn.classList.toggle('btn-outline-primary', !isAll);
            todayBtn.classList.toggle('btn-primary', !isAll);
            todayBtn.classList.toggle('btn-outline-primary', isAll);
        }

        // Filter controls
        let currentFilter = 'all';
        document.getElementById('filterAllBtn').addEventListener('click', () => setFilterMode('all'));
        document.getElementById('filterTodayBtn').addEventListener('click', () => setFilterMode('today'));
        setFilterMode('all');

        // Render daily routes on main map
        renderDailyRoutes();

        document.querySelectorAll('.route-toggle-btn').forEach(btn => {
            const driverId = btn.getAttribute('data-driver-id');
            btn.addEventListener('click', () => toggleMainRoute(driverId));
            updateRouteToggleButton(driverId, true);
        });
    </script>
<?php require_once 'footer.php'; ?>

