<?php
/**
 * One-off: DELETE all customers (daily_orders CASCADE) + insert random test customers.
 * Run from project root: php scripts/reset-and-seed-customers.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

$db = getDB();
$db->exec('DELETE FROM customers');

$factory = $db->query('SELECT latitude, longitude FROM factory LIMIT 1')->fetch();
$baseLat = $factory ? (float) $factory['latitude'] : 30.0444;
$baseLng = $factory ? (float) $factory['longitude'] : 31.2357;

$towns = ['مدينة نصر', 'المعادي', 'شبرا', 'حلوان', 'الزمالك', 'مصر الجديدة', 'العباسية', 'طره', 'المنيل', 'السيدة زينب'];
$govs = ['القاهرة', 'القاهرة', 'القاهرة', 'القاهرة', 'الجيزة', 'القاهرة', 'القاهرة', 'الجيزة', 'القاهرة', 'القاهرة'];

$stmt = $db->prepare(
    'INSERT INTO customers (customer_number, name, phone, address, town, governorate, latitude, longitude, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$n = 40;
for ($i = 1; $i <= $n; $i++) {
    $cn = (string) $i;
    $name = 'عميل تجريبي ' . $i;
    $phone = '01' . random_int(100000000, 999999999);
    $town = $towns[array_rand($towns)];
    $gov = $govs[array_rand($govs)];
    $address = 'شارع ' . random_int(1, 200) . '، ' . $town . '، محافظة ' . $gov;
    $lat = $baseLat + (random_int(-130, 130) / 1000);
    $lng = $baseLng + (random_int(-130, 130) / 1000);
    $stmt->execute([$cn, $name, $phone, $address, $town, $gov, $lat, $lng, '']);
}

echo "OK: removed all customers (daily_orders cleared via CASCADE), inserted {$n} random test customers near factory.\n";
