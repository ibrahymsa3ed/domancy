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

function reverseGeocodeGovernorate($latitude, $longitude, $apiKey) {
    if (empty($apiKey)) {
        return '';
    }
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
        'latlng' => $latitude . ',' . $longitude,
        'language' => 'ar',
        'key' => $apiKey,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false || $err) {
        return '';
    }

    $data = json_decode($response, true);
    if (!isset($data['results'][0]['address_components'])) {
        return '';
    }

    foreach ($data['results'][0]['address_components'] as $comp) {
        if (!empty($comp['types']) && in_array('administrative_area_level_1', $comp['types'], true)) {
            return $comp['long_name'] ?? '';
        }
    }

    return '';
}

// Handle form submission
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
            $governorate = $_POST['governorate'] ?? '';

            if ($name && $address && $latitude && $longitude) {
                try {
                    $stmt = getDB()->prepare("INSERT INTO customers (name, phone, address, town, governorate, latitude, longitude, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $phone, $address, $town, $governorate, $latitude, $longitude, $notes]);
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
        } elseif ($_POST['action'] === 'bulk_update_governorates') {
            $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 100;
            $limit = max(1, min(500, $limit));
            $apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
            if (empty($apiKey)) {
                $message = "يرجى ضبط مفتاح Google Maps API أولاً";
                $messageType = "warning";
            } else {
                try {
                    $stmt = getDB()->prepare("
                        SELECT id, latitude, longitude
                        FROM customers
                        WHERE (governorate IS NULL OR governorate = '')
                          AND latitude IS NOT NULL AND longitude IS NOT NULL
                        LIMIT ?
                    ");
                    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
                    $stmt->execute();
                    $rows = $stmt->fetchAll();

                    $updated = 0;
                    $failed = 0;
                    $updateStmt = getDB()->prepare("UPDATE customers SET governorate = ? WHERE id = ?");
                    foreach ($rows as $row) {
                        $gov = reverseGeocodeGovernorate($row['latitude'], $row['longitude'], $apiKey);
                        if ($gov !== '') {
                            $updateStmt->execute([$gov, $row['id']]);
                            $updated += 1;
                        } else {
                            $failed += 1;
                        }
                        usleep(120000);
                    }

                    $message = "تم تحديث المحافظة لـ {$updated} عميل";
                    if ($failed > 0) {
                        $message .= "، وفشل {$failed} عميل";
                    }
                    $messageType = "success";
                } catch (PDOException $e) {
                    $message = "خطأ في التحديث الجماعي: " . $e->getMessage();
                    $messageType = "danger";
                }
            }
        } elseif ($_POST['action'] === 'seed_customers') {
            try {
                $factory = getDB()->query("SELECT latitude, longitude FROM factory LIMIT 1")->fetch();
                $baseLat = $factory ? (float) $factory['latitude'] : 30.0444;
                $baseLng = $factory ? (float) $factory['longitude'] : 31.2357;
                $town = 'القاهرة';
                $gov = 'القاهرة';
                $stmt = getDB()->prepare("INSERT INTO customers (name, phone, address, town, governorate, latitude, longitude, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                for ($i = 1; $i <= 10; $i++) {
                    $name = 'عميل عشوائي ' . $i;
                    $phone = '01' . random_int(100000000, 999999999);
                    $addrNum = random_int(1000000, 9999999);
                    $address = 'عنوان تجريبي ' . $i . ' محافظة القاهرة ' . $addrNum;
                    $lat = $baseLat + (random_int(-120, 120) / 1000);
                    $lng = $baseLng + (random_int(-120, 120) / 1000);
                    $stmt->execute([$name, $phone, $address, $town, $gov, $lat, $lng, '']);
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

// Get all customers
$customers = getDB()->query("SELECT * FROM customers ORDER BY name")->fetchAll();

// Set variables for header
$pageTitle = APP_NAME . ' - العملاء';
$googleMapsScript = 'places,geometry';
require_once 'header.php';
?>

    <div class="container mt-4">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-person-plus"></i> إضافة عميل جديد</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="customerForm">
                            <input type="hidden" name="action" value="add">
                            <div class="mb-3">
                                <label class="form-label">اسم العميل *</label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">الهاتف</label>
                                <input type="text" class="form-control" name="phone">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">العنوان *</label>
                                <input type="text" class="form-control" id="addressInput" name="address" required>
                                <small class="text-muted">اكتب العنوان أو انقر على الخريطة لتحديد الموقع</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">المحافظة</label>
                                <input type="text" class="form-control" name="governorate" id="governorateInput" placeholder="مثال: القاهرة">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">بحث بالإحداثيات</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="coordsInput" placeholder="مثال: 30.0444, 31.2357">
                                    <button type="button" class="btn btn-outline-primary" id="coordsSearchBtn">
                                        بحث
                                    </button>
                                </div>
                                <small class="text-muted">أدخل خط العرض والطول للبحث في الخريطة</small>
                                <div class="text-danger small mt-1 d-none" id="coordsError">يرجى إدخال إحداثيات صحيحة</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">موقع على الخريطة</label>
                                <div id="customerMap" style="height: 300px; width: 100%; border: 1px solid #ddd; border-radius: 4px;"></div>
                                <small class="text-muted">انقر على الخريطة أو اسحب العلامة لتحديد الموقع</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">ملاحظات</label>
                                <textarea class="form-control" name="notes" rows="2"></textarea>
                            </div>
                            <input type="hidden" name="latitude" id="latitude">
                            <input type="hidden" name="longitude" id="longitude">
                            <input type="hidden" name="town" id="town">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-check-circle"></i> إضافة عميل
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-list-ul"></i> قائمة العملاء (<?php echo count($customers); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-2">
                            <input type="text" class="form-control" id="customersSearchInput" placeholder="بحث بالاسم أو العنوان أو رقم الموقع أو رقم العميل">
                        </div>
                        <form method="POST" class="d-flex align-items-end gap-2 mb-3">
                            <input type="hidden" name="action" value="bulk_update_governorates">
                            <div>
                                <label class="form-label">تحديث المحافظات (عدد)</label>
                                <input type="number" class="form-control" name="limit" value="100" min="1" max="500">
                            </div>
                            <button type="submit" class="btn btn-outline-primary">
                                تحديث المحافظات تلقائياً
                            </button>
                        </form>
                        <form method="POST" class="mb-3">
                            <input type="hidden" name="action" value="seed_customers">
                            <button type="submit" class="btn btn-outline-secondary">
                                إضافة 10 عملاء تجريبيين
                            </button>
                        </form>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>رقم العميل</th>
                                        <th>الاسم</th>
                                        <th>الهاتف</th>
                                        <th>العنوان</th>
                                        <th>رقم الموقع</th>
                                        <th>المحافظة</th>
                                        <th>الرمز العالمي</th>
                                        <th>QR</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($customers)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">لا يوجد عملاء</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($customers as $customer): ?>
                                        <?php
                                            $addressNumber = extractTrailingNumber($customer['address']);
                                            $plusCode = encodePlusCode($customer['latitude'], $customer['longitude']);
                                        ?>
                                        <tr data-search="<?php echo htmlspecialchars(mb_strtolower(
                                            $customer['name'] . ' ' .
                                            ($customer['address'] ?? '') . ' ' .
                                            ($customer['phone'] ?? '') . ' ' .
                                            $customer['id'] . ' ' .
                                            $addressNumber . ' ' .
                                            $plusCode . ' ' .
                                            ($customer['governorate'] ?? '')
                                        )); ?>">
                                            <td><?php echo htmlspecialchars($customer['id']); ?></td>
                                                <td><strong><?php echo htmlspecialchars($customer['name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($customer['phone'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($customer['address']); ?></td>
                                            <td><?php echo $addressNumber !== '' ? htmlspecialchars($addressNumber) : '-'; ?></td>
                                            <td><?php echo !empty($customer['governorate']) ? htmlspecialchars($customer['governorate']) : '-'; ?></td>
                                            <td><?php echo $plusCode !== '' ? htmlspecialchars($plusCode) : '-'; ?></td>
                                        <td>
                                            <?php if (is_numeric($customer['latitude']) && is_numeric($customer['longitude'])): ?>
                                                <?php
                                                    $mapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($customer['latitude'] . ',' . $customer['longitude']);
                                                    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . rawurlencode($mapsUrl);
                                                ?>
                                                <img src="<?php echo $qrUrl; ?>" alt="QR" style="width: 60px; height: 60px; object-fit: contain;">
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                            <td>
                                                <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo $customer['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger">
                                                            <i class="bi bi-trash"></i> حذف
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Google Maps Autocomplete for address
        let autocomplete;
        let geocoder;
        let map;
        let marker;
        let defaultCenter = { lat: 30.0444, lng: 31.2357 }; // Cairo, Egypt

        // Error handler for Google Maps API
        window.gm_authFailure = function() {
            console.error('Google Maps API authentication failed. Please check your API key.');
            showApiError();
        };

        function showApiError() {
            const addressInput = document.getElementById('addressInput');
            // Check if error message already exists
            if (!addressInput.parentNode.querySelector('.api-error-msg')) {
                const errorMsg = document.createElement('div');
                errorMsg.className = 'alert alert-warning mt-2 api-error-msg';
                errorMsg.innerHTML = '<small>⚠️ فشل تحميل Google Maps API. يمكنك إدخال العنوان يدوياً.</small>';
                addressInput.parentNode.appendChild(errorMsg);
            }
        }

        function getTownFromComponents(components) {
            const typesPriority = ['locality', 'administrative_area_level_2', 'administrative_area_level_1'];
            for (const type of typesPriority) {
                const comp = components.find(c => c.types && c.types.includes(type));
                if (comp) {
                    return comp.long_name;
                }
            }
            return '';
        }

        function getGovernorateFromComponents(components) {
            const comp = components.find(c => c.types && c.types.includes('administrative_area_level_1'));
            return comp ? comp.long_name : '';
        }

        function initAutocomplete() {
            try {
                const addressInput = document.getElementById('addressInput');
                
                // Make sure input is always enabled and visible
                addressInput.disabled = false;
                addressInput.style.opacity = '1';
                addressInput.style.backgroundColor = '';
                
                if (typeof google !== 'undefined' && google.maps && google.maps.places) {
            autocomplete = new google.maps.places.Autocomplete(
                        addressInput,
                        { 
                            componentRestrictions: { country: 'eg' }, 
                            language: 'ar',
                            fields: ['geometry', 'formatted_address', 'name']
                        }
            );

            geocoder = new google.maps.Geocoder();

            autocomplete.addListener('place_changed', function() {
                const place = autocomplete.getPlace();
                if (place.geometry) {
                            const lat = place.geometry.location.lat();
                            const lng = place.geometry.location.lng();
                            document.getElementById('latitude').value = lat;
                            document.getElementById('longitude').value = lng;
                            document.getElementById('town').value = place.address_components ? getTownFromComponents(place.address_components) : '';
                            const govInput = document.getElementById('governorateInput');
                            if (govInput && !govInput.value) {
                                govInput.value = place.address_components ? getGovernorateFromComponents(place.address_components) : '';
                            }
                            // Update address field with formatted address
                            if (place.formatted_address) {
                                addressInput.value = place.formatted_address;
                            }
                            // Update map
                            updateMapLocation(lat, lng);
                        }
                    });

                    // Prevent input from being disabled or dimmed
                    addressInput.addEventListener('focus', function() {
                        this.disabled = false;
                        this.style.opacity = '1';
                        this.style.backgroundColor = '';
                    });
                    
                    addressInput.addEventListener('input', function() {
                        this.disabled = false;
                        this.style.opacity = '1';
                        this.style.backgroundColor = '';
                    });
                } else {
                    console.warn('Google Maps Places API not loaded');
                    showApiError();
                }
            } catch (error) {
                console.error('Error initializing autocomplete:', error);
                showApiError();
                // Ensure input is still usable
                const addressInput = document.getElementById('addressInput');
                addressInput.disabled = false;
                addressInput.style.opacity = '1';
                addressInput.style.backgroundColor = '';
            }
        }

        // Initialize map
        function initMap() {
            if (typeof google === 'undefined' || !google.maps) {
                return;
            }

            const mapElement = document.getElementById('customerMap');
            if (!mapElement) {
                return;
            }

            map = new google.maps.Map(mapElement, {
                center: defaultCenter,
                zoom: 12,
                mapTypeControl: true,
                streetViewControl: true,
                fullscreenControl: true
            });

            // Add marker
            marker = new google.maps.Marker({
                map: map,
                draggable: true,
                animation: google.maps.Animation.DROP,
                icon: {
                    url: 'http://maps.google.com/mapfiles/ms/icons/blue-dot.png'
                }
            });

            // Update coordinates when marker is dragged
            marker.addListener('dragend', function() {
                const position = marker.getPosition();
                document.getElementById('latitude').value = position.lat();
                document.getElementById('longitude').value = position.lng();
                
                // Reverse geocode to get address
                if (geocoder) {
                    geocoder.geocode({ location: position }, function(results, status) {
                        if (status === 'OK' && results[0]) {
                            document.getElementById('addressInput').value = results[0].formatted_address;
                            document.getElementById('town').value = results[0].address_components ? getTownFromComponents(results[0].address_components) : '';
                            const govInput = document.getElementById('governorateInput');
                            if (govInput && !govInput.value) {
                                govInput.value = results[0].address_components ? getGovernorateFromComponents(results[0].address_components) : '';
                            }
                        }
                    });
                }
            });

            // Update marker when clicking on map
            map.addListener('click', function(event) {
                const lat = event.latLng.lat();
                const lng = event.latLng.lng();
                updateMapLocation(lat, lng);
                
                // Reverse geocode to get address
                if (geocoder) {
                    geocoder.geocode({ location: event.latLng }, function(results, status) {
                        if (status === 'OK' && results[0]) {
                            document.getElementById('addressInput').value = results[0].formatted_address;
                            document.getElementById('town').value = results[0].address_components ? getTownFromComponents(results[0].address_components) : '';
                            const govInput = document.getElementById('governorateInput');
                            if (govInput && !govInput.value) {
                                govInput.value = results[0].address_components ? getGovernorateFromComponents(results[0].address_components) : '';
                            }
                        }
                    });
                }
            });
        }

        // Update map location
        function updateMapLocation(lat, lng) {
            if (map && marker) {
                const position = new google.maps.LatLng(lat, lng);
                marker.setPosition(position);
                map.setCenter(position);
                map.setZoom(15);
                
                document.getElementById('latitude').value = lat;
                document.getElementById('longitude').value = lng;
            }
        }

        function parseCoordinates(input) {
            if (!input) return null;
            const parts = input.split(',').map(part => part.trim());
            if (parts.length !== 2) return null;
            const lat = parseFloat(parts[0]);
            const lng = parseFloat(parts[1]);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;
            return { lat, lng };
        }

        function showCoordsError(show) {
            const errorEl = document.getElementById('coordsError');
            if (!errorEl) return;
            errorEl.classList.toggle('d-none', !show);
        }

        function handleCoordsSearch() {
            const input = document.getElementById('coordsInput');
            const value = input ? input.value : '';
            const coords = parseCoordinates(value);
            if (!coords) {
                showCoordsError(true);
                return;
            }
            showCoordsError(false);
            updateMapLocation(coords.lat, coords.lng);
            if (geocoder) {
                geocoder.geocode({ location: coords }, function(results, status) {
                    if (status === 'OK' && results[0]) {
                        document.getElementById('addressInput').value = results[0].formatted_address;
                        document.getElementById('town').value = results[0].address_components ? getTownFromComponents(results[0].address_components) : '';
                    }
                });
            }
        }

        // Wait for Google Maps API to load
        function waitForGoogleMaps() {
            if (typeof google !== 'undefined' && google.maps && google.maps.places) {
                initAutocomplete();
                initMap();
            } else {
                // Retry after a short delay
                setTimeout(waitForGoogleMaps, 100);
            }
        }

        // Initialize when page loads
        window.addEventListener('load', function() {
            // Start checking for Google Maps API
            waitForGoogleMaps();
            
            // Timeout after 5 seconds if API doesn't load
            setTimeout(function() {
                if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
                    console.error('Google Maps API failed to load after timeout');
                    showApiError();
                }
            }, 5000);
        });

        const coordsBtn = document.getElementById('coordsSearchBtn');
        if (coordsBtn) {
            coordsBtn.addEventListener('click', handleCoordsSearch);
        }
        const coordsInput = document.getElementById('coordsInput');
        if (coordsInput) {
            coordsInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    handleCoordsSearch();
                }
            });
        }

        // Manual geocoding fallback if autocomplete fails
        function geocodeAddress(address) {
            if (!geocoder) {
                if (typeof google !== 'undefined' && google.maps) {
                    geocoder = new google.maps.Geocoder();
                } else {
                    return;
                }
            }
            
            geocoder.geocode({ address: address, region: 'eg' }, function(results, status) {
                if (status === 'OK' && results[0]) {
                    document.getElementById('latitude').value = results[0].geometry.location.lat();
                    document.getElementById('longitude').value = results[0].geometry.location.lng();
                }
            });
        }

        // Add manual geocoding on form submit if coordinates are missing
        document.getElementById('customerForm').addEventListener('submit', function(e) {
            const lat = document.getElementById('latitude').value;
            const lng = document.getElementById('longitude').value;
            const address = document.getElementById('addressInput').value;
            
            if ((!lat || !lng) && address && typeof google !== 'undefined' && google.maps) {
                e.preventDefault();
                geocodeAddress(address);
                // Retry submit after geocoding
                setTimeout(function() {
                    document.getElementById('customerForm').submit();
                }, 500);
            }
        });

        const searchInput = document.getElementById('customersSearchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const query = this.value.trim().toLowerCase();
                const rows = document.querySelectorAll('table tbody tr[data-search]');
                rows.forEach(row => {
                    const haystack = row.getAttribute('data-search') || '';
                    row.style.display = haystack.includes(query) ? '' : 'none';
                });
            });
        }
    </script>
<?php require_once 'footer.php'; ?>

