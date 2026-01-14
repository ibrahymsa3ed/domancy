<?php
require_once 'db.php';

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $address = $_POST['address'] ?? '';
    $latitude = $_POST['latitude'] ?? '';
    $longitude = $_POST['longitude'] ?? '';

    if ($name && $address && $latitude && $longitude) {
        try {
            // Check if factory exists
            $existing = getDB()->query("SELECT id FROM factory LIMIT 1")->fetch();
            
            if ($existing) {
                // Update existing
                $stmt = getDB()->prepare("UPDATE factory SET name = ?, address = ?, latitude = ?, longitude = ? WHERE id = ?");
                $stmt->execute([$name, $address, $latitude, $longitude, $existing['id']]);
                $message = "تم تحديث معلومات المصنع بنجاح";
            } else {
                // Insert new
                $stmt = getDB()->prepare("INSERT INTO factory (name, address, latitude, longitude) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $address, $latitude, $longitude]);
                $message = "تم حفظ معلومات المصنع بنجاح";
            }
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "خطأ في حفظ معلومات المصنع: " . $e->getMessage();
            $messageType = "danger";
        }
    } else {
        $message = "يرجى ملء جميع الحقول المطلوبة";
        $messageType = "warning";
    }
}

// Get factory location
$factory = getDB()->query("SELECT * FROM factory LIMIT 1")->fetch();

// Set variables for header
$pageTitle = APP_NAME . ' - موقع المصنع';
$googleMapsScript = 'places';
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
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-building"></i> معلومات المصنع</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="factoryForm">
                            <div class="mb-3">
                                <label class="form-label">اسم المصنع *</label>
                                <input type="text" class="form-control" name="name" 
                                       value="<?php echo htmlspecialchars($factory['name'] ?? 'دومانسي'); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">عنوان المصنع *</label>
                                <input type="text" class="form-control" id="addressInput" name="address" 
                                       value="<?php echo htmlspecialchars($factory['address'] ?? ''); ?>" required>
                                <small class="text-muted">اكتب العنوان أو انقر على الخريطة لتحديد الموقع</small>
                            </div>
                            <input type="hidden" name="latitude" id="latitude" 
                                   value="<?php echo $factory['latitude'] ?? ''; ?>">
                            <input type="hidden" name="longitude" id="longitude" 
                                   value="<?php echo $factory['longitude'] ?? ''; ?>">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-check-circle"></i> حفظ معلومات المصنع
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-map"></i> خريطة المصنع</h5>
                    </div>
                    <div class="card-body">
                        <div id="map" style="height: 400px; width: 100%;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let map;
        let marker;
        const defaultLocation = { lat: 30.0444, lng: 31.2357 }; // Cairo

        function initMap() {
            const factoryLat = <?php echo $factory ? floatval($factory['latitude']) : 'null'; ?>;
            const factoryLng = <?php echo $factory ? floatval($factory['longitude']) : 'null'; ?>;
            const initialLocation = factoryLat && factoryLng 
                ? { lat: factoryLat, lng: factoryLng }
                : defaultLocation;

            map = new google.maps.Map(document.getElementById('map'), {
                zoom: 15,
                center: initialLocation
            });

            if (factoryLat && factoryLng) {
                marker = new google.maps.Marker({
                    position: initialLocation,
                    map: map,
                    draggable: true,
                    title: 'موقع المصنع'
                });

                marker.addListener('dragend', function() {
                    const position = marker.getPosition();
                    document.getElementById('latitude').value = position.lat();
                    document.getElementById('longitude').value = position.lng();
                    
                    // Reverse geocode to get address
                    const geocoder = new google.maps.Geocoder();
                    geocoder.geocode({ location: position }, (results, status) => {
                        if (status === 'OK' && results[0]) {
                            document.getElementById('addressInput').value = results[0].formatted_address;
                        }
                    });
                });
            }

            // Google Maps Autocomplete
            const autocomplete = new google.maps.places.Autocomplete(
                document.getElementById('addressInput'),
                { componentRestrictions: { country: 'eg' }, language: 'ar' }
            );

            autocomplete.addListener('place_changed', function() {
                const place = autocomplete.getPlace();
                if (place.geometry) {
                    const location = place.geometry.location;
                    document.getElementById('latitude').value = location.lat();
                    document.getElementById('longitude').value = location.lng();

                    if (marker) {
                        marker.setPosition(location);
                    } else {
                        marker = new google.maps.Marker({
                            position: location,
                            map: map,
                            draggable: true,
                            title: 'موقع المصنع'
                        });

                        marker.addListener('dragend', function() {
                            const position = marker.getPosition();
                            document.getElementById('latitude').value = position.lat();
                            document.getElementById('longitude').value = position.lng();
                        });
                    }

                    map.setCenter(location);
                    map.setZoom(15);
                }
            });

            // Click on map to set location
            map.addListener('click', function(event) {
                const location = event.latLng;
                document.getElementById('latitude').value = location.lat();
                document.getElementById('longitude').value = location.lng();

                if (marker) {
                    marker.setPosition(location);
                } else {
                    marker = new google.maps.Marker({
                        position: location,
                        map: map,
                        draggable: true,
                        title: 'موقع المصنع'
                    });
                }

                // Reverse geocode
                const geocoder = new google.maps.Geocoder();
                geocoder.geocode({ location: location }, (results, status) => {
                    if (status === 'OK' && results[0]) {
                        document.getElementById('addressInput').value = results[0].formatted_address;
                    }
                });
            });
        }

        google.maps.event.addDomListener(window, 'load', initMap);
    </script>
<?php require_once 'footer.php'; ?>
