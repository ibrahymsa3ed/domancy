<?php
require_once 'db.php';

$message = '';
$messageType = '';

$tab = $_GET['tab'] ?? 'daily';
$selected_date = $_GET['date'] ?? date('Y-m-d');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$filter_customer = $_GET['customer_id'] ?? '';
$filter_driver = $_GET['driver_id'] ?? '';
$filter_mode = $_GET['mode'] ?? 'all';

function extractTrailingNumber($address) {
    $address = trim((string) $address);
    if ($address === '') return '';
    if (preg_match('/(\d+)\s*$/u', $address, $matches)) return $matches[1];
    return '';
}

function encodePlusCode($latitude, $longitude) {
    if (!is_numeric($latitude) || !is_numeric($longitude)) return '';
    $lat = (float) $latitude; $lng = (float) $longitude;
    $lat = max(-90.0, min(90.0, $lat));
    if ($lat === 90.0) $lat = 89.999999;
    while ($lng < -180.0) $lng += 360.0;
    while ($lng >= 180.0) $lng -= 360.0;
    $lat += 90.0; $lng += 180.0;
    $alphabet = '23456789CFGHJMPQRVWX';
    $pairResolutions = [20.0, 1.0, 0.05, 0.0025, 0.000125];
    $code = '';
    foreach ($pairResolutions as $res) {
        $latDigit = max(0, min(19, (int) floor($lat / $res)));
        $lngDigit = max(0, min(19, (int) floor($lng / $res)));
        $code .= $alphabet[$latDigit] . $alphabet[$lngDigit];
        $lat -= $latDigit * $res; $lng -= $lngDigit * $res;
        if (strlen($code) === 8) $code .= '+';
    }
    return $code;
}

$allCustomers = getDB()->query("SELECT id, customer_number, name, phone FROM customers ORDER BY CAST(customer_number AS UNSIGNED), customer_number")->fetchAll();
$allDrivers = getDB()->query("SELECT id, name FROM drivers WHERE is_active = 1 ORDER BY name")->fetchAll();

$orders = [];
$reportTitle = '';
$reportSubtitle = '';

try {
    $sql = "SELECT o.id, o.customer_id, o.order_date, c.name AS customer_name, c.customer_number, c.address, c.phone, c.latitude, c.longitude,
                   d.id AS driver_id, d.name AS driver_name
            FROM daily_orders o
            JOIN customers c ON o.customer_id = c.id
            LEFT JOIN drivers d ON o.driver_id = d.id";
    $params = [];

    if ($tab === 'daily') {
        $sql .= " WHERE o.order_date = ?";
        $params[] = $selected_date;
        $reportTitle = 'تقرير يومي';
        $reportSubtitle = $selected_date;
    } elseif ($tab === 'period') {
        if ($date_from !== '' && $date_to !== '') {
            $sql .= " WHERE o.order_date BETWEEN ? AND ?";
            $params = [$date_from, $date_to];
            $reportTitle = 'تقرير فترة';
            $reportSubtitle = $date_from . ' إلى ' . $date_to;
        } else {
            $reportTitle = 'تقرير فترة';
            $reportSubtitle = 'اختر الفترة';
        }
    } elseif ($tab === 'customer') {
        if ($filter_customer !== '') {
            $sql .= " WHERE o.customer_id = ?";
            $params[] = (int)$filter_customer;
            if ($filter_mode === 'date' && $selected_date) {
                $sql .= " AND o.order_date = ?";
                $params[] = $selected_date;
            } elseif ($filter_mode === 'period' && $date_from && $date_to) {
                $sql .= " AND o.order_date BETWEEN ? AND ?";
                $params[] = $date_from;
                $params[] = $date_to;
            }
            $custName = '';
            foreach ($allCustomers as $c) { if ((int)$c['id'] === (int)$filter_customer) { $custName = $c['name']; break; } }
            $reportTitle = 'تقرير عميل: ' . $custName;
        } else {
            $reportTitle = 'تقرير بحسب العميل';
            $reportSubtitle = 'اختر عميل';
        }
    } elseif ($tab === 'driver') {
        if ($filter_driver !== '') {
            $sql .= " WHERE o.driver_id = ?";
            $params[] = (int)$filter_driver;
            if ($filter_mode === 'date' && $selected_date) {
                $sql .= " AND o.order_date = ?";
                $params[] = $selected_date;
            } elseif ($filter_mode === 'period' && $date_from && $date_to) {
                $sql .= " AND o.order_date BETWEEN ? AND ?";
                $params[] = $date_from;
                $params[] = $date_to;
            }
            $drvName = '';
            foreach ($allDrivers as $d) { if ((int)$d['id'] === (int)$filter_driver) { $drvName = $d['name']; break; } }
            $reportTitle = 'تقرير سائق: ' . $drvName;
        } else {
            $reportTitle = 'تقرير بحسب السائق';
            $reportSubtitle = 'اختر سائق';
        }
    }

    $sql .= " ORDER BY d.name, o.order_date DESC, o.id";

    if (!empty($params)) {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll();
    }

    $ordersByDriver = [];
    foreach ($orders as $order) {
        $driverKey = $order['driver_id'] ? (string)$order['driver_id'] : 'unassigned';
        if (!isset($ordersByDriver[$driverKey])) {
            $ordersByDriver[$driverKey] = ['driver_name' => $order['driver_name'] ?: 'غير معين', 'orders' => []];
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

    <div class="card no-print mb-3">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link <?php echo $tab === 'daily' ? 'active' : ''; ?>" href="?tab=daily&date=<?php echo urlencode($selected_date); ?>">
                        <i class="bi bi-calendar-day"></i> تقرير يومي
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $tab === 'period' ? 'active' : ''; ?>" href="?tab=period">
                        <i class="bi bi-calendar-range"></i> تقرير فترة
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $tab === 'customer' ? 'active' : ''; ?>" href="?tab=customer">
                        <i class="bi bi-person"></i> بحسب العميل
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $tab === 'driver' ? 'active' : ''; ?>" href="?tab=driver">
                        <i class="bi bi-truck"></i> بحسب السائق
                    </a>
                </li>
            </ul>
        </div>
        <div class="card-body">
            <?php if ($tab === 'daily'): ?>
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="tab" value="daily">
                    <div class="col-auto">
                        <label class="form-label">التاريخ</label>
                        <input type="date" class="form-control" name="date" value="<?php echo htmlspecialchars($selected_date); ?>">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> عرض</button>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-outline-primary" id="printAllBtn"><i class="bi bi-printer"></i> طباعة الكل</button>
                    </div>
                </form>

            <?php elseif ($tab === 'period'): ?>
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="tab" value="period">
                    <div class="col-auto">
                        <label class="form-label">من تاريخ</label>
                        <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" required>
                    </div>
                    <div class="col-auto">
                        <label class="form-label">إلى تاريخ</label>
                        <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" required>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> عرض</button>
                    </div>
                    <?php if (!empty($orders)): ?>
                    <div class="col-auto">
                        <button type="button" class="btn btn-outline-primary" id="printAllBtn"><i class="bi bi-printer"></i> طباعة الكل</button>
                    </div>
                    <?php endif; ?>
                </form>

            <?php elseif ($tab === 'customer'): ?>
                <?php
                    $selectedCustLabel = '';
                    if ($filter_customer !== '') {
                        foreach ($allCustomers as $c) {
                            if ((string)$c['id'] === (string)$filter_customer) {
                                $selectedCustLabel = '#' . $c['customer_number'] . ' - ' . $c['name'];
                                break;
                            }
                        }
                    }
                ?>
                <form method="GET" id="customerReportForm" class="row g-2 align-items-end">
                    <input type="hidden" name="tab" value="customer">
                    <input type="hidden" name="customer_id" id="custPickerHidden" value="<?php echo htmlspecialchars($filter_customer); ?>">
                    <div class="col-md-4 position-relative">
                        <label class="form-label">العميل</label>
                        <input type="text" class="form-control" id="custPickerSearch" autocomplete="off"
                               placeholder="ابحث بالاسم أو الرقم أو الهاتف"
                               value="<?php echo htmlspecialchars($selectedCustLabel); ?>">
                        <div id="custPickerDropdown" class="picker-dropdown"></div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">الفترة</label>
                        <select class="form-select" name="mode" id="customerModeSelect">
                            <option value="all" <?php echo $filter_mode === 'all' ? 'selected' : ''; ?>>الكل</option>
                            <option value="date" <?php echo $filter_mode === 'date' ? 'selected' : ''; ?>>يوم محدد</option>
                            <option value="period" <?php echo $filter_mode === 'period' ? 'selected' : ''; ?>>فترة</option>
                        </select>
                    </div>
                    <div class="col-auto customer-date-field" style="<?php echo $filter_mode === 'date' ? '' : 'display:none'; ?>">
                        <label class="form-label">التاريخ</label>
                        <input type="date" class="form-control" name="date" value="<?php echo htmlspecialchars($selected_date); ?>">
                    </div>
                    <div class="col-auto customer-period-field" style="<?php echo $filter_mode === 'period' ? '' : 'display:none'; ?>">
                        <label class="form-label">من</label>
                        <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="col-auto customer-period-field" style="<?php echo $filter_mode === 'period' ? '' : 'display:none'; ?>">
                        <label class="form-label">إلى</label>
                        <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> عرض</button>
                    </div>
                    <?php if (!empty($orders)): ?>
                    <div class="col-auto">
                        <button type="button" class="btn btn-outline-primary" id="printAllBtn"><i class="bi bi-printer"></i> طباعة</button>
                    </div>
                    <?php endif; ?>
                </form>

            <?php elseif ($tab === 'driver'): ?>
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="tab" value="driver">
                    <div class="col-md-3">
                        <label class="form-label">السائق</label>
                        <select class="form-select" name="driver_id" required>
                            <option value="">اختر سائق</option>
                            <?php foreach ($allDrivers as $d): ?>
                                <option value="<?php echo $d['id']; ?>" <?php echo (string)$d['id'] === (string)$filter_driver ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($d['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">الفترة</label>
                        <select class="form-select" name="mode" id="driverModeSelect">
                            <option value="all" <?php echo $filter_mode === 'all' ? 'selected' : ''; ?>>الكل</option>
                            <option value="date" <?php echo $filter_mode === 'date' ? 'selected' : ''; ?>>يوم محدد</option>
                            <option value="period" <?php echo $filter_mode === 'period' ? 'selected' : ''; ?>>فترة</option>
                        </select>
                    </div>
                    <div class="col-auto driver-date-field" style="<?php echo $filter_mode === 'date' ? '' : 'display:none'; ?>">
                        <label class="form-label">التاريخ</label>
                        <input type="date" class="form-control" name="date" value="<?php echo htmlspecialchars($selected_date); ?>">
                    </div>
                    <div class="col-auto driver-period-field" style="<?php echo $filter_mode === 'period' ? '' : 'display:none'; ?>">
                        <label class="form-label">من</label>
                        <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="col-auto driver-period-field" style="<?php echo $filter_mode === 'period' ? '' : 'display:none'; ?>">
                        <label class="form-label">إلى</label>
                        <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> عرض</button>
                    </div>
                    <?php if (!empty($orders)): ?>
                    <div class="col-auto">
                        <button type="button" class="btn btn-outline-primary" id="printAllBtn"><i class="bi bi-printer"></i> طباعة</button>
                    </div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($orders)): ?>
        <div class="mb-2 no-print">
            <span class="badge bg-secondary"><?php echo count($orders); ?> طلب</span>
            <?php if ($reportSubtitle): ?>
                <span class="text-muted small"><?php echo htmlspecialchars($reportSubtitle); ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($orders) && (!empty($params) || $tab === 'daily')): ?>
        <div class="text-muted text-center mt-4 py-5">
            <i class="bi bi-inbox" style="font-size: 2rem;"></i>
            <div class="mt-2">لا توجد طلبات <?php echo $tab === 'daily' ? 'لهذا اليوم' : ''; ?></div>
        </div>
    <?php elseif (!empty($ordersByDriver)): ?>
        <?php foreach ($ordersByDriver as $driverId => $driverData): ?>
            <div class="card report-section mb-3" data-driver-id="<?php echo htmlspecialchars($driverId); ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi bi-person-badge"></i>
                        <strong>السائق: <?php echo htmlspecialchars($driverData['driver_name']); ?></strong>
                        <span class="badge bg-primary ms-1"><?php echo count($driverData['orders']); ?></span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary print-driver-btn no-print" data-driver-id="<?php echo htmlspecialchars($driverId); ?>">
                        <i class="bi bi-printer"></i> طباعة
                    </button>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush report-list" id="reportList-<?php echo htmlspecialchars($driverId); ?>">
                        <?php foreach ($driverData['orders'] as $index => $order): ?>
                            <?php
                                $lat = is_numeric($order['latitude']) ? (float)$order['latitude'] : null;
                                $lng = is_numeric($order['longitude']) ? (float)$order['longitude'] : null;
                                $mapQuery = ($lat && $lng) ? $lat . ',' . $lng : $order['address'];
                                $mapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($mapQuery);
                                $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=" . rawurlencode($mapsUrl);
                                $addressNumber = extractTrailingNumber($order['address']);
                                $plusCode = encodePlusCode($order['latitude'], $order['longitude']);
                            ?>
                            <li class="list-group-item d-flex align-items-start" data-order-id="<?php echo $order['id']; ?>">
                                <span class="badge bg-primary order-index"><?php echo $index + 1; ?></span>
                                <div class="flex-grow-1 ms-2">
                                    <div class="fw-bold">
                                        <span class="text-muted">#<?php echo htmlspecialchars($order['customer_number'] ?? $order['customer_id']); ?></span>
                                        <?php echo htmlspecialchars($order['customer_name']); ?>
                                    </div>
                                    <div class="text-muted small"><?php echo htmlspecialchars($order['address']); ?></div>
                                    <?php if ($tab !== 'daily'): ?>
                                        <div class="text-muted small">التاريخ: <?php echo htmlspecialchars($order['order_date']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($addressNumber !== ''): ?>
                                        <div class="text-muted small">رقم الموقع: <?php echo htmlspecialchars($addressNumber); ?></div>
                                    <?php endif; ?>
                                    <?php if ($plusCode !== ''): ?>
                                        <div class="text-muted small">الرمز العالمي: <?php echo htmlspecialchars($plusCode); ?></div>
                                    <?php endif; ?>
                                </div>
                                <img src="<?php echo $qrUrl; ?>" alt="QR" class="qr-img" loading="lazy">
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
    .card-body:has(.picker-dropdown) { overflow: visible; }
    .picker-dropdown {
        display: none; position: absolute; z-index: 1050; top: 100%; left: 0; right: 0;
        max-height: 250px; overflow-y: auto; background: #fff; border: 1px solid #dee2e6;
        border-radius: 0 0 .375rem .375rem; box-shadow: 0 4px 12px rgba(0,0,0,.12);
    }
    .picker-dropdown.show { display: block; }
    .picker-dropdown .picker-item {
        padding: 6px 12px; cursor: pointer; font-size: .9rem; border-bottom: 1px solid #f0f0f0;
    }
    .picker-dropdown .picker-item:hover, .picker-dropdown .picker-item.active { background: #e9ecef; }
    .picker-dropdown .picker-empty { padding: 10px 12px; text-align: center; color: #999; font-size: .85rem; }
    .report-list .list-group-item { cursor: default; }
    .report-list .drag-handle { cursor: grab; color: #888; font-size: 1.1rem; }
    .report-list .drag-handle:active { cursor: grabbing; }
    .order-index { min-width: 28px; text-align: center; }
    .qr-img { width: 70px; height: 70px; object-fit: contain; border: 1px solid #eee; border-radius: 6px; background: #fff; }
    @media print {
        .no-print { display: none !important; }
        .report-section { page-break-after: always; }
        .report-section:last-of-type { page-break-after: auto; }
        body.print-driver .report-section { display: none !important; }
        body.print-driver .report-section.print-only { display: block !important; }
        .report-section { page-break-inside: avoid; }
        .qr-img { border: none; }
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
    function updateOrderIndexes(listEl) {
        listEl.querySelectorAll('.list-group-item').forEach((item, i) => {
            const badge = item.querySelector('.order-index');
            if (badge) badge.textContent = i + 1;
        });
    }

    document.querySelectorAll('.report-list').forEach(listEl => {
        new Sortable(listEl, { handle: '.drag-handle', animation: 150, onEnd: () => updateOrderIndexes(listEl) });
        updateOrderIndexes(listEl);
    });

    document.querySelectorAll('.print-driver-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.getAttribute('data-driver-id');
            const section = document.querySelector(`.report-section[data-driver-id="${id}"]`);
            if (!section) return;
            document.body.classList.add('print-driver');
            section.classList.add('print-only');
            window.print();
            section.classList.remove('print-only');
            document.body.classList.remove('print-driver');
        });
    });

    const printAllBtn = document.getElementById('printAllBtn');
    if (printAllBtn) printAllBtn.addEventListener('click', () => window.print());

    function setupModeToggle(selectId, dateClass, periodClass) {
        const sel = document.getElementById(selectId);
        if (!sel) return;
        sel.addEventListener('change', function() {
            document.querySelectorAll('.' + dateClass).forEach(el => el.style.display = this.value === 'date' ? '' : 'none');
            document.querySelectorAll('.' + periodClass).forEach(el => el.style.display = this.value === 'period' ? '' : 'none');
        });
    }
    setupModeToggle('customerModeSelect', 'customer-date-field', 'customer-period-field');
    setupModeToggle('driverModeSelect', 'driver-date-field', 'driver-period-field');

    (function() {
        const allCust = <?php echo json_encode(array_map(function($c) {
            return ['id' => $c['id'], 'num' => $c['customer_number'], 'name' => $c['name'], 'phone' => $c['phone'] ?? ''];
        }, $allCustomers)); ?>;

        const searchEl = document.getElementById('custPickerSearch');
        const dropEl = document.getElementById('custPickerDropdown');
        const hiddenEl = document.getElementById('custPickerHidden');
        if (!searchEl || !dropEl || !hiddenEl) return;

        function render(q) {
            const lower = (q || '').toLowerCase().trim();
            const filtered = lower === '' ? allCust : allCust.filter(c =>
                c.name.toLowerCase().includes(lower) ||
                c.num.toLowerCase().includes(lower) ||
                c.phone.toLowerCase().includes(lower)
            );
            if (filtered.length === 0) {
                dropEl.innerHTML = '<div class="picker-empty">لا توجد نتائج</div>';
            } else {
                dropEl.innerHTML = filtered.slice(0, 50).map(c =>
                    '<div class="picker-item" data-id="' + c.id + '" data-label="#' + c.num + ' - ' + c.name + '">' +
                    '<strong>#' + c.num + '</strong> ' + c.name +
                    (c.phone ? ' <span class="text-muted small">(' + c.phone + ')</span>' : '') +
                    '</div>'
                ).join('');
            }
            dropEl.classList.add('show');
        }

        searchEl.addEventListener('focus', function() { render(this.value); });
        searchEl.addEventListener('input', function() {
            hiddenEl.value = '';
            render(this.value);
        });

        dropEl.addEventListener('click', function(e) {
            const item = e.target.closest('.picker-item');
            if (!item) return;
            hiddenEl.value = item.dataset.id;
            searchEl.value = item.dataset.label;
            dropEl.classList.remove('show');
        });

        document.addEventListener('click', function(e) {
            if (!searchEl.contains(e.target) && !dropEl.contains(e.target)) {
                dropEl.classList.remove('show');
            }
        });

        document.getElementById('customerReportForm')?.addEventListener('submit', function(e) {
            if (!hiddenEl.value) {
                e.preventDefault();
                searchEl.focus();
                searchEl.classList.add('is-invalid');
                setTimeout(() => searchEl.classList.remove('is-invalid'), 2000);
            }
        });
    })();
</script>

<?php require_once 'footer.php'; ?>
