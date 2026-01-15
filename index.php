<?php
require_once 'db.php';

// Initialize variables
$factoryLat = 30.0444; // Default Cairo coordinates
$factoryLng = 31.2357;
$customers = [];
$todayOrderIds = [];
$todayOrdersByDriver = [];

try {
    // Get factory location
    $factory = getDB()->query("SELECT * FROM factory LIMIT 1")->fetch();
    if ($factory) {
        $factoryLat = $factory['latitude'];
        $factoryLng = $factory['longitude'];
    }

    // Get all customers
    $customers = getDB()->query("SELECT * FROM customers ORDER BY name")->fetchAll();

    // Get today's orders
    $today = date('Y-m-d');
    $orders = getDB()->prepare("SELECT customer_id FROM daily_orders WHERE order_date = ?");
    $orders->execute([$today]);
    $todayOrderIds = $orders->fetchAll(PDO::FETCH_COLUMN);

    // Get today's orders with driver routes
    $routesStmt = getDB()->prepare("
        SELECT o.driver_id, c.name, c.address, c.latitude, c.longitude, c.phone, d.name AS driver_name
        FROM daily_orders o
        JOIN customers c ON o.customer_id = c.id
        LEFT JOIN drivers d ON o.driver_id = d.id
        WHERE o.order_date = ? AND o.driver_id IS NOT NULL
        ORDER BY o.driver_id, o.id
    ");
    $routesStmt->execute([$today]);
    $routeRows = $routesStmt->fetchAll();
    foreach ($routeRows as $row) {
        $driverId = $row['driver_id'];
        if (!isset($todayOrdersByDriver[$driverId])) {
            $todayOrdersByDriver[$driverId] = [];
        }
        $todayOrdersByDriver[$driverId][] = $row;
    }

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
}

// Set variables for header
$pageTitle = APP_NAME . ' - الخريطة';
$googleMapsScript = 'places,geometry';
require_once 'header.php';
?>

    <div class="container-fluid mt-3">
        <div class="row">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-filter"></i> الفلاتر</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="showAllCustomers" checked>
                            <label class="form-check-label" for="showAllCustomers">
                                عرض جميع العملاء
                            </label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="showTodayOrders" checked>
                            <label class="form-check-label" for="showTodayOrders">
                                عرض طلبات اليوم فقط
                            </label>
                        </div>
                        <div class="btn-group w-100 mb-3" role="group" aria-label="تصفية سريعة">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="filterAllBtn">الكل</button>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="filterTodayBtn">طلبات اليوم</button>
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

        customerData.forEach(customer => {
            const markerColor = customer.has_order_today ? 'green' : 'gray';
            const lat = parseFloat(customer.latitude);
            const lng = parseFloat(customer.longitude);
            const hasValidCoords = Number.isFinite(lat) && Number.isFinite(lng);
            const position = hasValidCoords ? { lat, lng } : factoryLocation;
            const marker = new google.maps.Marker({
                position: position,
                map: map,
                title: customer.name,
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: 6,
                    fillColor: markerColor === 'green' ? '#28a745' : '#6c757d',
                    fillOpacity: 1,
                    strokeColor: '#ffffff',
                    strokeWeight: 1
                },
                visible: true
            });

            const infoWindow = new google.maps.InfoWindow({
                content: `
                    <div>
                        <strong>${customer.name}</strong><br>
                        ${customer.phone ? '📞 ' + customer.phone + '<br>' : ''}
                        ${customer.address}<br>
                        ${hasValidCoords ? '' : '<small class="text-muted">⚠️ بدون إحداثيات دقيقة</small><br>'}
                        ${customer.has_order_today ? '<span class="badge bg-success">طلبات اليوم</span>' : ''}
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

        const dailyRouteRenderers = {};
        function renderDailyRoutes() {
            const directionsService = new google.maps.DirectionsService();
            Object.keys(todayOrdersByDriver).forEach(driverId => {
                const driverOrders = todayOrdersByDriver[driverId];
                if (!driverOrders || driverOrders.length === 0) return;

                const waypoints = driverOrders.map(order => ({
                    location: { lat: parseFloat(order.latitude), lng: parseFloat(order.longitude) },
                    stopover: true
                }));

                const renderer = new google.maps.DirectionsRenderer({
                    map: map,
                    suppressMarkers: true,
                    polylineOptions: {
                        strokeColor: getDriverColor(driverId),
                        strokeWeight: 3
                    }
                });

                const request = {
                    origin: factoryLocation,
                    destination: factoryLocation,
                    waypoints: waypoints,
                    optimizeWaypoints: true,
                    travelMode: google.maps.TravelMode.DRIVING
                };

                directionsService.route(request, (result, status) => {
                    if (status === 'OK') {
                        renderer.setDirections(result);
                        dailyRouteRenderers[driverId] = renderer;
                    }
                });
            });
        }

        function getDriverColor(driverId) {
            const colors = ['#FF6B6B', '#4ECDC4', '#45B7D1', '#FFD93D', '#A66DD4', '#F4A261'];
            const idNum = parseInt(driverId, 10) || 0;
            return colors[idNum % colors.length];
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
            const showAll = document.getElementById('showAllCustomers').checked;
            const showToday = document.getElementById('showTodayOrders').checked;

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
            const showAllEl = document.getElementById('showAllCustomers');
            const showTodayEl = document.getElementById('showTodayOrders');
            if (mode === 'all') {
                showAllEl.checked = true;
                showTodayEl.checked = false;
            } else if (mode === 'today') {
                showAllEl.checked = false;
                showTodayEl.checked = true;
            }
            applyCustomerFilters();
        }

        // Filter controls
        document.getElementById('showAllCustomers').addEventListener('change', applyCustomerFilters);
        document.getElementById('showTodayOrders').addEventListener('change', applyCustomerFilters);
        document.getElementById('filterAllBtn').addEventListener('click', () => setFilterMode('all'));
        document.getElementById('filterTodayBtn').addEventListener('click', () => setFilterMode('today'));
        setFilterMode('all');

        // Render daily routes on main map
        renderDailyRoutes();
    </script>
<?php require_once 'footer.php'; ?>
