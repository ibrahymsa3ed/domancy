<?php
require_once 'db.php';

$message = '';
$messageType = '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add') {
            $customer_number = trim($_POST['customer_number'] ?? '');
            $name = $_POST['name'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $address = $_POST['address'] ?? '';
            $latitude = $_POST['latitude'] ?? '';
            $longitude = $_POST['longitude'] ?? '';
            $notes = $_POST['notes'] ?? '';
            $town = $_POST['town'] ?? '';

            if ($name && $address && $latitude && $longitude) {
                if ($customer_number === '') {
                    $maxNum = getDB()->query("SELECT MAX(CAST(customer_number AS UNSIGNED)) FROM customers")->fetchColumn();
                    $customer_number = (string)(((int)$maxNum) + 1);
                }
                $dup = getDB()->prepare("SELECT id FROM customers WHERE customer_number = ?");
                $dup->execute([$customer_number]);
                if ($dup->fetch()) {
                    $message = "رقم العميل '$customer_number' مستخدم بالفعل";
                    $messageType = "danger";
                } else {
                    try {
                        $stmt = getDB()->prepare("INSERT INTO customers (customer_number, name, phone, address, town, latitude, longitude, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$customer_number, $name, $phone, $address, $town, $latitude, $longitude, $notes]);
                        $message = "تم إضافة العميل بنجاح";
                        $messageType = "success";
                    } catch (PDOException $e) {
                        $message = "خطأ في إضافة العميل: " . $e->getMessage();
                        $messageType = "danger";
                    }
                }
            } else {
                $message = "يرجى ملء جميع الحقول المطلوبة";
                $messageType = "warning";
            }
        } elseif ($_POST['action'] === 'edit') {
            $id = $_POST['id'] ?? 0;
            $customer_number = trim($_POST['customer_number'] ?? '');
            $name = $_POST['name'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $address = $_POST['address'] ?? '';
            $latitude = $_POST['latitude'] ?? '';
            $longitude = $_POST['longitude'] ?? '';
            $notes = $_POST['notes'] ?? '';
            $town = $_POST['town'] ?? '';

            if ($id && $name && $address && $customer_number !== '') {
                $dup = getDB()->prepare("SELECT id FROM customers WHERE customer_number = ? AND id != ?");
                $dup->execute([$customer_number, $id]);
                if ($dup->fetch()) {
                    $message = "رقم العميل '$customer_number' مستخدم بالفعل لعميل آخر";
                    $messageType = "danger";
                } else {
                    try {
                        $stmt = getDB()->prepare("UPDATE customers SET customer_number = ?, name = ?, phone = ?, address = ?, town = ?, latitude = ?, longitude = ?, notes = ? WHERE id = ?");
                        $stmt->execute([$customer_number, $name, $phone, $address, $town, $latitude ?: null, $longitude ?: null, $notes, $id]);
                        $message = "تم تحديث بيانات العميل بنجاح";
                        $messageType = "success";
                    } catch (PDOException $e) {
                        $message = "خطأ في تحديث العميل: " . $e->getMessage();
                        $messageType = "danger";
                    }
                }
            } else {
                $message = "يرجى ملء جميع الحقول المطلوبة";
                $messageType = "warning";
            }
        } elseif ($_POST['action'] === 'delete') {
            $id = $_POST['id'] ?? 0;
            if ($id) {
                try {
                    $stmt = getDB()->prepare("DELETE FROM customers WHERE id = ?");
                    $stmt->execute([$id]);
                    $message = "تم حذف العميل بنجاح";
                    $messageType = "success";
                } catch (PDOException $e) {
                    $message = "خطأ في حذف العميل: " . $e->getMessage();
                    $messageType = "danger";
                }
            }
        } elseif ($_POST['action'] === 'seed_customers') {
            try {
                $factory = getDB()->query("SELECT latitude, longitude FROM factory LIMIT 1")->fetch();
                $baseLat = $factory ? (float) $factory['latitude'] : 30.0444;
                $baseLng = $factory ? (float) $factory['longitude'] : 31.2357;
                $town = 'القاهرة';
                $maxNum = (int) getDB()->query("SELECT COALESCE(MAX(CAST(customer_number AS UNSIGNED)), 0) FROM customers")->fetchColumn();
                $stmt = getDB()->prepare("INSERT INTO customers (customer_number, name, phone, address, town, latitude, longitude, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                for ($i = 1; $i <= 10; $i++) {
                    $maxNum++;
                    $name = 'عميل عشوائي ' . $i;
                    $phone = '01' . random_int(100000000, 999999999);
                    $addrNum = random_int(1000000, 9999999);
                    $address = 'عنوان تجريبي ' . $i . ' محافظة القاهرة ' . $addrNum;
                    $lat = $baseLat + (random_int(-120, 120) / 1000);
                    $lng = $baseLng + (random_int(-120, 120) / 1000);
                    $stmt->execute([(string)$maxNum, $name, $phone, $address, $town, $lat, $lng, '']);
                }
                $message = "تم إضافة 10 عملاء تجريبيين";
                $messageType = "success";
            } catch (Throwable $e) {
                $message = "خطأ في إضافة العملاء التجريبيين: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    }
}

$allCustomers = getDB()->query("SELECT * FROM customers ORDER BY CAST(customer_number AS UNSIGNED), customer_number")->fetchAll();
$totalCustomers = count($allCustomers);

$pageTitle = APP_NAME . ' - العملاء';
$googleMapsScript = 'places,geometry';
require_once 'header.php';
?>

    <div class="container-fluid mt-3">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-lg-4 col-xl-3">
                <div class="card" id="customerFormCard">
                    <div class="card-header">
                        <h5 class="mb-0" id="formTitle"><i class="bi bi-person-plus"></i> إضافة عميل جديد</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="customerForm">
                            <input type="hidden" name="action" value="add" id="formAction">
                            <input type="hidden" name="id" id="editId">
                            <div class="mb-2">
                                <label class="form-label">رقم العميل</label>
                                <input type="text" class="form-control" name="customer_number" id="formCustomerNumber" placeholder="تلقائي">
                            </div>
                            <div class="mb-2">
                                <label class="form-label">اسم العميل *</label>
                                <input type="text" class="form-control" name="name" id="formName" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">الهاتف</label>
                                <input type="text" class="form-control" name="phone" id="formPhone">
                            </div>
                            <div class="mb-2">
                                <label class="form-label">العنوان *</label>
                                <input type="text" class="form-control" id="addressInput" name="address" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">بحث بالإحداثيات</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control" id="coordsInput" placeholder="30.0444, 31.2357">
                                    <button type="button" class="btn btn-outline-primary" id="coordsSearchBtn">بحث</button>
                                </div>
                                <div class="text-danger small mt-1 d-none" id="coordsError">إحداثيات غير صحيحة</div>
                            </div>
                            <div class="mb-2">
                                <div id="customerMap" style="height: 220px; width: 100%;"></div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">ملاحظات</label>
                                <textarea class="form-control" name="notes" id="formNotes" rows="2"></textarea>
                            </div>
                            <input type="hidden" name="latitude" id="latitude">
                            <input type="hidden" name="longitude" id="longitude">
                            <input type="hidden" name="town" id="town">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-grow-1" id="formSubmitBtn">
                                    <i class="bi bi-check-circle"></i> إضافة
                                </button>
                                <button type="button" class="btn btn-outline-secondary d-none" id="formCancelBtn" onclick="cancelEdit()">
                                    إلغاء
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-8 col-xl-9">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-list-ul"></i> العملاء (<?php echo $totalCustomers; ?>)</h5>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="seed_customers">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">+ 10 تجريبي</button>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <div class="p-2 d-flex gap-2 align-items-center">
                            <div class="input-group flex-grow-1">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control" id="customerSearchInput" placeholder="بحث برقم العميل أو الاسم أو الهاتف أو العنوان...">
                            </div>
                            <select class="form-select form-select-sm" id="perPageSelect" style="width: auto;">
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                        <div class="d-flex justify-content-between align-items-center p-2 border-bottom" id="topPaginationBar">
                            <small class="text-muted" id="customerPageInfoTop"></small>
                            <nav>
                                <ul class="pagination pagination-sm mb-0" id="customerPaginationTop"></ul>
                            </nav>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th class="sortable-th" data-col="customer_number">رقم <span id="sortIcon-customer_number">▲</span></th>
                                        <th class="sortable-th" data-col="name">الاسم <span id="sortIcon-name"></span></th>
                                        <th class="sortable-th" data-col="phone">الهاتف <span id="sortIcon-phone"></span></th>
                                        <th>العنوان</th>
                                        <th>رقم الموقع</th>
                                        <th>الرمز العالمي</th>
                                        <th>QR</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="customerTableBody"></tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-between align-items-center p-2 border-top">
                            <small class="text-muted" id="customerPageInfo"></small>
                            <nav>
                                <ul class="pagination pagination-sm mb-0" id="customerPagination"></ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .sortable-th { cursor: pointer; user-select: none; white-space: nowrap; }
        .sortable-th:hover { background: #f0f0f0; }
    </style>
    <script>
        let autocomplete, geocoder, map, marker;
        const defaultCenter = { lat: 30.0444, lng: 31.2357 };

        window.gm_authFailure = function() { showApiError(); };

        function showApiError() {
            const el = document.getElementById('addressInput');
            if (!el.parentNode.querySelector('.api-error-msg')) {
                const d = document.createElement('div');
                d.className = 'alert alert-warning mt-2 py-1 api-error-msg';
                d.innerHTML = '<small>فشل تحميل Google Maps API</small>';
                el.parentNode.appendChild(d);
            }
        }

        function getTownFromComponents(c) {
            for (const t of ['locality', 'administrative_area_level_2', 'administrative_area_level_1']) {
                const comp = c.find(x => x.types && x.types.includes(t));
                if (comp) return comp.long_name;
            }
            return '';
        }

        function initAutocomplete() {
            try {
                const el = document.getElementById('addressInput');
                el.disabled = false;
                if (typeof google !== 'undefined' && google.maps && google.maps.places) {
                    autocomplete = new google.maps.places.Autocomplete(el, {
                        componentRestrictions: { country: 'eg' }, language: 'ar',
                        fields: ['geometry', 'formatted_address', 'name']
                    });
                    geocoder = new google.maps.Geocoder();
                    autocomplete.addListener('place_changed', function() {
                        const place = autocomplete.getPlace();
                        if (place.geometry) {
                            const lat = place.geometry.location.lat(), lng = place.geometry.location.lng();
                            document.getElementById('latitude').value = lat;
                            document.getElementById('longitude').value = lng;
                            document.getElementById('town').value = place.address_components ? getTownFromComponents(place.address_components) : '';
                            if (place.formatted_address) el.value = place.formatted_address;
                            updateMapLocation(lat, lng);
                        }
                    });
                } else { showApiError(); }
            } catch (e) { showApiError(); }
        }

        function initMap() {
            if (typeof google === 'undefined' || !google.maps) return;
            const el = document.getElementById('customerMap');
            if (!el) return;
            map = new google.maps.Map(el, { center: defaultCenter, zoom: 12, mapTypeControl: true, streetViewControl: false, fullscreenControl: true });
            marker = new google.maps.Marker({ map, draggable: true, icon: { url: 'http://maps.google.com/mapfiles/ms/icons/blue-dot.png' } });
            marker.addListener('dragend', function() {
                const pos = marker.getPosition();
                document.getElementById('latitude').value = pos.lat();
                document.getElementById('longitude').value = pos.lng();
                if (geocoder) {
                    geocoder.geocode({ location: pos }, function(r, s) {
                        if (s === 'OK' && r[0]) {
                            document.getElementById('addressInput').value = r[0].formatted_address;
                            document.getElementById('town').value = r[0].address_components ? getTownFromComponents(r[0].address_components) : '';
                        }
                    });
                }
            });
            map.addListener('click', function(e) {
                updateMapLocation(e.latLng.lat(), e.latLng.lng());
                if (geocoder) {
                    geocoder.geocode({ location: e.latLng }, function(r, s) {
                        if (s === 'OK' && r[0]) {
                            document.getElementById('addressInput').value = r[0].formatted_address;
                            document.getElementById('town').value = r[0].address_components ? getTownFromComponents(r[0].address_components) : '';
                        }
                    });
                }
            });
        }

        function updateMapLocation(lat, lng) {
            if (map && marker) {
                const pos = new google.maps.LatLng(lat, lng);
                marker.setPosition(pos); map.setCenter(pos); map.setZoom(15);
                document.getElementById('latitude').value = lat;
                document.getElementById('longitude').value = lng;
            }
        }

        function handleCoordsSearch() {
            const val = (document.getElementById('coordsInput').value || '').trim();
            const parts = val.split(',').map(p => p.trim());
            if (parts.length !== 2) { document.getElementById('coordsError').classList.remove('d-none'); return; }
            const lat = parseFloat(parts[0]), lng = parseFloat(parts[1]);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) { document.getElementById('coordsError').classList.remove('d-none'); return; }
            document.getElementById('coordsError').classList.add('d-none');
            updateMapLocation(lat, lng);
            if (geocoder) {
                geocoder.geocode({ location: { lat, lng } }, function(r, s) {
                    if (s === 'OK' && r[0]) {
                        document.getElementById('addressInput').value = r[0].formatted_address;
                        document.getElementById('town').value = r[0].address_components ? getTownFromComponents(r[0].address_components) : '';
                    }
                });
            }
        }

        function startEdit(customer) {
            document.getElementById('formAction').value = 'edit';
            document.getElementById('editId').value = customer.id;
            document.getElementById('formCustomerNumber').value = customer.customer_number || '';
            document.getElementById('formName').value = customer.name || '';
            document.getElementById('formPhone').value = customer.phone || '';
            document.getElementById('addressInput').value = customer.address || '';
            document.getElementById('formNotes').value = customer.notes || '';
            document.getElementById('latitude').value = customer.latitude || '';
            document.getElementById('longitude').value = customer.longitude || '';
            document.getElementById('town').value = customer.town || '';
            document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil"></i> تعديل العميل #' + (customer.customer_number || customer.id);
            document.getElementById('formSubmitBtn').innerHTML = '<i class="bi bi-check-circle"></i> حفظ التعديلات';
            document.getElementById('formCancelBtn').classList.remove('d-none');
            if (customer.latitude && customer.longitude) updateMapLocation(parseFloat(customer.latitude), parseFloat(customer.longitude));
            document.getElementById('customerFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function cancelEdit() {
            document.getElementById('formAction').value = 'add';
            document.getElementById('editId').value = '';
            document.getElementById('customerForm').reset();
            document.getElementById('formTitle').innerHTML = '<i class="bi bi-person-plus"></i> إضافة عميل جديد';
            document.getElementById('formSubmitBtn').innerHTML = '<i class="bi bi-check-circle"></i> إضافة';
            document.getElementById('formCancelBtn').classList.add('d-none');
        }

        function waitForGoogleMaps() {
            if (typeof google !== 'undefined' && google.maps && google.maps.places) { initAutocomplete(); initMap(); }
            else setTimeout(waitForGoogleMaps, 100);
        }

        window.addEventListener('load', function() {
            waitForGoogleMaps();
            setTimeout(function() { if (typeof google === 'undefined' || !google.maps) showApiError(); }, 5000);
        });

        document.getElementById('coordsSearchBtn')?.addEventListener('click', handleCoordsSearch);
        document.getElementById('coordsInput')?.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); handleCoordsSearch(); } });

        document.getElementById('customerForm').addEventListener('submit', function(e) {
            const lat = document.getElementById('latitude').value;
            const lng = document.getElementById('longitude').value;
            const addr = document.getElementById('addressInput').value;
            if ((!lat || !lng) && addr && typeof google !== 'undefined' && google.maps) {
                e.preventDefault();
                if (!geocoder) geocoder = new google.maps.Geocoder();
                geocoder.geocode({ address: addr, region: 'eg' }, function(r, s) {
                    if (s === 'OK' && r[0]) {
                        document.getElementById('latitude').value = r[0].geometry.location.lat();
                        document.getElementById('longitude').value = r[0].geometry.location.lng();
                    }
                    document.getElementById('customerForm').submit();
                });
            }
        });

        // Client-side table: search, sort, pagination
        const allCustData = <?php echo json_encode(array_map(function($c) {
            return [
                'id' => $c['id'],
                'cn' => $c['customer_number'] ?? (string)$c['id'],
                'name' => $c['name'],
                'phone' => $c['phone'] ?? '',
                'address' => $c['address'] ?? '',
                'latitude' => $c['latitude'] ?? '',
                'longitude' => $c['longitude'] ?? '',
                'notes' => $c['notes'] ?? '',
                'town' => $c['town'] ?? '',
            ];
        }, $allCustomers), JSON_UNESCAPED_UNICODE); ?>;

        let perPage = 20;
        let currentPage = 1;
        let searchQuery = '';
        let sortCol = 'customer_number';
        let sortDir = 'asc';

        function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

        function extractTrailingNum(addr) {
            if (!addr) return '';
            const m = addr.match(/(\d+)\s*$/);
            return m ? m[1] : '';
        }

        function getFiltered() {
            let items = allCustData;
            if (searchQuery) {
                const q = searchQuery.toLowerCase();
                items = items.filter(c =>
                    c.cn.toLowerCase().includes(q) ||
                    c.name.toLowerCase().includes(q) ||
                    c.phone.toLowerCase().includes(q) ||
                    c.address.toLowerCase().includes(q)
                );
            }
            items = [...items].sort((a, b) => {
                let va, vb;
                if (sortCol === 'customer_number') {
                    va = parseInt(a.cn) || 0; vb = parseInt(b.cn) || 0;
                    if (va !== vb) return sortDir === 'asc' ? va - vb : vb - va;
                    va = a.cn; vb = b.cn;
                } else if (sortCol === 'name') {
                    va = a.name.toLowerCase(); vb = b.name.toLowerCase();
                } else if (sortCol === 'phone') {
                    va = a.phone.toLowerCase(); vb = b.phone.toLowerCase();
                } else {
                    va = ''; vb = '';
                }
                if (va < vb) return sortDir === 'asc' ? -1 : 1;
                if (va > vb) return sortDir === 'asc' ? 1 : -1;
                return 0;
            });
            return items;
        }

        function renderCustomerTable() {
            const filtered = getFiltered();
            const total = filtered.length;
            const totalPages = Math.max(1, Math.ceil(total / perPage));
            if (currentPage > totalPages) currentPage = totalPages;
            const start = (currentPage - 1) * perPage;
            const page = filtered.slice(start, start + perPage);

            const tbody = document.getElementById('customerTableBody');
            if (page.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">لا يوجد عملاء</td></tr>';
            } else {
                tbody.innerHTML = page.map(c => {
                    const addrNum = extractTrailingNum(c.address);
                    const hasCoords = c.latitude && c.longitude && !isNaN(c.latitude) && !isNaN(c.longitude);
                    let qrHtml = '-';
                    if (hasCoords) {
                        const mUrl = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(c.latitude + ',' + c.longitude);
                        const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=60x60&data=' + encodeURIComponent(mUrl);
                        qrHtml = '<img src="' + qrUrl + '" alt="QR" style="width:40px;height:40px;" loading="lazy">';
                    }
                    const custJson = esc(JSON.stringify(c));
                    return '<tr>' +
                        '<td>' + esc(c.cn) + '</td>' +
                        '<td><strong>' + esc(c.name) + '</strong></td>' +
                        '<td>' + (esc(c.phone) || '-') + '</td>' +
                        '<td class="text-truncate" style="max-width:200px;" title="' + esc(c.address) + '">' + esc(c.address) + '</td>' +
                        '<td>' + (addrNum ? esc(addrNum) : '-') + '</td>' +
                        '<td><small>-</small></td>' +
                        '<td>' + qrHtml + '</td>' +
                        '<td><div class="d-flex gap-1">' +
                            '<button type="button" class="btn btn-sm btn-outline-primary edit-btn" data-cust=\'' + JSON.stringify(c).replace(/'/g, '&#39;') + '\'><i class="bi bi-pencil"></i></button>' +
                            '<form method="POST" class="d-inline delete-form" data-name="' + esc(c.name) + '">' +
                                '<input type="hidden" name="action" value="delete">' +
                                '<input type="hidden" name="id" value="' + c.id + '">' +
                                '<button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>' +
                            '</form>' +
                        '</div></td></tr>';
                }).join('');
            }

            // Update sort icons
            ['customer_number', 'name', 'phone'].forEach(col => {
                const icon = document.getElementById('sortIcon-' + col);
                if (icon) icon.textContent = col === sortCol ? (sortDir === 'asc' ? '▲' : '▼') : '';
            });

            // Page info
            const pageText = 'صفحة ' + currentPage + ' من ' + totalPages + ' (' + total + ')';
            document.getElementById('customerPageInfo').textContent = pageText;
            document.getElementById('customerPageInfoTop').textContent = pageText;

            // Pagination (render to both top and bottom)
            ['customerPagination', 'customerPaginationTop'].forEach(elId => {
                const ul = document.getElementById(elId);
                ul.innerHTML = '';
                if (totalPages > 1) {
                    const mk = (label, pg, dis, act) => {
                        const li = document.createElement('li');
                        li.className = 'page-item' + (dis ? ' disabled' : '') + (act ? ' active' : '');
                        const a = document.createElement('a');
                        a.className = 'page-link'; a.href = '#'; a.textContent = label;
                        a.addEventListener('click', e => { e.preventDefault(); if (!dis && !act) { currentPage = pg; renderCustomerTable(); } });
                        li.appendChild(a); ul.appendChild(li);
                    };
                    mk('‹', currentPage - 1, currentPage <= 1, false);
                    let s = Math.max(1, currentPage - 2), e = Math.min(totalPages, currentPage + 2);
                    if (s > 1) mk('…', 1, true, false);
                    for (let p = s; p <= e; p++) mk(p, p, false, p === currentPage);
                    if (e < totalPages) mk('…', totalPages, true, false);
                    mk('›', currentPage + 1, currentPage >= totalPages, false);
                }
            });

            // Bind edit buttons
            tbody.querySelectorAll('.edit-btn').forEach(btn => {
                btn.addEventListener('click', () => startEdit(JSON.parse(btn.dataset.cust)));
            });

            // Bind delete forms
            tbody.querySelectorAll('.delete-form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const name = form.getAttribute('data-name') || '';
                    confirmSubmit(form, { title: 'حذف عميل', message: 'هل أنت متأكد من حذف العميل "' + name + '"؟', btnText: 'نعم، حذف' });
                });
            });
        }

        // Search input
        document.getElementById('customerSearchInput').addEventListener('input', function() {
            searchQuery = this.value;
            currentPage = 1;
            renderCustomerTable();
        });

        // Sort headers
        document.querySelectorAll('.sortable-th').forEach(th => {
            th.addEventListener('click', function() {
                const col = this.dataset.col;
                if (sortCol === col) {
                    sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortCol = col;
                    sortDir = 'asc';
                }
                renderCustomerTable();
            });
        });

        // Per-page selector
        document.getElementById('perPageSelect').addEventListener('change', function() {
            perPage = parseInt(this.value);
            currentPage = 1;
            renderCustomerTable();
        });

        renderCustomerTable();
    </script>
<?php require_once 'footer.php'; ?>
