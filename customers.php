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
            $name = $_POST['name'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $address = $_POST['address'] ?? '';
            $latitude = $_POST['latitude'] ?? '';
            $longitude = $_POST['longitude'] ?? '';
            $notes = $_POST['notes'] ?? '';
            $town = $_POST['town'] ?? '';

            if ($name && $address && $latitude && $longitude) {
                try {
                    $stmt = getDB()->prepare("INSERT INTO customers (name, phone, address, town, latitude, longitude, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $phone, $address, $town, $latitude, $longitude, $notes]);
                    $message = "تم إضافة العميل بنجاح";
                    $messageType = "success";
                } catch (PDOException $e) {
                    $message = "خطأ في إضافة العميل: " . $e->getMessage();
                    $messageType = "danger";
                }
            } else {
                $message = "يرجى ملء جميع الحقول المطلوبة";
                $messageType = "warning";
            }
        } elseif ($_POST['action'] === 'edit') {
            $id = $_POST['id'] ?? 0;
            $name = $_POST['name'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $address = $_POST['address'] ?? '';
            $latitude = $_POST['latitude'] ?? '';
            $longitude = $_POST['longitude'] ?? '';
            $notes = $_POST['notes'] ?? '';
            $town = $_POST['town'] ?? '';

            if ($id && $name && $address) {
                try {
                    $stmt = getDB()->prepare("UPDATE customers SET name = ?, phone = ?, address = ?, town = ?, latitude = ?, longitude = ?, notes = ? WHERE id = ?");
                    $stmt->execute([$name, $phone, $address, $town, $latitude ?: null, $longitude ?: null, $notes, $id]);
                    $message = "تم تحديث بيانات العميل بنجاح";
                    $messageType = "success";
                } catch (PDOException $e) {
                    $message = "خطأ في تحديث العميل: " . $e->getMessage();
                    $messageType = "danger";
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
                $stmt = getDB()->prepare("INSERT INTO customers (name, phone, address, town, latitude, longitude, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                for ($i = 1; $i <= 10; $i++) {
                    $name = 'عميل عشوائي ' . $i;
                    $phone = '01' . random_int(100000000, 999999999);
                    $addrNum = random_int(1000000, 9999999);
                    $address = 'عنوان تجريبي ' . $i . ' محافظة القاهرة ' . $addrNum;
                    $lat = $baseLat + (random_int(-120, 120) / 1000);
                    $lng = $baseLng + (random_int(-120, 120) / 1000);
                    $stmt->execute([$name, $phone, $address, $town, $lat, $lng, '']);
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

// Pagination + Sort
$search = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$sortCol = $_GET['sort'] ?? 'id';
$sortDir = strtolower($_GET['dir'] ?? 'asc');
$allowedSorts = ['id', 'name', 'phone', 'address'];
if (!in_array($sortCol, $allowedSorts)) $sortCol = 'id';
if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'asc';

$countSql = "SELECT COUNT(*) FROM customers";
$dataSql = "SELECT * FROM customers";
$params = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $idExact = is_numeric($search) ? (int) $search : null;
    if ($idExact !== null) {
        $where = " WHERE (id = ? OR name LIKE ? OR address LIKE ? OR phone LIKE ?)";
        $params = [$idExact, $like, $like, $like];
    } else {
        $where = " WHERE (name LIKE ? OR address LIKE ? OR phone LIKE ?)";
        $params = [$like, $like, $like];
    }
    $countSql .= $where;
    $dataSql .= $where;
}

$dataSql .= " ORDER BY $sortCol $sortDir LIMIT $perPage OFFSET $offset";

$countStmt = getDB()->prepare($countSql);
$countStmt->execute($params);
$totalCustomers = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCustomers / $perPage));

$dataStmt = getDB()->prepare($dataSql);
$dataStmt->execute($params);
$customers = $dataStmt->fetchAll();

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
                        <div class="p-2">
                            <form method="GET" class="d-flex gap-2">
                                <input type="hidden" name="sort" value="<?php echo $sortCol; ?>">
                                <input type="hidden" name="dir" value="<?php echo $sortDir; ?>">
                                <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="بحث برقم العميل أو الاسم أو الهاتف أو العنوان...">
                                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
                                <?php if ($search !== ''): ?>
                                    <a href="customers.php?sort=<?php echo $sortCol; ?>&dir=<?php echo $sortDir; ?>" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                                <?php endif; ?>
                            </form>
                        </div>
                        <div class="table-responsive">
                            <?php
                                $sortParams = ($search !== '' ? '&q=' . urlencode($search) : '');
                                function sortUrl($col, $curCol, $curDir, $extra) {
                                    $dir = ($col === $curCol && $curDir === 'asc') ? 'desc' : 'asc';
                                    return '?sort=' . $col . '&dir=' . $dir . $extra;
                                }
                                function sortIcon($col, $curCol, $curDir) {
                                    if ($col !== $curCol) return '';
                                    return $curDir === 'asc' ? ' ▲' : ' ▼';
                                }
                            ?>
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th><a href="<?php echo sortUrl('id', $sortCol, $sortDir, $sortParams); ?>" class="text-decoration-none">#<?php echo sortIcon('id', $sortCol, $sortDir); ?></a></th>
                                        <th><a href="<?php echo sortUrl('name', $sortCol, $sortDir, $sortParams); ?>" class="text-decoration-none">الاسم<?php echo sortIcon('name', $sortCol, $sortDir); ?></a></th>
                                        <th><a href="<?php echo sortUrl('phone', $sortCol, $sortDir, $sortParams); ?>" class="text-decoration-none">الهاتف<?php echo sortIcon('phone', $sortCol, $sortDir); ?></a></th>
                                        <th>العنوان</th>
                                        <th>رقم الموقع</th>
                                        <th>الرمز العالمي</th>
                                        <th>QR</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($customers)): ?>
                                        <tr><td colspan="8" class="text-center text-muted py-4">لا يوجد عملاء</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($customers as $customer): ?>
                                        <?php
                                            $addressNumber = extractTrailingNumber($customer['address']);
                                            $plusCode = encodePlusCode($customer['latitude'], $customer['longitude']);
                                        ?>
                                        <tr>
                                            <td><?php echo $customer['id']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($customer['name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($customer['phone'] ?? '-'); ?></td>
                                            <td class="text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($customer['address']); ?>"><?php echo htmlspecialchars($customer['address']); ?></td>
                                            <td><?php echo $addressNumber !== '' ? htmlspecialchars($addressNumber) : '-'; ?></td>
                                            <td><small><?php echo $plusCode !== '' ? htmlspecialchars($plusCode) : '-'; ?></small></td>
                                            <td>
                                                <?php if (is_numeric($customer['latitude']) && is_numeric($customer['longitude'])): ?>
                                                    <?php
                                                        $mapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($customer['latitude'] . ',' . $customer['longitude']);
                                                        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=60x60&data=' . rawurlencode($mapsUrl);
                                                    ?>
                                                    <img src="<?php echo $qrUrl; ?>" alt="QR" style="width: 40px; height: 40px;" loading="lazy">
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-1">
                                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="startEdit(<?php echo htmlspecialchars(json_encode($customer, JSON_UNESCAPED_UNICODE)); ?>)">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <form method="POST" class="d-inline delete-form" data-name="<?php echo htmlspecialchars($customer['name']); ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo $customer['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($totalPages > 1): ?>
                        <div class="d-flex justify-content-between align-items-center p-2 border-top">
                            <small class="text-muted">صفحة <?php echo $page; ?> من <?php echo $totalPages; ?></small>
                            <nav>
                                <ul class="pagination pagination-sm mb-0">
                                    <?php
                                        $qParam = ($search !== '' ? '&q=' . urlencode($search) : '') . '&sort=' . $sortCol . '&dir=' . $sortDir;
                                    ?>
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $qParam; ?>">‹</a>
                                    </li>
                                    <?php
                                        $startP = max(1, $page - 2);
                                        $endP = min($totalPages, $page + 2);
                                        if ($startP > 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                        for ($p = $startP; $p <= $endP; $p++):
                                    ?>
                                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $p; ?><?php echo $qParam; ?>"><?php echo $p; ?></a>
                                        </li>
                                    <?php endfor;
                                        if ($endP < $totalPages) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                    ?>
                                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $qParam; ?>">›</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let autocomplete, geocoder, map, marker;
        const defaultCenter = { lat: 30.0444, lng: 31.2357 };

        window.gm_authFailure = function() {
            showApiError();
        };

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
                        componentRestrictions: { country: 'eg' },
                        language: 'ar',
                        fields: ['geometry', 'formatted_address', 'name']
                    });
                    geocoder = new google.maps.Geocoder();
                    autocomplete.addListener('place_changed', function() {
                        const place = autocomplete.getPlace();
                        if (place.geometry) {
                            const lat = place.geometry.location.lat();
                            const lng = place.geometry.location.lng();
                            document.getElementById('latitude').value = lat;
                            document.getElementById('longitude').value = lng;
                            document.getElementById('town').value = place.address_components ? getTownFromComponents(place.address_components) : '';
                            if (place.formatted_address) el.value = place.formatted_address;
                            updateMapLocation(lat, lng);
                        }
                    });
                } else {
                    showApiError();
                }
            } catch (e) {
                showApiError();
            }
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
                marker.setPosition(pos);
                map.setCenter(pos);
                map.setZoom(15);
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

        // Edit mode
        function startEdit(customer) {
            document.getElementById('formAction').value = 'edit';
            document.getElementById('editId').value = customer.id;
            document.getElementById('formName').value = customer.name || '';
            document.getElementById('formPhone').value = customer.phone || '';
            document.getElementById('addressInput').value = customer.address || '';
            document.getElementById('formNotes').value = customer.notes || '';
            document.getElementById('latitude').value = customer.latitude || '';
            document.getElementById('longitude').value = customer.longitude || '';
            document.getElementById('town').value = customer.town || '';

            document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil"></i> تعديل العميل #' + customer.id;
            document.getElementById('formSubmitBtn').innerHTML = '<i class="bi bi-check-circle"></i> حفظ التعديلات';
            document.getElementById('formCancelBtn').classList.remove('d-none');

            if (customer.latitude && customer.longitude) {
                updateMapLocation(parseFloat(customer.latitude), parseFloat(customer.longitude));
            }

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
            if (typeof google !== 'undefined' && google.maps && google.maps.places) {
                initAutocomplete();
                initMap();
            } else {
                setTimeout(waitForGoogleMaps, 100);
            }
        }

        window.addEventListener('load', function() {
            waitForGoogleMaps();
            setTimeout(function() {
                if (typeof google === 'undefined' || !google.maps) showApiError();
            }, 5000);
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
        document.querySelectorAll('.delete-form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const name = form.getAttribute('data-name') || '';
                confirmSubmit(form, {
                    title: 'حذف عميل',
                    message: 'هل أنت متأكد من حذف العميل "' + name + '"؟',
                    btnText: 'نعم، حذف'
                });
            });
        });
    </script>
<?php require_once 'footer.php'; ?>
