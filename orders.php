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

function drivingDistanceKm($originLat, $originLng, $destLat, $destLng, $apiKey) {
    static $cache = [];
    $key = $originLat . ',' . $originLng . '|' . $destLat . ',' . $destLng;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    if (empty($apiKey)) {
        $cache[$key] = haversineDistanceKm($originLat, $originLng, $destLat, $destLng);
        return $cache[$key];
    }

    $url = 'https://maps.googleapis.com/maps/api/directions/json?' . http_build_query([
        'origin' => $originLat . ',' . $originLng,
        'destination' => $destLat . ',' . $destLng,
        'mode' => 'driving',
        'key' => $apiKey,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false || $err) {
        $cache[$key] = haversineDistanceKm($originLat, $originLng, $destLat, $destLng);
        return $cache[$key];
    }

    $data = json_decode($response, true);
    if (isset($data['routes'][0]['legs'][0]['distance']['value'])) {
        $km = $data['routes'][0]['legs'][0]['distance']['value'] / 1000;
        $cache[$key] = $km;
        return $km;
    }

    $cache[$key] = haversineDistanceKm($originLat, $originLng, $destLat, $destLng);
    return $cache[$key];
}

function minRouteDistanceKm($points, $lat, $lng) {
    if (empty($points)) {
        return null;
    }
    $best = null;
    foreach ($points as $pt) {
        $dist = haversineDistanceKm($pt['lat'], $pt['lng'], $lat, $lng);
        if ($best === null || $dist < $best) {
            $best = $dist;
        }
    }
    return $best;
}

function extractTown($address, $town = null) {
    if (!empty($town)) {
        return normalizeLocationName($town);
    }
    $address = trim((string) $address);
    if ($address === '') {
        return 'unknown';
    }
    $parts = preg_split('/[,،\-–\|]+/', $address);
    $parts = array_values(array_filter(array_map('trim', $parts)));
    if (empty($parts)) {
        return 'unknown';
    }
    return normalizeLocationName($parts[count($parts) - 1]);
}

function normalizeLocationName($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $value = mb_strtolower($value);
    $value = str_replace(['أ', 'إ', 'آ'], 'ا', $value);
    $value = str_replace(['ى'], 'ي', $value);
    $value = str_replace(['ة'], 'ه', $value);
    $value = str_replace(['ـ'], '', $value);
    $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $value);
    $value = str_replace(['محافظة', 'مدينة', 'مركز', 'قسم', 'حي', 'ال'], ['', '', '', '', '', ''], $value);
    $value = preg_replace('/[^\p{Arabic}a-z0-9\s]+/u', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return trim($value);
}

function computeCentroid($orders) {
    $count = count($orders);
    if ($count === 0) {
        return null;
    }
    $sumLat = 0.0;
    $sumLng = 0.0;
    foreach ($orders as $order) {
        $sumLat += (float) $order['latitude'];
        $sumLng += (float) $order['longitude'];
    }
    return [
        'lat' => $sumLat / $count,
        'lng' => $sumLng / $count,
    ];
}

function nearestNeighborOrder($orders, $startLat, $startLng) {
    $remaining = $orders;
    $ordered = [];
    $currentLat = $startLat;
    $currentLng = $startLng;
    while (!empty($remaining)) {
        $bestKey = null;
        $bestDist = null;
        foreach ($remaining as $key => $order) {
            $dist = haversineDistanceKm($currentLat, $currentLng, (float) $order['latitude'], (float) $order['longitude']);
            if ($bestDist === null || $dist < $bestDist) {
                $bestDist = $dist;
                $bestKey = $key;
            }
        }
        if ($bestKey === null) {
            break;
        }
        $selected = $remaining[$bestKey];
        unset($remaining[$bestKey]);
        $remaining = array_values($remaining);
        $ordered[] = $selected;
        $currentLat = (float) $selected['latitude'];
        $currentLng = (float) $selected['longitude'];
    }
    return $ordered;
}

function extractGovernorate($address, $governorate = null) {
    if (!empty($governorate)) {
        return normalizeLocationName($governorate);
    }
    $address = trim((string) $address);
    if ($address === '') {
        return 'unknown';
    }
    if (preg_match('/محافظة\s+([^\s،,]+)/u', $address, $matches)) {
        return normalizeLocationName($matches[1]);
    }
    if (preg_match('/([a-z\s]+)\s+governorate/i', $address, $matches)) {
        return normalizeLocationName($matches[1]);
    }
    $parts = preg_split('/[,،\-–\|]+/', $address);
    $parts = array_values(array_filter(array_map('trim', $parts)));
    if (empty($parts)) {
        return 'unknown';
    }
    return normalizeLocationName($parts[count($parts) - 1]);
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
                    
                    // Load existing orders for this date
                    $existingStmt = getDB()->prepare("SELECT id, customer_id, driver_id, status FROM daily_orders WHERE order_date = ?");
                    $existingStmt->execute([$order_date]);
                    $existingOrders = $existingStmt->fetchAll();

                    $selectedIds = array_map('intval', $customer_ids);
                    $selectedMap = array_flip($selectedIds);

                    // Delete orders removed from selection
                    $deleteStmt = getDB()->prepare("DELETE FROM daily_orders WHERE id = ?");
                    foreach ($existingOrders as $order) {
                        if (!isset($selectedMap[(int) $order['customer_id']])) {
                            $deleteStmt->execute([$order['id']]);
                        }
                    }

                    // Insert new orders that don't exist yet
                    $existingCustomerMap = [];
                    foreach ($existingOrders as $order) {
                        $existingCustomerMap[(int) $order['customer_id']] = true;
                    }

                    $insertStmt = getDB()->prepare("INSERT INTO daily_orders (order_date, customer_id, status) VALUES (?, ?, 'pending')");
                    foreach ($selectedIds as $customer_id) {
                        if (!isset($existingCustomerMap[$customer_id])) {
                            $insertStmt->execute([$order_date, $customer_id]);
                        }
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

                $driversStmt = getDB()->query("SELECT id, name, capacity, governorate FROM drivers WHERE is_active = 1 ORDER BY id");
                $drivers = $driversStmt->fetchAll();
                if (empty($drivers)) {
                    throw new RuntimeException("لا يوجد سائقين نشطين");
                }
                $nearTownKm = 5.0;
                if ($nearTownKm < 0) {
                    $nearTownKm = 0;
                }

                $selectedDriverIds = array_map('intval', $_POST['driver_ids'] ?? []);
                if (!empty($selectedDriverIds)) {
                    $selectedMap = array_flip($selectedDriverIds);
                    $drivers = array_values(array_filter($drivers, function($driver) use ($selectedMap) {
                        return isset($selectedMap[(int) $driver['id']]);
                    }));
                    if (empty($drivers)) {
                        throw new RuntimeException("يرجى اختيار سائقين للتوزيع");
                    }
                }

                $driversCount = count($drivers);
                $carsCount = isset($_POST['cars_count']) ? (int) $_POST['cars_count'] : $driversCount;
                if ($carsCount < 1) {
                    $carsCount = 1;
                }
                if ($carsCount > $driversCount) {
                    $carsCount = $driversCount;
                }
                if ($carsCount < $driversCount) {
                    $drivers = array_slice($drivers, 0, $carsCount);
                }

                $redistributeSelected = isset($_POST['redistribute_selected']) && $_POST['redistribute_selected'] === '1';
                if ($redistributeSelected && !empty($selectedDriverIds)) {
                    $placeholders = implode(',', array_fill(0, count($selectedDriverIds), '?'));
                    $params = array_merge([$order_date], $selectedDriverIds);
                    $resetStmt = getDB()->prepare("UPDATE daily_orders SET driver_id = NULL, status = 'pending' WHERE order_date = ? AND driver_id IN ($placeholders)");
                    $resetStmt->execute($params);
                }

                $ordersStmt = getDB()->prepare("
                    SELECT o.id, c.id AS customer_id, c.latitude, c.longitude, c.address, c.town, c.governorate
                    FROM daily_orders o
                    JOIN customers c ON o.customer_id = c.id
                    WHERE o.order_date = ? AND o.driver_id IS NULL
                ");
                $ordersStmt->execute([$order_date]);
                $unassignedOrders = $ordersStmt->fetchAll();

                $assignedStmt = getDB()->prepare("
                    SELECT o.id, o.driver_id, c.latitude, c.longitude, c.address, c.town, c.governorate
                    FROM daily_orders o
                    JOIN customers c ON o.customer_id = c.id
                    WHERE o.order_date = ? AND o.driver_id IS NOT NULL
                ");
                $assignedStmt->execute([$order_date]);
                $assignedOrders = $assignedStmt->fetchAll();

                if (empty($unassignedOrders)) {
                    $message = "لا توجد طلبات غير معينة للتوزيع";
                    $messageType = "info";
                } else {
                    $driverStates = [];
                    $driverTowns = [];
                    $assignedCountByDriver = [];
                    $driverRoutePoints = [];
                    $driverAssignedRun = [];
                    $driverIdSet = [];
                    $townClusters = [];
                    $townCentroids = [];
                    $townAssignments = [];
                    $debugLines = [];
                    $useGovernorateFilter = false;

                    foreach ($drivers as $driver) {
                        $driverId = (int) $driver['id'];
                        $driverIdSet[$driverId] = true;
                        $driverAssignedRun[$driverId] = 0;
                    }

                    foreach ($assignedOrders as $order) {
                        $driverId = (int) $order['driver_id'];
                        if (!isset($driverIdSet[$driverId])) {
                            continue;
                        }
                        if (!isset($assignedCountByDriver[$driverId])) {
                            $assignedCountByDriver[$driverId] = 0;
                        }
                        $assignedCountByDriver[$driverId] += 1;

                        $town = extractTown($order['address'] ?? '', $order['town'] ?? null);
                        if (!isset($driverTowns[$driverId])) {
                            $driverTowns[$driverId] = [];
                        }
                        $driverTowns[$driverId][$town] = true;

                        if (!isset($driverRoutePoints[$driverId])) {
                            $driverRoutePoints[$driverId] = [];
                        }
                        $driverRoutePoints[$driverId][] = [
                            'lat' => (float) $order['latitude'],
                            'lng' => (float) $order['longitude'],
                        ];
                    }

                    foreach ($drivers as $driver) {
                        $driverId = (int) $driver['id'];
                        if (!isset($driverIdSet[$driverId])) {
                            continue;
                        }
                        $assignedCount = $assignedCountByDriver[$driverId] ?? 0;
                        $remainingCapacity = max(0, (int) $driver['capacity'] - $assignedCount);

                        $routePoints = $driverRoutePoints[$driverId] ?? [];
                        if (empty($routePoints)) {
                            $routePoints[] = [
                                'lat' => (float) $factory['latitude'],
                                'lng' => (float) $factory['longitude'],
                            ];
                        }
                        $lastPoint = end($routePoints);
                        $driverRoutePoints[$driverId] = $routePoints;

                        $driverStates[$driverId] = [
                            'id' => $driverId,
                            'capacity' => $remainingCapacity,
                            'last_lat' => (float) $lastPoint['lat'],
                            'last_lng' => (float) $lastPoint['lng'],
                        ];
                    }

                    foreach ($unassignedOrders as $order) {
                        $town = extractTown($order['address'] ?? '', $order['town'] ?? null);
                        $order['town_norm'] = $town;
                        $townClusters[$town][] = $order;
                    }

                    foreach ($townClusters as $town => $ordersInTown) {
                        $centroid = computeCentroid($ordersInTown);
                        if ($centroid) {
                            $townCentroids[$town] = $centroid;
                        }
                    }

                    $debugLines[] = 'عدد المدن: ' . count($townClusters);

                    $townSizes = [];
                    foreach ($townClusters as $town => $ordersInTown) {
                        $townSizes[$town] = count($ordersInTown);
                    }
                    arsort($townSizes);

                    foreach ($townSizes as $town => $size) {
                        $candidateDrivers = array_keys($driverIdSet);
                        $centroid = $townCentroids[$town] ?? null;
                        if (!$centroid) {
                            continue;
                        }

                        $bestDriver = null;
                        $bestScore = null;
                        foreach ($candidateDrivers as $driverId) {
                            if (($driverStates[$driverId]['capacity'] ?? 0) <= 0) {
                                continue;
                            }
                            $ownsTown = isset($driverTowns[$driverId][$town]);

                            $allowedByNear = true;
                            if (!empty($driverTowns[$driverId]) && $nearTownKm >= 0) {
                                $allowedByNear = false;
                                foreach (array_keys($driverTowns[$driverId]) as $ownedTown) {
                                    if (!isset($townCentroids[$ownedTown])) {
                                        continue;
                                    }
                                    $dist = haversineDistanceKm(
                                        $townCentroids[$ownedTown]['lat'],
                                        $townCentroids[$ownedTown]['lng'],
                                        $centroid['lat'],
                                        $centroid['lng']
                                    );
                                    if ($dist <= $nearTownKm) {
                                        $allowedByNear = true;
                                        break;
                                    }
                                }
                                if ($nearTownKm === 0 && !$ownsTown) {
                                    $allowedByNear = false;
                                }
                            }

                            if (!$allowedByNear) {
                                continue;
                            }

                            $routePoints = $driverRoutePoints[$driverId] ?? [];
                            $routeDistance = minRouteDistanceKm($routePoints, $centroid['lat'], $centroid['lng']);
                            $distanceScore = $routeDistance !== null ? $routeDistance : 0;
                            $fairnessPenalty = ($driverAssignedRun[$driverId] ?? 0) * 0.5;
                            $townBonus = $ownsTown ? -5 : 0;
                            $score = $distanceScore + $fairnessPenalty + $townBonus;

                            if ($bestScore === null || $score < $bestScore) {
                                $bestScore = $score;
                                $bestDriver = $driverId;
                            }
                        }

                        if ($bestDriver !== null) {
                            $townAssignments[$town] = $bestDriver;
                            $driverTowns[$bestDriver][$town] = true;
                            $driverAssignedRun[$bestDriver] = ($driverAssignedRun[$bestDriver] ?? 0) + 1;
                        }
                    }

                    $debugTownAssignments = [];
                    foreach ($townAssignments as $town => $driverId) {
                        $debugTownAssignments[] = $town . '→' . $driverId;
                    }
                    if (!empty($debugTownAssignments)) {
                        $debugLines[] = 'توزيع المدن: ' . implode('، ', $debugTownAssignments);
                    }

                    $assignments = [];
                    $capacityLeftCount = 0;
                    $noNearTownCount = 0;

                    foreach ($townClusters as $town => $ordersInTown) {
                        if (!isset($townAssignments[$town])) {
                            $fallbackDriver = null;
                            $fallbackScore = null;
                            $centroid = $townCentroids[$town] ?? null;
                            if ($centroid) {
                                foreach (array_keys($driverIdSet) as $driverId) {
                                    if (($driverStates[$driverId]['capacity'] ?? 0) <= 0) {
                                        continue;
                                    }
                                    $routePoints = $driverRoutePoints[$driverId] ?? [];
                                    $distanceScore = minRouteDistanceKm($routePoints, $centroid['lat'], $centroid['lng']);
                                    if ($distanceScore === null) {
                                        $distanceScore = haversineDistanceKm(
                                            $driverStates[$driverId]['last_lat'],
                                            $driverStates[$driverId]['last_lng'],
                                            $centroid['lat'],
                                            $centroid['lng']
                                        );
                                    }
                                    if ($fallbackScore === null || $distanceScore < $fallbackScore) {
                                        $fallbackScore = $distanceScore;
                                        $fallbackDriver = $driverId;
                                    }
                                }
                            }
                            if ($fallbackDriver === null) {
                                $noNearTownCount += count($ordersInTown);
                                continue;
                            }
                            $townAssignments[$town] = $fallbackDriver;
                        }
                        $driverId = $townAssignments[$town];
                        if (($driverStates[$driverId]['capacity'] ?? 0) <= 0) {
                            $capacityLeftCount += count($ordersInTown);
                            continue;
                        }

                        $routeStartLat = $driverStates[$driverId]['last_lat'];
                        $routeStartLng = $driverStates[$driverId]['last_lng'];
                        $orderedStops = nearestNeighborOrder($ordersInTown, $routeStartLat, $routeStartLng);

                        foreach ($orderedStops as $order) {
                            if (($driverStates[$driverId]['capacity'] ?? 0) <= 0) {
                                $capacityLeftCount += 1;
                                continue;
                            }
                            $assignments[] = [
                                'order_id' => (int) $order['id'],
                                'driver_id' => $driverId,
                            ];
                            $driverStates[$driverId]['capacity'] -= 1;
                            $driverStates[$driverId]['last_lat'] = (float) $order['latitude'];
                            $driverStates[$driverId]['last_lng'] = (float) $order['longitude'];
                            $driverRoutePoints[$driverId][] = [
                                'lat' => (float) $order['latitude'],
                                'lng' => (float) $order['longitude'],
                            ];
                        }
                    }

                    if (!empty($assignments)) {
                        getDB()->beginTransaction();
                        $updateStmt = getDB()->prepare("UPDATE daily_orders SET driver_id = ?, status = 'assigned' WHERE id = ?");
                        foreach ($assignments as $assignment) {
                            $updateStmt->execute([$assignment['driver_id'], $assignment['order_id']]);
                        }
                        getDB()->commit();
                    }

                    $assignedCount = count($assignments);
                    $message = "تم التوزيع التلقائي لـ {$assignedCount} طلب";
                    if ($capacityLeftCount > 0) {
                        $message .= "، {$capacityLeftCount} طلب بدون سائق بسبب السعة";
                    }
                    if ($noNearTownCount > 0) {
                        $message .= "، {$noNearTownCount} طلب بدون مدن قريبة";
                    }
                    if (!empty($debugLines)) {
                        $message .= " — " . implode(' | ', $debugLines);
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
                            <div class="row align-items-end g-2">
                                <div class="col-md-4">
                                    <label class="form-label">عدد السيارات للتوزيع</label>
                                    <input type="number" class="form-control" name="cars_count" min="1" max="<?php echo count($drivers); ?>" value="<?php echo max(1, count($drivers)); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">اختر السائقين للتوزيع</label>
                                    <select class="form-select" id="driverSelect" name="driver_ids[]" multiple>
                                        <?php foreach ($drivers as $driver): ?>
                                            <option value="<?php echo $driver['id']; ?>" data-color="<?php echo htmlspecialchars($driver['color'] ?? '#6c757d'); ?>">
                                                <?php echo htmlspecialchars($driver['name']); ?>
                                                <?php if (!empty($driver['governorate'])): ?>
                                                    (<?php echo htmlspecialchars($driver['governorate']); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="mt-2">
                                        <label class="form-label">السائقون المختارون</label>
                                        <div id="selectedDriversList" class="border rounded p-2" style="max-height: 140px; overflow-y: auto;">
                                            <div class="text-muted small">لا يوجد سائقين مختارين</div>
                                        </div>
                                        <div class="text-muted small mt-1">
                                            عدد السائقين المختارين: <span id="selectedDriversCount">0</span>
                                        </div>
                                        <small class="text-muted">اتركها فارغة لتوزيع على جميع السائقين</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check mt-4">
                                        <input class="form-check-input" type="checkbox" id="redistributeSelected" name="redistribute_selected" value="1" checked>
                                        <label class="form-check-label" for="redistributeSelected">
                                            إعادة توزيع طلبات السائقين المختارين
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                            <button type="submit" class="btn btn-outline-primary">
                                <i class="bi bi-shuffle"></i> توزيع تلقائي حسب المسار
                            </button>
                                </div>
                            </div>
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
                                            <?php 
                                                $driver = array_filter($drivers, fn($d) => $d['id'] == $driverId);
                                                $driver = reset($driver);
                                                $driverColor = $driver && !empty($driver['color']) ? $driver['color'] : '#6c757d';
                                            ?>
                                            <span class="badge driver-color-badge" style="background-color: <?php echo htmlspecialchars($driverColor); ?>;">
                                                <?php echo htmlspecialchars($driver ? $driver['name'] : 'غير معين'); ?>
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
                                                        <small class="text-muted">
                                                            <?php echo htmlspecialchars($order['address']); ?>
                                                        </small>
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
        .driver-color-badge {
            color: #ffffff;
        }
        .driver-color-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-left: 6px;
            vertical-align: middle;
            border: 1px solid rgba(255, 255, 255, 0.8);
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
            $('#driverSelect').select2({
                placeholder: 'اختر السائقين',
                width: '100%'
            });
            updateSelectedCustomersList();
            updateSelectedDriversList();
            $('#customerSelect').on('change', updateSelectedCustomersList);
            $('#driverSelect').on('change', updateSelectedDriversList);

            const selectedDateInput = document.querySelector('input[name="order_date"]');
            const selectedDate = selectedDateInput ? selectedDateInput.value : '';
            const driverStorageKey = selectedDate ? `selectedDrivers:${selectedDate}` : 'selectedDrivers';

            const savedDriverIds = localStorage.getItem(driverStorageKey);
            if (savedDriverIds) {
                const ids = savedDriverIds.split(',').filter(Boolean);
                $('#driverSelect').val(ids).trigger('change');
            }

            $('#driverSelect').on('change', function() {
                const selected = Array.from(this.selectedOptions).map(opt => opt.value);
                localStorage.setItem(driverStorageKey, selected.join(','));
            });

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

        function updateSelectedDriversList() {
            const select = document.getElementById('driverSelect');
            const list = document.getElementById('selectedDriversList');
            const count = document.getElementById('selectedDriversCount');
            if (!select || !list || !count) return;
            const selectedOptions = Array.from(select.selectedOptions);

            list.innerHTML = '';
            if (selectedOptions.length === 0) {
                list.innerHTML = '<div class="text-muted small">لا يوجد سائقين مختارين</div>';
                count.textContent = '0';
                return;
            }

            selectedOptions.forEach(option => {
                const item = document.createElement('div');
                item.className = 'border-bottom py-1 d-flex justify-content-between align-items-center';

                const label = document.createElement('span');
                const color = option.getAttribute('data-color') || '#6c757d';
                label.innerHTML = `<span class="driver-color-dot" style="background-color: ${color};"></span>${option.text}`;
                item.appendChild(label);

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'btn btn-sm btn-light border';
                removeBtn.textContent = '×';
                removeBtn.title = 'إزالة من القائمة';
                removeBtn.addEventListener('click', () => {
                    option.selected = false;
                    $('#driverSelect').trigger('change');
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
                const pinColor = isAssigned ? getDriverColor(order.driver_id) : '#6c757d';
                const marker = new google.maps.Marker({
                    position: { lat: parseFloat(order.latitude), lng: parseFloat(order.longitude) },
                    map: routeMap,
                    title: order.customer_name,
                    icon: {
                        path: "M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5S13.38 11.5 12 11.5z",
                        fillColor: pinColor,
                        fillOpacity: 1,
                        strokeColor: '#ffffff',
                        strokeWeight: 1,
                        scale: 1.2,
                        anchor: new google.maps.Point(12, 22)
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

