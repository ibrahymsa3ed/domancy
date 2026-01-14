<?php
require_once 'db.php';

// Initialize variables
$factoryLat = 30.0444; // Default Cairo coordinates
$factoryLng = 31.2357;
$customers = [];
$todayOrderIds = [];

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

        customerData.forEach(customer => {
            const markerColor = customer.has_order_today ? 'green' : 'gray';
            const marker = new google.maps.Marker({
                position: { lat: parseFloat(customer.latitude), lng: parseFloat(customer.longitude) },
                map: map,
                title: customer.name,
                icon: {
                    url: `http://maps.google.com/mapfiles/ms/icons/${markerColor}-dot.png`
                },
                visible: true
            });

            const infoWindow = new google.maps.InfoWindow({
                content: `
                    <div>
                        <strong>${customer.name}</strong><br>
                        ${customer.phone ? '📞 ' + customer.phone + '<br>' : ''}
                        ${customer.address}<br>
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

        // Filter controls
        document.getElementById('showAllCustomers').addEventListener('change', function() {
            const show = this.checked;
            customerMarkers.forEach(item => {
                item.marker.setVisible(show);
            });
        });

        document.getElementById('showTodayOrders').addEventListener('change', function() {
            const show = this.checked;
            customerMarkers.forEach(item => {
                item.marker.setVisible(show && item.hasOrder);
            });
        });
    </script>
<?php require_once 'footer.php'; ?>
