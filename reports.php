<?php
require_once 'db.php';

$message = '';
$messageType = '';
$selected_date = $_GET['date'] ?? date('Y-m-d');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

function extractTrailingNumber($address) {
    $address = trim((string) $address);
    if ($address === '') {
        return '';
    }
    if (preg_match('/(\d+)\s*$/u', $address, $matches)) {
        return $matches[1];
    }
    return '';
}

function encodePlusCode($latitude, $longitude) {
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        return '';
    }
    $lat = (float) $latitude;
    $lng = (float) $longitude;
    $lat = max(-90.0, min(90.0, $lat));
    if ($lat === 90.0) {
        $lat = 89.999999;
    }
    while ($lng < -180.0) {
        $lng += 360.0;
    }
    while ($lng >= 180.0) {
        $lng -= 360.0;
    }
    $lat += 90.0;
    $lng += 180.0;

    $alphabet = '23456789CFGHJMPQRVWX';
    $separatorPosition = 8;
    $pairResolutions = [20.0, 1.0, 0.05, 0.0025, 0.000125];
    $code = '';

    foreach ($pairResolutions as $res) {
        $latDigit = (int) floor($lat / $res);
        $lngDigit = (int) floor($lng / $res);
        $latDigit = max(0, min(19, $latDigit));
        $lngDigit = max(0, min(19, $lngDigit));

        $code .= $alphabet[$latDigit] . $alphabet[$lngDigit];
        $lat -= $latDigit * $res;
        $lng -= $lngDigit * $res;

        if (strlen($code) === $separatorPosition) {
            $code .= '+';
        }
    }

    return $code;
}

try {
    $useRange = ($date_from !== '' && $date_to !== '');
    $ordersStmt = getDB()->prepare($useRange ? "
        SELECT o.id, o.customer_id, o.order_date, c.name AS customer_name, c.address, c.phone, c.latitude, c.longitude,
               d.id AS driver_id, d.name AS driver_name
        FROM daily_orders o
        JOIN customers c ON o.customer_id = c.id
        LEFT JOIN drivers d ON o.driver_id = d.id
        WHERE o.order_date BETWEEN ? AND ?
        ORDER BY d.name, o.order_date, o.id
    " : "
        SELECT o.id, o.customer_id, o.order_date, c.name AS customer_name, c.address, c.phone, c.latitude, c.longitude,
               d.id AS driver_id, d.name AS driver_name
        FROM daily_orders o
        JOIN customers c ON o.customer_id = c.id
        LEFT JOIN drivers d ON o.driver_id = d.id
        WHERE o.order_date = ?
        ORDER BY d.name, o.id
    ");
    if ($useRange) {
        $ordersStmt->execute([$date_from, $date_to]);
    } else {
        $ordersStmt->execute([$selected_date]);
    }
    $orders = $ordersStmt->fetchAll();

    $ordersByDriver = [];
    foreach ($orders as $order) {
        $driverKey = $order['driver_id'] ? (string) $order['driver_id'] : 'unassigned';
        if (!isset($ordersByDriver[$driverKey])) {
            $ordersByDriver[$driverKey] = [
                'driver_name' => $order['driver_name'] ?: 'غير معين',
                'orders' => []
            ];
        }
        $ordersByDriver[$driverKey]['orders'][] = $order;
    }
} catch (PDOException $e) {
    $message = "خطأ في تحميل التقارير: " . $e->getMessage();
    $messageType = "danger";
    $ordersByDriver = [];
}

$pageTitle = APP_NAME . ' - التقارير';
require_once 'header.php';
?>

<div class="container-fluid mt-4">
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card no-print">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-clipboard-data"></i> تقارير السائقين</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">التاريخ</label>
                    <input type="date" class="form-control" name="date" value="<?php echo htmlspecialchars($selected_date); ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">من تاريخ</label>
                    <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">إلى تاريخ</label>
                    <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-md-9">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> عرض
                    </button>
                    <button type="button" class="btn btn-outline-primary" id="printAllBtn">
                        <i class="bi bi-printer"></i> طباعة الكل
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($ordersByDriver)): ?>
        <div class="text-muted text-center mt-4">لا توجد طلبات لهذا التاريخ</div>
    <?php else: ?>
        <?php foreach ($ordersByDriver as $driverId => $driverData): ?>
            <div class="card report-section" data-driver-id="<?php echo htmlspecialchars($driverId); ?>">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi bi-person-badge"></i>
                        <span>السائق: <?php echo htmlspecialchars($driverData['driver_name']); ?></span>
                        <div class="small">
                            <?php echo $useRange
                                ? 'الفترة: ' . htmlspecialchars($date_from) . ' إلى ' . htmlspecialchars($date_to)
                                : 'التاريخ: ' . htmlspecialchars($selected_date); ?>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-light print-driver-btn no-print" data-driver-id="<?php echo htmlspecialchars($driverId); ?>">
                        <i class="bi bi-printer"></i> طباعة
                    </button>
                </div>
                <div class="card-body">
                    <ul class="list-group report-list" id="reportList-<?php echo htmlspecialchars($driverId); ?>">
                        <?php foreach ($driverData['orders'] as $index => $order): ?>
                            <?php
                                $lat = is_numeric($order['latitude']) ? (float) $order['latitude'] : null;
                                $lng = is_numeric($order['longitude']) ? (float) $order['longitude'] : null;
                                if ($lat && $lng) {
                                    $mapQuery = $lat . ',' . $lng;
                                } else {
                                    $mapQuery = $order['address'];
                                }
                                $mapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($mapQuery);
                                $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=" . rawurlencode($mapsUrl);
                            ?>
                            <?php
                                $addressNumber = extractTrailingNumber($order['address']);
                                $plusCode = encodePlusCode($order['latitude'], $order['longitude']);
                            ?>
                            <li class="list-group-item d-flex align-items-start" data-order-id="<?php echo $order['id']; ?>">
                                <span class="badge bg-primary order-index"><?php echo $index + 1; ?></span>
                                <div class="flex-grow-1 ms-2">
                                    <div class="fw-bold"><?php echo htmlspecialchars($order['customer_name']); ?></div>
                                    <div class="text-muted small">
                                        <?php echo htmlspecialchars($order['address']); ?>
                                    </div>
                                    <?php if (!empty($order['order_date'])): ?>
                                        <div class="text-muted small">تاريخ الطلب: <?php echo htmlspecialchars($order['order_date']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($addressNumber !== ''): ?>
                                        <div class="text-muted small">رقم الموقع: <?php echo htmlspecialchars($addressNumber); ?></div>
                                    <?php endif; ?>
                                    <div class="text-muted small">رقم العميل: <?php echo htmlspecialchars($order['customer_id']); ?></div>
                                    <?php if ($plusCode !== ''): ?>
                                        <div class="text-muted small">الرمز العالمي: <?php echo htmlspecialchars($plusCode); ?></div>
                                    <?php endif; ?>
                                </div>
                                <img src="<?php echo $qrUrl; ?>" alt="QR" class="qr-img">
                                <span class="drag-handle ms-2 no-print" title="اسحب للتغيير">
                                    <i class="bi bi-grip-vertical"></i>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
    .report-list .list-group-item {
        cursor: default;
    }
    .report-list .drag-handle {
        cursor: grab;
        color: #888;
        font-size: 1.1rem;
    }
    .report-list .drag-handle:active {
        cursor: grabbing;
    }
    .order-index {
        min-width: 28px;
        text-align: center;
    }
    .qr-img {
        width: 70px;
        height: 70px;
        object-fit: contain;
        border: 1px solid #eee;
        border-radius: 6px;
        background: #fff;
    }
    @media print {
        .no-print {
            display: none !important;
        }
        .report-section {
            page-break-after: always;
        }
        .report-section:last-of-type {
            page-break-after: auto;
        }
        body.print-driver .report-section {
            display: none !important;
        }
        body.print-driver .report-section.print-only {
            display: block !important;
        }
        .report-section {
            page-break-inside: avoid;
        }
        .qr-img {
            border: none;
        }
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
    function updateOrderIndexes(listEl) {
        const items = listEl.querySelectorAll('.list-group-item');
        items.forEach((item, index) => {
            const badge = item.querySelector('.order-index');
            if (badge) badge.textContent = index + 1;
        });
    }

    document.querySelectorAll('.report-list').forEach(listEl => {
        new Sortable(listEl, {
            handle: '.drag-handle',
            animation: 150,
            onEnd: () => updateOrderIndexes(listEl)
        });
        updateOrderIndexes(listEl);
    });

    document.querySelectorAll('.print-driver-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const driverId = btn.getAttribute('data-driver-id');
            const section = document.querySelector(`.report-section[data-driver-id="${driverId}"]`);
            if (!section) return;
            document.body.classList.add('print-driver');
            section.classList.add('print-only');
            window.print();
            section.classList.remove('print-only');
            document.body.classList.remove('print-driver');
        });
    });

    const printAllBtn = document.getElementById('printAllBtn');
    if (printAllBtn) {
        printAllBtn.addEventListener('click', () => window.print());
    }
</script>

<?php require_once 'footer.php'; ?>
