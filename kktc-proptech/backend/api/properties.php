<?php
// backend/api/properties.php
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

try {
    $db = (new Database())->getConnection();

    // 1. KAMPÜS REFERANS NOKTALARINI AL
    $campusPoints = [];
    $tableCheck = $db->query("SHOW TABLES LIKE 'campus_gates'")->fetch();
    if ($tableCheck) {
        $campusPoints = $db->query("SELECT id, name, ST_X(location) as lat, ST_Y(location) as lng FROM campus_gates ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    $activeCampus = [
        "id"   => 1,
        "name" => "DAÜ Kampüs Merkezi",
        "lat"  => 35.1424,
        "lng"  => 33.9117
    ];

    $campusId = isset($_GET['campus_id']) ? (int)$_GET['campus_id'] : 1;
    foreach ($campusPoints as $cp) {
        if ((int)$cp['id'] === $campusId) {
            $activeCampus = [
                "id"   => (int)$cp['id'],
                "name" => $cp['name'],
                "lat"  => (float)$cp['lat'],
                "lng"  => (float)$cp['lng']
            ];
            break;
        }
    }

    $refLat = $activeCampus['lat'];
    $refLng = $activeCampus['lng'];

    // 2. FİLTRELER
    $maxPrice = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? (float)$_GET['max_price'] : null;
    $bedrooms = isset($_GET['bedrooms']) && is_numeric($_GET['bedrooms']) ? (int)$_GET['bedrooms'] : null;
    $maxWalk  = isset($_GET['max_walk']) && is_numeric($_GET['max_walk']) ? (int)$_GET['max_walk'] : null;

    // 3. YALNIZCA ONAYLI / AKTİF İLANLAR
    $colCheck = $db->query("SHOW COLUMNS FROM properties LIKE 'status'")->fetch();
    $statusCondition = $colCheck 
        ? "p.status = 'active'" 
        : "p.is_active = 1";

    $whereConditions = [$statusCondition];
    $params = [
        ':refLat'  => $refLat,
        ':refLng'  => $refLng,
        ':refLat2' => $refLat
    ];

    if ($maxPrice !== null && $maxPrice > 0) {
        $whereConditions[] = "p.price <= :maxPrice";
        $params[':maxPrice'] = $maxPrice;
    }

    if ($bedrooms !== null && $bedrooms > 0) {
        $whereConditions[] = "p.bedrooms = :bedrooms";
        $params[':bedrooms'] = $bedrooms;
    }

    $whereSql = "WHERE " . implode(" AND ", $whereConditions);

    // properties tablosunda cover_image sütunu var mı kontrolü
    $hasCoverCol = $db->query("SHOW COLUMNS FROM properties LIKE 'cover_image'")->fetch();
    $coverSelect = $hasCoverCol 
        ? "COALESCE(p.cover_image, (SELECT pi.image_url FROM property_images pi WHERE pi.property_id = p.id ORDER BY pi.is_cover DESC, pi.sort_order ASC LIMIT 1), 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?auto=format&fit=crop&w=800&q=80') as cover_image"
        : "COALESCE((SELECT pi.image_url FROM property_images pi WHERE pi.property_id = p.id ORDER BY pi.is_cover DESC, pi.sort_order ASC LIMIT 1), 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?auto=format&fit=crop&w=800&q=80') as cover_image";

    // 4. HAVERSINE MESAFE VE İLANLARI ÇEKME
    $sql = "
        SELECT 
            p.id, p.title, p.price, 
            COALESCE(p.deposit_amount, p.price * 2) as deposit_amount,
            COALESCE(p.monthly_dues, 0) as monthly_dues,
            COALESCE(p.payment_period, 'monthly') as payment_period,
            p.bedrooms, 
            COALESCE(p.area_sqm, 65) as area_sqm,
            COALESCE(p.floor_number, 1) as floor_number,
            COALESCE(p.is_furnished, 1) as is_furnished,
            COALESCE(p.has_inverter_ac, 1) as has_inverter_ac,
            COALESCE(p.has_generator, 0) as has_generator,
            COALESCE(p.has_water_tank, 1) as has_water_tank,
            COALESCE(p.description, '') as description,
            {$coverSelect},
            ST_X(p.location) as lat,
            ST_Y(p.location) as lng,
            COALESCE(d.name, 'Karakol') as district_name,
            COALESCE(c.name, 'Gazimağusa') as city_name,
            (
                6371 * ACOS(
                    COS(RADIANS(:refLat)) * COS(RADIANS(ST_X(p.location))) *
                    COS(RADIANS(ST_Y(p.location)) - RADIANS(:refLng)) +
                    SIN(RADIANS(:refLat2)) * SIN(RADIANS(ST_X(p.location)))
                )
            ) AS distance_km
        FROM properties p
        LEFT JOIN districts d ON d.id = p.district_id
        LEFT JOIN cities c ON c.id = d.city_id
        {$whereSql}
        ORDER BY distance_km ASC
        LIMIT 100
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. ANALİTİK VERİLERİ OLUŞTUR
    $properties = [];

    foreach ($rows as $row) {
        $crowKm = round((float)$row['distance_km'], 2);
        $realRouteKm = round($crowKm * 1.28, 2);
        $walkMin = max(2, (int)round($realRouteKm * 12.5));
        $driveMin = max(1, (int)round(($realRouteKm * 2) + 1));

        if ($maxWalk !== null && $walkMin > $maxWalk) {
            continue;
        }

        $baseRent = (float)$row['price'];
        $dues = (float)$row['monthly_dues'];
        $kibtekEst = ($row['has_inverter_ac'] == 1) ? 45.0 : 70.0;
        $waterEst = 10.0;
        $totalLivingCost = round($baseRent + $dues + $kibtekEst + $waterEst, 0);

        $properties[] = [
            "id"             => (int)$row['id'],
            "title"          => $row['title'],
            "price"          => (float)$row['price'],
            "deposit_amount" => (float)$row['deposit_amount'],
            "monthly_dues"   => (float)$row['monthly_dues'],
            "payment_period" => $row['payment_period'],
            "bedrooms"       => (int)$row['bedrooms'],
            "area_sqm"       => (int)$row['area_sqm'],
            "floor_number"   => (int)$row['floor_number'],
            "is_furnished"   => (int)$row['is_furnished'],
            "has_inverter_ac"=> (int)$row['has_inverter_ac'],
            "has_generator"  => (int)$row['has_generator'],
            "has_water_tank" => (int)$row['has_water_tank'],
            "description"    => $row['description'],
            "cover_image"    => $row['cover_image'],
            "lat"            => (float)$row['lat'],
            "lng"            => (float)$row['lng'],
            "district_name"  => $row['district_name'],
            "city_name"      => $row['city_name'],
            "analytics"      => [
                "distance_km"      => $realRouteKm,
                "walk_time_min"    => $walkMin,
                "drive_time_min"   => $driveMin,
                "is_walkable"      => ($walkMin <= 15),
                "total_cost_gbp"   => $totalLivingCost,
                "electricity_est"  => $kibtekEst
            ]
        ];
    }

    echo json_encode([
        "status"           => "success",
        "active_reference" => $activeCampus,
        "campus_points"    => !empty($campusPoints) ? $campusPoints : [$activeCampus],
        "total"            => count($properties),
        "properties"       => $properties
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}