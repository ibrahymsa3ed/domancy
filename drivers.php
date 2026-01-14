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
            $car_number = $_POST['car_number'] ?? '';
            $capacity = $_POST['capacity'] ?? 10;
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($name) {
                try {
                    $stmt = getDB()->prepare("INSERT INTO drivers (name, phone, car_number, capacity, is_active) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $phone, $car_number, $capacity, $is_active]);
                    $message = "تم إضافة السائق بنجاح";
                    $messageType = "success";
                } catch (PDOException $e) {
                    $message = "خطأ في إضافة السائق: " . $e->getMessage();
                    $messageType = "danger";
                }
            } else {
                $message = "يرجى إدخال اسم السائق";
                $messageType = "warning";
            }
        } elseif ($_POST['action'] === 'delete') {
            $id = $_POST['id'] ?? 0;
            if ($id) {
                try {
                    $stmt = getDB()->prepare("DELETE FROM drivers WHERE id = ?");
                    $stmt->execute([$id]);
                    $message = "تم حذف السائق بنجاح";
                    $messageType = "success";
                } catch (PDOException $e) {
                    $message = "خطأ في حذف السائق: " . $e->getMessage();
                    $messageType = "danger";
                }
            }
        } elseif ($_POST['action'] === 'toggle_active') {
            $id = $_POST['id'] ?? 0;
            if ($id) {
                try {
                    $stmt = getDB()->prepare("UPDATE drivers SET is_active = NOT is_active WHERE id = ?");
                    $stmt->execute([$id]);
                    $message = "تم تحديث حالة السائق";
                    $messageType = "success";
                } catch (PDOException $e) {
                    $message = "خطأ في تحديث حالة السائق";
                    $messageType = "danger";
                }
            }
        }
    }
}

// Get all drivers
$drivers = getDB()->query("SELECT * FROM drivers ORDER BY name")->fetchAll();

// Set variables for header
$pageTitle = APP_NAME . ' - السائقين';
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
                        <h5 class="mb-0"><i class="bi bi-person-plus"></i> إضافة سائق جديد</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="add">
                            <div class="mb-3">
                                <label class="form-label">اسم السائق *</label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">الهاتف</label>
                                <input type="text" class="form-control" name="phone">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">رقم السيارة</label>
                                <input type="text" class="form-control" name="car_number">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">القدرة (عدد الطلبات في اليوم)</label>
                                <input type="number" class="form-control" name="capacity" value="10" min="1">
                            </div>
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" name="is_active" id="is_active" checked>
                                <label class="form-check-label" for="is_active">
                                    نشط
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-check-circle"></i> إضافة سائق
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-list-ul"></i> قائمة السائقين (<?php echo count($drivers); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>الاسم</th>
                                        <th>الهاتف</th>
                                        <th>رقم السيارة</th>
                                        <th>القدرة</th>
                                        <th>الحالة</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($drivers)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">لا يوجد سائقين</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($drivers as $driver): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($driver['name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($driver['phone'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($driver['car_number'] ?? '-'); ?></td>
                                                <td><?php echo $driver['capacity']; ?> طلبات في اليوم</td>
                                                <td>
                                                    <?php if ($driver['is_active']): ?>
                                                        <span class="badge bg-success">نشط</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">غير نشط</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="toggle_active">
                                                        <input type="hidden" name="id" value="<?php echo $driver['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-<?php echo $driver['is_active'] ? 'warning' : 'success'; ?>">
                                                            <i class="bi bi-<?php echo $driver['is_active'] ? 'pause' : 'play'; ?>"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('هل أنت متأكد من حذف هذا السائق؟');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo $driver['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger">
                                                            <i class="bi bi-trash"></i>
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

<?php require_once 'footer.php'; ?>
