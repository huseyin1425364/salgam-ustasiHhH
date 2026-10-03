<?php
// backend/api/create_property.php
ob_start();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(0);
ini_set('display_errors', '0');

require_once '../config/Database.php';

try {
    $db = (new Database())->getConnection();

    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    if ($userId <= 0) {
        throw new Exception("İlan eklemek için geçerli bir kullanıcı oturumu gereklidir.");
    }

    // Kullanıcının rolünü kontrol et
    $uStmt = $db->prepare("SELECT role, email FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([$userId]);
    $userRow = $uStmt->fetch(PDO::FETCH_ASSOC);

    if (!$userRow) {
        throw new Exception("Kullanıcı oturumu doğrulanamadı.");
    }

    // Admin veya Süper Admin eklerse doğrudan 'active', normal kullanıcı eklerse 'pending' (ONAY BEKLİYOR)
    $initialStatus = in_array($userRow['role'], ['admin', 'superadmin']) ? 'active' : 'pending';

    $title         = trim($_POST['title'] ?? '');
    $price         = (float)($_POST['price'] ?? 0);
    $depositAmount = (float)($_POST['deposit_amount'] ?? ($price * 2));
    $monthlyDues   = (float)($_POST['monthly_dues'] ?? 0);
    $paymentPeriod = trim($_POST['payment_period'] ?? 'monthly');
    $districtId    = (int)($_POST['district_id'] ?? 1);
    $bedrooms      = (int)($_POST['bedrooms'] ?? 2);
    $areaSqm       = (int)($_POST['area_sqm'] ?? 65);
    $floorNumber   = (int)($_POST['floor_number'] ?? 1);
    $isFurnished   = (int)($_POST['is_furnished'] ?? 1);
    $hasInverter   = (int)($_POST['has_inverter_ac'] ?? 1);
    $hasGenerator  = (int)($_POST['has_generator'] ?? 0);
    $hasWaterTank  = (int)($_POST['has_water_tank'] ?? 1);
    $description   = trim($_POST['description'] ?? '');
    $lat           = (float)($_POST['lat'] ?? 35.1424);
    $lng           = (float)($_POST['lng'] ?? 33.9117);

    if (empty($title) || $price <= 0) {
        throw new Exception("Lütfen geçerli bir ilan başlığı ve kira bedeli girin.");
    }

    // 1. Kapak Görselini Yükle (Varsayılan veya Yüklenen Dosya)
    $coverUrl = 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?auto=format&fit=crop&w=800&q=80';
    if (!empty($_FILES['cover_image']['name']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $uploadDir = __DIR__ . '/../../public/uploads/properties/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
            $filename = 'prop_cover_' . time() . '_' . rand(100, 999) . '.' . $ext;
            if (@move_uploaded_file($_FILES['cover_image']['tmp_name'], $uploadDir . $filename)) {
                $coverUrl = 'uploads/properties/' . $filename;
            }
        }
    }

    // 2. properties tablosundaki mevcut kolonları tespit et (HATA ÖNLEYİCİ DİNAMİK YAPILANDIRMA)
    $propCols = $db->query("SHOW COLUMNS FROM properties")->fetchAll(PDO::FETCH_COLUMN);

    $fields = ['user_id', 'district_id', 'title', 'price', 'bedrooms', 'area_sqm', 'description', 'location'];
    $placeholders = [':uid', ':did', ':title', ':price', ':bedrooms', ':area', ':desc', 'ST_GeomFromText(:wkt)'];
    $params = [
        ':uid'      => $userId,
        ':did'      => $districtId,
        ':title'    => $title,
        ':price'    => $price,
        ':bedrooms' => $bedrooms,
        ':area'     => $areaSqm,
        ':desc'     => $description,
        ':wkt'      => "POINT({$lat} {$lng})"
    ];

    // Opsiyonel kolonlar varsa sorguya ekle (Yoksa sorguyu patlatmaz)
    if (in_array('deposit_amount', $propCols)) {
        $fields[] = 'deposit_amount';
        $placeholders[] = ':deposit';
        $params[':deposit'] = $depositAmount;
    }
    if (in_array('monthly_dues', $propCols)) {
        $fields[] = 'monthly_dues';
        $placeholders[] = ':dues';
        $params[':dues'] = $monthlyDues;
    }
    if (in_array('payment_period', $propCols)) {
        $fields[] = 'payment_period';
        $placeholders[] = ':pperiod';
        $params[':pperiod'] = $paymentPeriod;
    }
    if (in_array('floor_number', $propCols)) {
        $fields[] = 'floor_number';
        $placeholders[] = ':floor';
        $params[':floor'] = $floorNumber;
    }
    if (in_array('is_furnished', $propCols)) {
        $fields[] = 'is_furnished';
        $placeholders[] = ':furn';
        $params[':furn'] = $isFurnished;
    }
    if (in_array('has_inverter_ac', $propCols)) {
        $fields[] = 'has_inverter_ac';
        $placeholders[] = ':inverter';
        $params[':inverter'] = $hasInverter;
    }
    if (in_array('has_generator', $propCols)) {
        $fields[] = 'has_generator';
        $placeholders[] = ':gen';
        $params[':gen'] = $hasGenerator;
    }
    if (in_array('has_water_tank', $propCols)) {
        $fields[] = 'has_water_tank';
        $placeholders[] = ':tank';
        $params[':tank'] = $hasWaterTank;
    }
    if (in_array('status', $propCols)) {
        $fields[] = 'status';
        $placeholders[] = ':status';
        $params[':status'] = $initialStatus;
    }
    if (in_array('is_active', $propCols)) {
        $fields[] = 'is_active';
        $placeholders[] = ':is_active';
        $params[':is_active'] = ($initialStatus === 'active') ? 1 : 0;
    }
    if (in_array('cover_image', $propCols)) {
        $fields[] = 'cover_image';
        $placeholders[] = ':cover';
        $params[':cover'] = $coverUrl;
    }

    $sql = "INSERT INTO properties (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $propertyId = (int)$db->lastInsertId();

    // 3. Kapak Görselini ve Galeri Görsellerini property_images Tablosuna Kaydet
    $hasImageTable = $db->query("SHOW TABLES LIKE 'property_images'")->fetch();
    if ($hasImageTable) {
        $imgCols = $db->query("SHOW COLUMNS FROM property_images")->fetchAll(PDO::FETCH_COLUMN);
        $hasIsCover = in_array('is_cover', $imgCols);

        // A) Kapak resmini property_images tablosuna ekle
        if ($hasIsCover) {
            $cStmt = $db->prepare("INSERT INTO property_images (property_id, image_url, sort_order, is_cover) VALUES (?, ?, 1, 1)");
            $cStmt->execute([$propertyId, $coverUrl]);
        } else {
            $cStmt = $db->prepare("INSERT INTO property_images (property_id, image_url, sort_order) VALUES (?, ?, 1)");
            $cStmt->execute([$propertyId, $coverUrl]);
        }

        // B) Ekstra Galeri Resimleri (Varsa)
        if (!empty($_FILES['gallery_images']['name'][0])) {
            $count = count($_FILES['gallery_images']['name']);
            $uploadDir = __DIR__ . '/../../public/uploads/properties/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);

            $gSql = $hasIsCover 
                ? "INSERT INTO property_images (property_id, image_url, sort_order, is_cover) VALUES (?, ?, ?, 0)"
                : "INSERT INTO property_images (property_id, image_url, sort_order) VALUES (?, ?, ?)";
            $gStmt = $db->prepare($gSql);

            for ($i = 0; $i < $count; $i++) {
                if ($_FILES['gallery_images']['error'][$i] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['gallery_images']['name'][$i], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $gFile = 'prop_gal_' . $propertyId . '_' . time() . '_' . $i . '.' . $ext;
                        if (@move_uploaded_file($_FILES['gallery_images']['tmp_name'][$i], $uploadDir . $gFile)) {
                            $gStmt->execute([$propertyId, 'uploads/properties/' . $gFile, $i + 2]);
                        }
                    }
                }
            }
        }
    }

    // 4. Denetim Günlüğü (Audit Log)
    try {
        if (file_exists('../services/AuditService.php')) {
            require_once '../services/AuditService.php';
            if (class_exists('AuditService')) {
                AuditService::log($db, $userId, $userRow['email'], $userRow['role'], 'PROPERTY_CREATED', 'properties', $propertyId, null, [
                    'status' => $initialStatus,
                    'title'  => $title,
                    'price'  => $price
                ]);
            }
        }
    } catch (Throwable $eLog) {}

    $msg = ($initialStatus === 'active')
        ? "İlanınız yönetici yetkisiyle anında yayına alındı."
        : "İlanınız başarıyla oluşturuldu! Yönetici onayından geçtikten sonra haritada yayına girecektir.";

    ob_clean();
    echo json_encode([
        "status"      => "success",
        "message"     => $msg,
        "property_id" => $propertyId,
        "prop_status" => $initialStatus
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}