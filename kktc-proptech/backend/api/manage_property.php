<?php
// backend/api/manage_property.php
ob_start();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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

    // 1. İLAN DETAYINI DÜZENLEME MODALI İÇİN GETİR (GET)
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $propId = (int)($_GET['id'] ?? 0);
        $userId = (int)($_GET['user_id'] ?? 0);

        if ($propId <= 0 || $userId <= 0) {
            throw new Exception("Geçersiz ilan veya kullanıcı parametresi.");
        }

        $propCols = $db->query("SHOW COLUMNS FROM properties")->fetchAll(PDO::FETCH_COLUMN);
        $dateSelect = in_array('created_at', $propCols)
            ? "DATE_FORMAT(p.created_at, '%d.%m.%Y %H:%i') as created_at_formatted"
            : "'Bugün' as created_at_formatted";

        $statusSelect = in_array('status', $propCols)
            ? "p.status"
            : "IF(p.is_active = 1, 'active', 'pending') as status";

        $sql = "
            SELECT 
                p.id, p.user_id, p.title, p.price,
                COALESCE(p.deposit_amount, 0) as deposit_amount,
                COALESCE(p.monthly_dues, 0) as monthly_dues,
                COALESCE(p.bedrooms, 1) as bedrooms,
                COALESCE(p.area_sqm, 60) as area_sqm,
                COALESCE(p.description, '') as description,
                {$dateSelect},
                {$statusSelect}
            FROM properties p
            WHERE p.id = ? AND p.user_id = ?
            LIMIT 1
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$propId, $userId]);
        $prop = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prop) {
            throw new Exception("İlan bulunamadı veya bu ilanı düzenleme yetkiniz yok.");
        }

        ob_clean();
        echo json_encode(["status" => "success", "property" => $prop], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // 2. İLAN GÜNCELLEME VEYA DURUM DEĞİŞTİRME (POST)
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception("Geçersiz veri gövdesi gönderildi.");
    }

    $action = $input['action'] ?? '';
    $userId = (int)($input['user_id'] ?? 0);
    $propId = (int)($input['property_id'] ?? 0);

    if ($userId <= 0 || $propId <= 0) {
        throw new Exception("Geçersiz kullanıcı oturumu veya ilan ID'si.");
    }

    $chk = $db->prepare("SELECT id, user_id, status FROM properties WHERE id = ? LIMIT 1");
    $chk->execute([$propId]);
    $existing = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$existing || (int)$existing['user_id'] !== $userId) {
        throw new Exception("Bu ilan üzerinde düzenleme yapma yetkiniz yok.");
    }

    $propCols = $db->query("SHOW COLUMNS FROM properties")->fetchAll(PDO::FETCH_COLUMN);

    // A) KİRALANDI / YAYINA AL DONDURMASI
    if ($action === 'toggle_rental_status') {
        $targetStatus = trim($input['status'] ?? '');

        if ($targetStatus === 'active' && !in_array($existing['status'], ['active', 'rented'])) {
            throw new Exception("Henüz onaylanmamış bir ilan kullanıcı tarafından doğrudan yayına alınamaz.");
        }

        $updates = [];
        $params = [];

        if (in_array('status', $propCols)) {
            $updates[] = "status = ?";
            $params[] = $targetStatus;
        }
        if (in_array('is_active', $propCols)) {
            $updates[] = "is_active = ?";
            $params[] = ($targetStatus === 'active') ? 1 : 0;
        }

        if (!empty($updates)) {
            $params[] = $propId;
            $db->prepare("UPDATE properties SET " . implode(", ", $updates) . " WHERE id = ?")->execute($params);
        }

        $msg = ($targetStatus === 'rented') 
            ? "İlan 'Kiralandı' olarak işaretlendi ve haritadan gizlendi." 
            : "İlanınız tekrar aktif olarak yayına alındı.";

        ob_clean();
        echo json_encode(["status" => "success", "message" => $msg, "new_status" => $targetStatus], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // B) İLAN DETAYLARINI GÜNCELLE
    if ($action === 'update_property_details') {
        $title         = trim($input['title'] ?? '');
        $price         = (float)($input['price'] ?? 0);
        $depositAmount = (float)($input['deposit_amount'] ?? 0);
        $monthlyDues   = (float)($input['monthly_dues'] ?? 0);
        $bedrooms      = (int)($input['bedrooms'] ?? 2);
        $areaSqm       = (int)($input['area_sqm'] ?? 65);
        $description   = trim($input['description'] ?? '');

        if (empty($title) || $price <= 0) {
            throw new Exception("Lütfen geçerli bir ilan başlığı ve fiyat girin.");
        }

        $setParts = ["title = :title", "price = :price", "bedrooms = :bedrooms", "area_sqm = :area", "description = :desc"];
        $updateParams = [
            ':title'    => $title,
            ':price'    => $price,
            ':bedrooms' => $bedrooms,
            ':area'     => $areaSqm,
            ':desc'     => $description,
            ':id'       => $propId,
            ':uid'      => $userId
        ];

        if (in_array('deposit_amount', $propCols)) {
            $setParts[] = "deposit_amount = :deposit";
            $updateParams[':deposit'] = $depositAmount;
        }
        if (in_array('monthly_dues', $propCols)) {
            $setParts[] = "monthly_dues = :dues";
            $updateParams[':dues'] = $monthlyDues;
        }

        $sql = "UPDATE properties SET " . implode(", ", $setParts) . " WHERE id = :id AND user_id = :uid";
        $stmt = $db->prepare($sql);
        $stmt->execute($updateParams);

        ob_clean();
        echo json_encode(["status" => "success", "message" => "İlan detayları başarıyla kaydedildi."], JSON_UNESCAPED_UNICODE);
        exit();
    }

    throw new Exception("Tanımsız aksiyon: " . htmlspecialchars($action));

} catch (Throwable $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}