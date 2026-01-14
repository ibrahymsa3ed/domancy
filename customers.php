<?php
require_once 'db.php';

$message = '';
$messageType = '';

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

            if ($name && $address && $latitude && $longitude) {
                try {
                    $stmt = getDB()->prepare("INSERT INTO customers (name, phone, address, latitude, longitude, notes) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $phone, $address, $latitude, $longitude, $notes]);
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
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>الاسم</th>
                                        <th>الهاتف</th>
                                        <th>العنوان</th>
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
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($customer['name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($customer['phone'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($customer['address']); ?></td>
                                                <td>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('هل أنت متأكد من حذف هذا العميل؟');">
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
    </script>
<?php require_once 'footer.php'; ?>
