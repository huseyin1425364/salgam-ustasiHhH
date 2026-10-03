<?php
// backend/api/user_profile.php
ob_start();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(0);
ini_set('display_errors', '0');

require_once '../config/Database.php';

$userId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Geçersiz kullanıcı ID."]);
    exit();
}

try {
    $db = (new Database())->getConnection();

    // 1. users tablosundaki mevcut kolonları tespit et
    $userCols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);

    $selectFields = ["id", "full_name", "email", "phone", "role"];
    $optionalUserFields = [
        "city_region", "bio", "avatar_url", 
        "is_email_verified", "is_phone_verified", "is_banned", "status",
        "show_email", "show_phone", "created_at"
    ];

    foreach ($optionalUserFields as $field) {
        if (in_array($field, $userCols)) {
            $selectFields[] = $field;
        }
    }

    $uStmt = $db->prepare("SELECT " . implode(", ", $selectFields) . " FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([$userId]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception("Kullanıcı profili bulunamadı.");
    }

    // Ban / Askı Kontrolü
    if (!empty($user['is_banned']) || (isset($user['status']) && in_array($user['status'], ['banned', 'suspended']))) {
        throw new Exception("Bu kullanıcının hesabı askıya alınmıştır.");
    }

    // Gizlilik Maskeleme
    if (isset($user['show_email']) && empty($user['show_email'])) {
        $user['email'] = null;
    }
    if (isset($user['show_phone']) && empty($user['show_phone'])) {
        $user['phone'] = null;
    }

    // Üyelik süresi biçimlendirme
    $createdAt = $user['created_at'] ?? 'now';
    $memberSince = date('F Y', strtotime($createdAt));
    $months = [
        'January' => 'Ocak', 'February' => 'Şubat', 'March' => 'Mart',
        'April' => 'Nisan', 'May' => 'Mayıs', 'June' => 'Haziran',
        'July' => 'Temmuz', 'August' => 'Ağustos', 'September' => 'Eylül',
        'October' => 'Ekim', 'November' => 'Kasım', 'December' => 'Aralık'
    ];
    foreach ($months as $en => $tr) {
        $memberSince = str_replace($en, $tr, $memberSince);
    }
    $user['member_since_formatted'] = $memberSince;

    // Varsayılan avatar koruması
    if (empty($user['avatar_url'])) {
        $user['avatar_url'] = 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=300&q=80';
    }

    // 2. properties tablosundaki durum ve tarih kolonlarını tespit et
    $propCols = $db->query("SHOW COLUMNS FROM properties")->fetchAll(PDO::FETCH_COLUMN);

    $hasStatus = in_array('status', $propCols);
    $hasIsActive = in_array('is_active', $propCols);

    $statusSelect = "'active' AS status";
    if ($hasStatus && $hasIsActive) {
        $statusSelect = "COALESCE(p.status, IF(p.is_active = 1, 'active', 'pending')) AS status";
    } elseif ($hasStatus) {
        $statusSelect = "p.status AS status";
    } elseif ($hasIsActive) {
        $statusSelect = "IF(p.is_active = 1, 'active', 'pending') AS status";
    }

    $createdAtSelect = in_array('created_at', $propCols) 
        ? "DATE_FORMAT(p.created_at, '%d.%m.%Y %H:%i') as created_date" 
        : "'Bugün' as created_date";

    // KRİTİK: p.cover_image ASLA ÇAĞRILMAZ, doğrudan property_images'tan çekilir
    $pStmt = $db->prepare("
        SELECT 
            p.id, p.title, p.price, p.bedrooms, p.area_sqm,
            {$createdAtSelect},
            {$statusSelect},
            COALESCE(d.name, 'Gazimağusa') as district_name,
            COALESCE(
                (SELECT pi.image_url FROM property_images pi WHERE pi.property_id = p.id ORDER BY pi.is_cover DESC, pi.sort_order ASC LIMIT 1),
                'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?auto=format&fit=crop&w=800&q=80'
            ) AS cover_image
        FROM properties p
        LEFT JOIN districts d ON d.id = p.district_id
        WHERE p.user_id = ?
        ORDER BY p.id DESC
    ");
    $pStmt->execute([$userId]);
    $userListings = $pStmt->fetchAll(PDO::FETCH_ASSOC);

    ob_clean();
    echo json_encode([
        "status"         => "success",
        "user"           => $user,
        "listings"       => $userListings,
        "total_listings" => count($userListings)
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}