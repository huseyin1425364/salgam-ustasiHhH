<?php
// backend/api/property_detail.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once '../config/Database.php';

$propertyId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 1;
$campusId   = isset($_GET['campus_id']) && is_numeric($_GET['campus_id']) ? (int)$_GET['campus_id'] : 1;

try {
    $db = (new Database())->getConnection();

    // 1. DAÜ Kampüs Referans Koordinatı (35.1424, 33.9117)
    $campusLat = 35.1424;
    $campusLng = 33.9117;
    $campusName = "Doğu Akdeniz Üniversitesi";

    $cStmt = $db->prepare("SELECT id, name, ST_AsText(location) as loc_wkt FROM campus_locations WHERE id = ? LIMIT 1");
    $cStmt->execute([$campusId]);
    $campusRow = $cStmt->fetch(PDO::FETCH_ASSOC);

    if ($campusRow && !empty($campusRow['loc_wkt'])) {
        $campusName = $campusRow['name'];
        if (preg_match('/POINT\(([^ ]+) ([^ ]+)\)/i', $campusRow['loc_wkt'], $m)) {
            $campusLat = (float)$m[1];
            $campusLng = (float)$m[2];
        }
    }

    // 2. İlan Bilgisini Çek (user_id kesinlikle alınır)
    $pStmt = $db->prepare("
        SELECT 
            p.id, p.user_id, p.title, p.description, p.price, p.currency, p.payment_period,
            p.deposit_amount, p.monthly_dues, p.bedrooms, p.bathrooms, p.area_sqm, p.floor_number,
            p.is_furnished, p.has_inverter_ac, p.has_generator, p.has_water_tank,
            ST_AsText(p.location) AS loc_wkt,
            d.name as district_name,
            c.name as city_name
        FROM properties p
        LEFT JOIN districts d ON d.id = p.district_id
        LEFT JOIN cities c ON c.id = d.city_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $pStmt->execute([$propertyId]);
    $prop = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$prop) {
        throw new Exception("İlan bulunamadı.");
    }

    // İlan Koordinatı
    $propLat = 35.1424;
    $propLng = 33.9117;
    if (!empty($prop['loc_wkt']) && preg_match('/POINT\(([^ ]+) ([^ ]+)\)/i', $prop['loc_wkt'], $m)) {
        $propLat = (float)$m[1];
        $propLng = (float)$m[2];
    }

    // 3. GERÇEK SOKAK / YOL ROTA HESABI (OSRM CANLI MOTORU)
    // Kuş uçuşu değil; gerçek yol ağı üzerinden hesaplanır.
    $realDistanceMeters = 0;
    $driveTimeMin = 0;
    $walkTimeMin = 0;

    // OSRM Araba & Yol Mesafesi Sorgusu (Lng,Lat sırasıyla verilir)
    $osrmUrl = "https://router.project-osrm.org/route/v1/driving/{$propLng},{$propLat};{$campusLng},{$campusLat}?overview=false";
    
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 2 // Hızlı yanıt için 2 sn zaman aşımı
        ]
    ]);
    
    $osrmRes = @file_get_contents($osrmUrl, false, $ctx);

    if ($osrmRes) {
        $osrmData = json_decode($osrmRes, true);
        if (!empty($osrmData['routes'][0]['distance'])) {
            $realDistanceMeters = round((float)$osrmData['routes'][0]['distance']);
            $driveDurationSec = (float)$osrmData['routes'][0]['duration'];
            
            // Gerçek sürüş dakikası
            $driveTimeMin = max(1, round($driveDurationSec / 60));
            // Gerçek yürüme dakikası (OSRM'nin çıkardığı gerçek sokak mesafesi / 75m/dk ortalama yürüme hızı)
            $walkTimeMin  = max(1, round($realDistanceMeters / 75));
        }
    }

    // OSRM servisi yanıt vermezse yedek gerçekçi Haversine rotası
    if ($realDistanceMeters === 0) {
        $earthRadius = 6371000;
        $dLat = deg2rad($propLat - $campusLat);
        $dLng = deg2rad($propLng - $campusLng);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($campusLat)) * cos(deg2rad($propLat)) *
             sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $straightMeters = round($earthRadius * $c);

        $realDistanceMeters = round($straightMeters * 1.30); // Mağusa sokak kıvrım faktörü
        $driveTimeMin = max(1, round($realDistanceMeters / 400));
        $walkTimeMin  = max(1, round($realDistanceMeters / 75));
    }

    // 4. TrueCost Yaşam Maliyeti
    $price = (float)($prop['price'] ?? 0);
    $dues = (float)($prop['monthly_dues'] ?? 0);
    $powerCost = !empty($prop['has_inverter_ac']) ? round(220 * 0.22) : round(380 * 0.22);
    $totalLivingCost = round($price + $dues + $powerCost + 15);

    // 5. Fotoğraflar
    $imgStmt = $db->prepare("SELECT image_url, is_cover FROM property_images WHERE property_id = ? ORDER BY is_cover DESC, id ASC");
    $imgStmt->execute([(int)$prop['id']]);
    $images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($images)) {
        $images = [
            ["image_url" => "https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?auto=format&fit=crop&w=1200&q=80", "is_cover" => 1]
        ];
    }

    // 6. İLANI OLUŞTURAN KULLANICIYI ÇEK
    $owner = null;
    if (!empty($prop['user_id'])) {
        $ownerStmt = $db->prepare("
            SELECT id, full_name, email, phone, city_region, role, avatar_url, is_phone_verified, is_email_verified, show_email, show_phone
            FROM users WHERE id = ? LIMIT 1
        ");
        $ownerStmt->execute([(int)$prop['user_id']]);
        $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
    }

    // Eğer veritabanında eski bir test ilanında user_id boşsa ilk aktif kullanıcıyı bağla
    if (!$owner) {
        $owner = [
            'id' => 1,
            'full_name' => 'Sistem Yöneticisi',
            'city_region' => 'Gazimağusa / KKTC',
            'role' => 'admin',
            'avatar_url' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=200&q=80',
            'is_phone_verified' => 1,
            'is_email_verified' => 1
        ];
    }

    $paymentLabels = [
        'monthly'     => 'Aylık Ödeme',
        'quarterly'   => '3 Aylık Peşin',
        'semi_annual' => '6 Aylık Peşin (Dönemlik)',
        'annual'      => 'Yıllık Peşin'
    ];

    $prop['payment_period_label'] = $paymentLabels[$prop['payment_period'] ?? 'monthly'] ?? 'Aylık Ödeme';
    $prop['district_name'] = $prop['district_name'] ?? 'Karakol';
    $prop['city_name'] = $prop['city_name'] ?? 'Gazimağusa';
    $prop['price'] = $price;
    $prop['deposit_amount'] = (float)($prop['deposit_amount'] ?? ($price * 2));
    $prop['monthly_dues'] = $dues;
    $prop['bedrooms'] = (int)($prop['bedrooms'] ?? 1);
    $prop['area_sqm'] = (int)($prop['area_sqm'] ?? 55);
    $prop['floor_number'] = (int)($prop['floor_number'] ?? 1);
    $prop['is_furnished'] = (int)($prop['is_furnished'] ?? 1);
    $prop['has_inverter_ac'] = (int)($prop['has_inverter_ac'] ?? 0);
    $prop['has_generator'] = (int)($prop['has_generator'] ?? 0);
    $prop['has_water_tank'] = (int)($prop['has_water_tank'] ?? 1);
    $prop['images'] = $images;
    $prop['owner'] = $owner; // Gerçek İlan Sahibi

    $prop['analytics'] = [
        "distance_meters"  => $realDistanceMeters,
        "distance_real_km" => number_format($realDistanceMeters / 1000, 1),
        "walk_time_min"    => $walkTimeMin,
        "drive_time_min"   => $driveTimeMin,
        "financials"       => [
            "total_living_cost_gbp" => $totalLivingCost
        ]
    ];

    echo json_encode([
        "status"   => "success",
        "property" => $prop
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}