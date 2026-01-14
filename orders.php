<?php
require_once 'db.php';

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create_orders') {
            $order_date = $_POST['order_date'] ?? date('Y-m-d');
            $customer_ids = $_POST['customer_ids'] ?? [];

            if (!empty($customer_ids)) {
                try {
                    getDB()->beginTransaction();
                    
                    // Delete existing orders for this date
                    $stmt = getDB()->prepare("DELETE FROM daily_orders WHERE order_date = ?");
                    $stmt->execute([$order_date]);

                    // Insert new orders
                    $stmt = getDB()->prepare("INSERT INTO daily_orders (order_date, customer_id, status) VALUES (?, ?, 'pending')");
                    foreach ($customer_ids as $customer_id) {
                        $stmt->execute([$order_date, $customer_id]);
                    }

                    getDB()->commit();
                    $message = "تم إنشاء الطلبات بنجاح";
                    $messageType = "success";
                } catch (PDOException $e) {
                    getDB()->rollBack();
                    $message = "خطأ في إنشاء الطلبات: " . $e->getMessage();
                    $messageType = "danger";
                }
            }
        } elseif ($_POST['action'] === 'assign_driver') {
            $order_id = $_POST['order_id'] ?? 0;
            $driver_id = $_POST['driver_id'] ?? 0;

            if ($order_id && $driver_id) {
                try {
                    $stmt = getDB()->prepare("UPDATE daily_orders SET driver_id = ?, status = 'assigned' WHERE id = ?");
                    $stmt->execute([$driver_id, $order_id]);
                    $message = "تم تعيين السائق بنجاح";
                    $messageType = "success";
                } catch (PDOException $e) {
                    $message = "خطأ في تعيين السائق: " . $e->getMessage();
                    $messageType = "danger";
                }
            }
        } elseif ($_POST['action'] === 'remove_order') {
            $order_id = $_POST['order_id'] ?? 0;
            if ($order_id) {
                try {
                    $stmt = getDB()->prepare("DELETE FROM daily_orders WHERE id = ?");
                    $stmt->execute([$order_id]);
                    $message = "تم حذف الطلب بنجاح";
                    $messageType = "success";
                } catch (PDOException $e) {
                    $message = "خطأ في حذف الطلب";
                    $messageType = "danger";
                }
            }
        }
    }
}

// Get selected date
$selected_date = $_GET['date'] ?? date('Y-m-d');

// Get all customers
$customers = getDB()->query("SELECT * FROM customers ORDER BY name")->fetchAll();

// Get today's orders
$orders = getDB()->prepare("
    SELECT o.*, c.name as customer_name, c.address, c.latitude, c.longitude, 
           d.name as driver_name, d.capacity
    FROM daily_orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    LEFT JOIN drivers d ON o.driver_id = d.id
    WHERE o.order_date = ?
    ORDER BY o.driver_id, o.id
");
$orders->execute([$selected_date]);
$todayOrders = $orders->fetchAll();

// Get active drivers
$drivers = getDB()->query("SELECT * FROM drivers WHERE is_active = 1 ORDER BY name")->fetchAll();

// Get customers with orders today
$orderedCustomerIds = array_column($todayOrders, 'customer_id');

// Group orders by driver
$ordersByDriver = [];
foreach ($todayOrders as $order) {
    $driverId = $order['driver_id'] ?? 'unassigned';
    if (!isset($ordersByDriver[$driverId])) {
        $ordersByDriver[$driverId] = [];
    }
    $ordersByDriver[$driverId][] = $order;
}

// Get factory location
$factory = getDB()->query("SELECT * FROM factory LIMIT 1")->fetch();

// Set variables for header
$pageTitle = APP_NAME . ' - الطلبات اليومية';
$googleMapsScript = 'places,geometry';
require_once 'header.php';
?>

    <div class="container-fluid mt-4">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row mb-3">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-calendar"></i> إنشاء طلبات يومية</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="createOrdersForm">
                            <input type="hidden" name="action" value="create_orders">
                            <div class="row">
                                <div class="col-md-3">
                                    <label class="form-label">التاريخ</label>
                                    <input type="date" class="form-control" name="order_date" value="<?php echo $selected_date; ?>" required>
                                </div>
                                <div class="col-md-9">
                                    <label class="form-label">اختر العملاء</label>
                                    <div class="border p-3" style="max-height: 200px; overflow-y: auto;">
                                        <?php foreach ($customers as $customer): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="customer_ids[]" 
                                                       value="<?php echo $customer['id']; ?>" 
                                                       id="customer_<?php echo $customer['id']; ?>"
                                                       <?php echo in_array($customer['id'], $orderedCustomerIds) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="customer_<?php echo $customer['id']; ?>">
                                                    <?php echo htmlspecialchars($customer['name']); ?> - <?php echo htmlspecialchars($customer['address']); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary mt-3">
                                <i class="bi bi-check-circle"></i> حفظ الطلبات
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-list-check"></i> طلبات اليوم (<?php echo count($todayOrders); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($todayOrders)): ?>
                            <p class="text-muted text-center">لا توجد طلبات</p>
                        <?php else: ?>
                            <?php foreach ($ordersByDriver as $driverId => $driverOrders): ?>
                                <div class="mb-4">
                                    <h6 class="border-bottom pb-2">
                                        <?php if ($driverId === 'unassigned'): ?>
                                            <span class="badge bg-warning">غير معين</span>
                                        <?php else: ?>
                                            <span class="badge bg-info">
                                                <?php 
                                                $driver = array_filter($drivers, fn($d) => $d['id'] == $driverId);
                                                $driver = reset($driver);
                                                echo htmlspecialchars($driver ? $driver['name'] : 'غير معين');
                                                ?>
                                                (<?php echo count($driverOrders); ?>/<?php echo $driver ? $driver['capacity'] : '?'; ?>)
                                            </span>
                                        <?php endif; ?>
                                    </h6>
                                    <ul class="list-group">
                                        <?php foreach ($driverOrders as $order): ?>
                                            <li class="list-group-item">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($order['customer_name']); ?></strong><br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($order['address']); ?></small>
                                                    </div>
                                                    <div>
                                                        <?php if (!$order['driver_id']): ?>
                                                            <form method="POST" class="d-inline">
                                                                <input type="hidden" name="action" value="assign_driver">
                                                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                                <select name="driver_id" class="form-select form-select-sm d-inline-block" style="width: auto;" onchange="this.form.submit()">
                                                                    <option value="">اختر سائق</option>
                                                                    <?php foreach ($drivers as $driver): ?>
                                                                        <option value="<?php echo $driver['id']; ?>">
                                                                            <?php echo htmlspecialchars($driver['name']); ?> (<?php echo $driver['capacity']; ?>)
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </form>
                                                        <?php endif; ?>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا الطلب؟');">
                                                            <input type="hidden" name="action" value="remove_order">
                                                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-danger">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php if ($driverId !== 'unassigned' && $factory): ?>
                                        <button class="btn btn-sm btn-primary mt-2" onclick="showRoute(<?php echo $driverId; ?>)">
                                            <i class="bi bi-map"></i> عرض المسار
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-map"></i> خريطة المسارات</h5>
                    </div>
                    <div class="card-body">
                        <div id="routeMap" style="height: 600px; width: 100%;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const factoryLocation = <?php echo $factory ? json_encode(['lat' => floatval($factory['latitude']), 'lng' => floatval($factory['longitude'])]) : 'null'; ?>;
        const ordersByDriver = <?php echo json_encode($ordersByDriver, JSON_UNESCAPED_UNICODE); ?>;
        const drivers = <?php echo json_encode($drivers, JSON_UNESCAPED_UNICODE); ?>;
        
        let routeMap;
        let routeMarkers = [];
        let routePolylines = [];

        function initRouteMap() {
            if (!factoryLocation) {
                document.getElementById('routeMap').innerHTML = '<div class="alert alert-warning">يرجى تحديد موقع المصنع أولاً</div>';
                return;
            }

            routeMap = new google.maps.Map(document.getElementById('routeMap'), {
                zoom: 10,
                center: factoryLocation,
                mapTypeControl: true
            });

            // Add factory marker
            new google.maps.Marker({
                position: factoryLocation,
                map: routeMap,
                title: 'دومانسي',
                icon: { url: 'http://maps.google.com/mapfiles/ms/icons/red-dot.png' }
            });

            // Show all routes by default
            showAllRoutes();
        }

        function showAllRoutes() {
            clearRoutes();
            const directionsService = new google.maps.DirectionsService();
            const directionsRenderer = new google.maps.DirectionsRenderer({ map: routeMap });

            Object.keys(ordersByDriver).forEach(driverId => {
                if (driverId === 'unassigned') return;
                
                const driverOrders = ordersByDriver[driverId];
                if (driverOrders.length === 0) return;

                const waypoints = driverOrders.map(order => ({
                    location: { lat: parseFloat(order.latitude), lng: parseFloat(order.longitude) },
                    stopover: true
                }));

                const request = {
                    origin: factoryLocation,
                    destination: factoryLocation,
                    waypoints: waypoints,
                    optimizeWaypoints: true,
                    travelMode: google.maps.TravelMode.DRIVING
                };

                directionsService.route(request, (result, status) => {
                    if (status === 'OK') {
                        const renderer = new google.maps.DirectionsRenderer({
                            map: routeMap,
                            directions: result,
                            suppressMarkers: false,
                            polylineOptions: {
                                strokeColor: getRandomColor(),
                                strokeWeight: 3
                            }
                        });
                        routePolylines.push(renderer);
                    }
                });
            });
        }

        function showRoute(driverId) {
            clearRoutes();
            if (!ordersByDriver[driverId] || ordersByDriver[driverId].length === 0) return;

            const driverOrders = ordersByDriver[driverId];
            const waypoints = driverOrders.map(order => ({
                location: { lat: parseFloat(order.latitude), lng: parseFloat(order.longitude) },
                stopover: true
            }));

            const directionsService = new google.maps.DirectionsService();
            const directionsRenderer = new google.maps.DirectionsRenderer({ map: routeMap });

            const request = {
                origin: factoryLocation,
                destination: factoryLocation,
                waypoints: waypoints,
                optimizeWaypoints: true,
                travelMode: google.maps.TravelMode.DRIVING
            };

            directionsService.route(request, (result, status) => {
                if (status === 'OK') {
                    directionsRenderer.setDirections(result);
                }
            });
        }

        function clearRoutes() {
            routePolylines.forEach(renderer => renderer.setMap(null));
            routePolylines = [];
        }

        function getRandomColor() {
            const colors = ['#FF0000', '#00FF00', '#0000FF', '#FFFF00', '#FF00FF', '#00FFFF'];
            return colors[Math.floor(Math.random() * colors.length)];
        }

        google.maps.event.addDomListener(window, 'load', initRouteMap);
    </script>
<?php require_once 'footer.php'; ?>
