<?php
require_once 'db.php';

function haversineDistanceKm($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371;
    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);
    $a = sin($latDelta / 2) * sin($latDelta / 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($lonDelta / 2) * sin($lonDelta / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

$message = '';
$messageType = '';
$selected_date = $_POST['order_date'] ?? $_GET['date'] ?? date('Y-m-d');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create_orders') {
            $order_date = $selected_date;
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
        } elseif ($_POST['action'] === 'unassign_driver') {
            $order_id = $_POST['order_id'] ?? 0;
            if ($order_id) {
                try {
                    $stmt = getDB()->prepare("UPDATE daily_orders SET driver_id = NULL, status = 'pending' WHERE id = ?");
                    $stmt->execute([$order_id]);
                    $message = "تم إلغاء تعيين السائق";
                    $messageType = "success";
                } catch (PDOException $e) {
                    $message = "خطأ في إلغاء التعيين: " . $e->getMessage();
                    $messageType = "danger";
                }
            }
        } elseif ($_POST['action'] === 'auto_assign') {
            $order_date = $selected_date;
            try {
                $factory = getDB()->query("SELECT * FROM factory LIMIT 1")->fetch();
                if (!$factory) {
                    throw new RuntimeException("يرجى تحديد موقع المصنع أولاً");
                }

                $driversStmt = getDB()->query("SELECT id, capacity FROM drivers WHERE is_active = 1 ORDER BY id");
                $drivers = $driversStmt->fetchAll();
                if (empty($drivers)) {
                    throw new RuntimeException("لا يوجد سائقين نشطين");
                }

                $ordersStmt = getDB()->prepare("
                    SELECT o.id, c.id AS customer_id, c.latitude, c.longitude
                    FROM daily_orders o
                    JOIN customers c ON o.customer_id = c.id
                    WHERE o.order_date = ? AND o.driver_id IS NULL
                ");
                $ordersStmt->execute([$order_date]);
                $unassignedOrders = $ordersStmt->fetchAll();

                if (empty($unassignedOrders)) {
                    $message = "لا توجد طلبات غير معينة للتوزيع";
                    $messageType = "info";
                } else {
                    $driverStates = [];
                    foreach ($drivers as $driver) {
                        $driverStates[] = [
                            'id' => (int) $driver['id'],
                            'capacity' => (int) $driver['capacity'],
                            'last_lat' => (float) $factory['latitude'],
                            'last_lng' => (float) $factory['longitude'],
                        ];
                    }

                    $assignments = [];
                    $remainingOrders = $unassignedOrders;

                    while (!empty($remainingOrders)) {
                        $madeAssignment = false;
                        foreach ($driverStates as $index => $state) {
                            if ($state['capacity'] <= 0 || empty($remainingOrders)) {
                                continue;
                            }

                            $nearestIndex = null;
                            $nearestDistance = null;
                            foreach ($remainingOrders as $orderIndex => $order) {
                                $distance = haversineDistanceKm(
                                    $state['last_lat'],
                                    $state['last_lng'],
                                    (float) $order['latitude'],
                                    (float) $order['longitude']
                                );
                                if ($nearestDistance === null || $distance < $nearestDistance) {
                                    $nearestDistance = $distance;
                                    $nearestIndex = $orderIndex;
                                }
                            }

                            if ($nearestIndex !== null) {
                                $selected = $remainingOrders[$nearestIndex];
                                $assignments[] = [
                                    'order_id' => (int) $selected['id'],
                                    'driver_id' => $state['id'],
                                ];
                                $driverStates[$index]['capacity'] -= 1;
                                $driverStates[$index]['last_lat'] = (float) $selected['latitude'];
                                $driverStates[$index]['last_lng'] = (float) $selected['longitude'];
                                unset($remainingOrders[$nearestIndex]);
                                $remainingOrders = array_values($remainingOrders);
                                $madeAssignment = true;
                            }
                        }

                        if (!$madeAssignment) {
                            break;
                        }
                    }

                    getDB()->beginTransaction();
                    $updateStmt = getDB()->prepare("UPDATE daily_orders SET driver_id = ?, status = 'assigned' WHERE id = ?");
                    foreach ($assignments as $assignment) {
                        $updateStmt->execute([$assignment['driver_id'], $assignment['order_id']]);
                    }
                    getDB()->commit();

                    $assignedCount = count($assignments);
                    $remainingCount = count($remainingOrders);
                    $message = "تم التوزيع التلقائي لـ {$assignedCount} طلب";
                    if ($remainingCount > 0) {
                        $message .= "، وبقي {$remainingCount} بدون سائق بسبب السعة";
                    }
                    $messageType = "success";
                }
            } catch (Throwable $e) {
                if (getDB()->inTransaction()) {
                    getDB()->rollBack();
                }
                $message = "خطأ في التوزيع التلقائي: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    }
}

// Get all customers
$customers = getDB()->query("SELECT * FROM customers ORDER BY name")->fetchAll();

// Get today's orders
$orders = getDB()->prepare("
    SELECT o.*, c.name as customer_name, c.address, c.latitude, c.longitude, c.phone,
           d.name as driver_name, d.capacity, d.color as driver_color
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
                                    <select class="form-select" id="customerSelect" name="customer_ids[]" multiple>
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?php echo $customer['id']; ?>" <?php echo in_array($customer['id'], $orderedCustomerIds) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($customer['name']); ?> - <?php echo htmlspecialchars($customer['address']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-4">
                                    <label class="form-label">العملاء المختارون</label>
                                    <div id="selectedCustomersList" class="border rounded p-2" style="max-height: 200px; overflow-y: auto;">
                                        <div class="text-muted small">لا يوجد عملاء مختارين</div>
                                    </div>
                                </div>
                                <div class="col-md-8 d-flex align-items-end">
                                    <div class="text-muted">
                                        عدد العملاء المختارين: <span id="selectedCustomersCount">0</span>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary mt-3">
                                <i class="bi bi-check-circle"></i> حفظ الطلبات
                            </button>
                        </form>
                        <form method="POST" class="mt-2">
                            <input type="hidden" name="action" value="auto_assign">
                            <input type="hidden" name="order_date" value="<?php echo $selected_date; ?>">
                            <button type="submit" class="btn btn-outline-primary">
                                <i class="bi bi-shuffle"></i> توزيع تلقائي حسب المسار
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
                                                        <?php if ($order['driver_id']): ?>
                                                            <form method="POST" class="d-inline" onsubmit="return confirm('هل تريد إلغاء تعيين السائق لهذا الطلب؟');">
                                                                <input type="hidden" name="action" value="unassign_driver">
                                                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-warning">
                                                                    <i class="bi bi-x-circle"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <form method="POST" class="d-inline">
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
                                        <button class="btn btn-sm btn-primary mt-2" id="routeToggleBtn-<?php echo $driverId; ?>" onclick="toggleRoute(<?php echo $driverId; ?>)">
                                            <i class="bi bi-map"></i> إخفاء المسار
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

    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        .select2-container {
            width: 100% !important;
        }

        .select2-container--default .select2-selection--multiple {
            min-height: 38px;
            padding-right: 28px;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__rendered {
            display: none;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__placeholder {
            display: block;
            color: #6c757d;
        }

        .select2-container--default .select2-selection--multiple {
            position: relative;
            cursor: pointer;
        }

        .select2-container--default .select2-selection--multiple::after {
            content: '';
            position: absolute;
            top: 50%;
            right: 10px;
            width: 0;
            height: 0;
            margin-top: -2px;
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
            border-top: 5px solid #6c757d;
            pointer-events: none;
        }

        .select2-dropdown {
            z-index: 2000;
        }

        #selectedCustomersList {
            direction: ltr;
            text-align: right;
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Enable searchable multi-select for customers
        $(document).ready(function() {
            $('#customerSelect').select2({
                placeholder: 'ابحث واختر العملاء',
                width: '100%'
            });
            updateSelectedCustomersList();
            $('#customerSelect').on('change', updateSelectedCustomersList);
        });

        function updateSelectedCustomersList() {
            const select = document.getElementById('customerSelect');
            const list = document.getElementById('selectedCustomersList');
            const count = document.getElementById('selectedCustomersCount');
            const selectedOptions = Array.from(select.selectedOptions);

            if (!list || !count) return;

            list.innerHTML = '';
            if (selectedOptions.length === 0) {
                list.innerHTML = '<div class="text-muted small">لا يوجد عملاء مختارين</div>';
                count.textContent = '0';
                return;
            }

            selectedOptions.forEach(option => {
                const item = document.createElement('div');
                item.className = 'border-bottom py-1 d-flex justify-content-between align-items-center';

                const label = document.createElement('span');
                label.textContent = option.text;
                item.appendChild(label);

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'btn btn-sm btn-light border';
                removeBtn.textContent = '×';
                removeBtn.title = 'إزالة من القائمة';
                removeBtn.addEventListener('click', () => {
                    option.selected = false;
                    $('#customerSelect').trigger('change');
                });
                item.appendChild(removeBtn);

                list.appendChild(item);
            });
            count.textContent = selectedOptions.length;
        }

        const factoryLocation = <?php echo $factory ? json_encode(['lat' => floatval($factory['latitude']), 'lng' => floatval($factory['longitude'])]) : 'null'; ?>;
        const ordersByDriver = <?php echo json_encode($ordersByDriver, JSON_UNESCAPED_UNICODE); ?>;
        const drivers = <?php echo json_encode($drivers, JSON_UNESCAPED_UNICODE); ?>;
        const todayOrders = <?php echo json_encode($todayOrders, JSON_UNESCAPED_UNICODE); ?>;
        const driverColorMap = {};
        drivers.forEach(driver => {
            if (driver.color) {
                driverColorMap[driver.id] = driver.color;
            }
        });
        
        let routeMap;
        const routeRenderers = {};
        const orderMarkers = [];
        const orderInfoWindow = new google.maps.InfoWindow();

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

            renderOrderMarkers();

            // Show all routes by default
            showAllRoutes();
        }

        function showAllRoutes() {
            clearRoutes();
            Object.keys(ordersByDriver).forEach(driverId => {
                if (driverId === 'unassigned') return;
                
                const driverOrders = ordersByDriver[driverId];
                if (driverOrders.length === 0) return;
                renderDriverRoute(driverId, driverOrders, getDriverColor(driverId));
                setToggleState(driverId, true);
            });
        }

        function toggleRoute(driverId) {
            if (!ordersByDriver[driverId] || ordersByDriver[driverId].length === 0) return;
            const isVisible = !!routeRenderers[driverId];
            if (isVisible) {
                hideDriverRoute(driverId);
                setToggleState(driverId, false);
            } else {
                renderDriverRoute(driverId, ordersByDriver[driverId], getDriverColor(driverId));
                setToggleState(driverId, true);
            }
        }

        function renderDriverRoute(driverId, driverOrders, color) {
            const waypoints = driverOrders.map(order => ({
                location: { lat: parseFloat(order.latitude), lng: parseFloat(order.longitude) },
                stopover: true
            }));

            const directionsService = new google.maps.DirectionsService();
            const directionsRenderer = new google.maps.DirectionsRenderer({
                map: routeMap,
                suppressMarkers: true,
                polylineOptions: {
                    strokeColor: color,
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
                    directionsRenderer.setDirections(result);
                    routeRenderers[driverId] = directionsRenderer;
                }
            });
        }

        function renderOrderMarkers() {
            orderMarkers.forEach(marker => marker.setMap(null));
            orderMarkers.length = 0;

            todayOrders.forEach(order => {
                const isAssigned = !!order.driver_id;
                const marker = new google.maps.Marker({
                    position: { lat: parseFloat(order.latitude), lng: parseFloat(order.longitude) },
                    map: routeMap,
                    title: order.customer_name,
                    icon: {
                        url: isAssigned
                            ? 'http://maps.google.com/mapfiles/ms/icons/blue-dot.png'
                            : 'http://maps.google.com/mapfiles/ms/icons/gray-dot.png'
                    }
                });
                marker.addListener('click', () => {
                    orderInfoWindow.setContent(`
                        <div>
                            <strong>${order.customer_name}</strong><br>
                            ${order.phone ? '📞 ' + order.phone + '<br>' : ''}
                            ${order.address}<br>
                            <small>السائق: ${order.driver_name ? order.driver_name : 'غير معين'}</small>
                        </div>
                    `);
                    orderInfoWindow.open(routeMap, marker);
                });
                orderMarkers.push(marker);
            });
        }

        function hideDriverRoute(driverId) {
            if (routeRenderers[driverId]) {
                routeRenderers[driverId].setMap(null);
                delete routeRenderers[driverId];
            }
        }

        function clearRoutes() {
            Object.keys(routeRenderers).forEach(driverId => hideDriverRoute(driverId));
            Object.keys(ordersByDriver).forEach(driverId => {
                if (driverId !== 'unassigned') {
                    setToggleState(driverId, false);
                }
            });
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

        function setToggleState(driverId, isVisible) {
            const btn = document.getElementById(`routeToggleBtn-${driverId}`);
            if (!btn) return;
            btn.classList.toggle('btn-primary', isVisible);
            btn.classList.toggle('btn-outline-primary', !isVisible);
            btn.innerHTML = isVisible ? '<i class="bi bi-map"></i> إخفاء المسار' : '<i class="bi bi-map"></i> عرض المسار';
        }

        google.maps.event.addDomListener(window, 'load', initRouteMap);
    </script>
<?php require_once 'footer.php'; ?>
