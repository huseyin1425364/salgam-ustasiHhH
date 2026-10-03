<?php
// backend/api/admin/manager.php
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

require_once '../../config/Database.php';

try {
    $db = (new Database())->getConnection();

    $authUserId = (int)($_GET['auth_user_id'] ?? 0);
    if ($authUserId <= 0) {
        $input = json_decode(file_get_contents('php://input'), true);
        $authUserId = (int)($input['auth_user_id'] ?? 0);
    }

    if ($authUserId <= 0) {
        throw new Exception("Yönetici oturumu doğrulanamadı.");
    }

    $uStmt = $db->prepare("SELECT id, full_name, email, role FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([$authUserId]);
    $actor = $uStmt->fetch(PDO::FETCH_ASSOC);

    if (!$actor || !in_array($actor['role'], ['admin', 'superadmin'])) {
        throw new Exception("Bu panele erişim yetkiniz bulunmamaktadır.");
    }

    $isSuperAdmin = ($actor['role'] === 'superadmin');

    // 1. DASHBOARD BİLGİLERİNİ VE BEKLEYEN İLAN BİLDİRİMİNİ GETİR (GET)
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $propCols = $db->query("SHOW COLUMNS FROM properties")->fetchAll(PDO::FETCH_COLUMN);

        $statusField = in_array('status', $propCols) 
            ? "p.status" 
            : "IF(p.is_active = 1, 'active', 'pending') AS status";

        $dateField = in_array('created_at', $propCols)
            ? "DATE_FORMAT(p.created_at, '%d.%m.%Y %H:%i') as created_at_formatted"
            : "'Yeni' as created_at_formatted";

        $props = $db->query("
            SELECT p.id, p.title, p.price, p.bedrooms, {$statusField}, {$dateField},
                   COALESCE(u.full_name, 'Bilinmiyor') AS owner_name,
                   COALESCE(u.phone, '-') AS owner_phone,
                   COALESCE(d.name, 'Gazimağusa') AS district_name
            FROM properties p
            LEFT JOIN users u ON u.id = p.user_id
            LEFT JOIN districts d ON d.id = p.district_id
            ORDER BY (CASE WHEN {$statusField} = 'pending' THEN 0 ELSE 1 END), p.id DESC
            LIMIT 150
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Bekleyen ilan sayısı (Yöneticiye kırmızı bildirim rozeti için)
        $pendingCount = 0;
        foreach ($props as $p) {
            if ($p['status'] === 'pending') {
                $pendingCount++;
            }
        }

        $users = $db->query("
            SELECT id, full_name, email, phone, role, created_at,
                   COALESCE(status, 'active') as status
            FROM users
            ORDER BY id DESC LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC);

        $hasLogTable = $db->query("SHOW TABLES LIKE 'audit_logs'")->fetch();
        $logs = [];
        if ($hasLogTable) {
            $cols = $db->query("SHOW COLUMNS FROM audit_logs")->fetchAll(PDO::FETCH_COLUMN);
            $logsQuery = in_array('user_id', $cols)
                ? "SELECT a.*, COALESCE(u.full_name, 'Sistem') AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 100"
                : "SELECT a.*, 'Sistem' AS user_name FROM audit_logs a ORDER BY a.id DESC LIMIT 100";
            $logs = $db->query($logsQuery)->fetchAll(PDO::FETCH_ASSOC);
        }

        ob_clean();
        echo json_encode([
            "status"        => "success",
            "is_superadmin" => $isSuperAdmin,
            "actor_name"    => $actor['full_name'],
            "pending_count" => $pendingCount,
            "properties"    => $props,
            "users"         => $users,
            "logs"          => $logs
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // 2. YÖNETİCİ ONAY / RED / SİL AKSİYONLARI (POST)
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    // A) İLANI ONAYLA VEYA REDDET
    if ($action === 'update_property_status') {
        $propId = (int)($input['property_id'] ?? 0);
        $newStatus = trim($input['status'] ?? 'active'); // 'active', 'rejected', 'archived'

        $propCols = $db->query("SHOW COLUMNS FROM properties")->fetchAll(PDO::FETCH_COLUMN);
        $updates = [];
        $params = [];

        if (in_array('status', $propCols)) {
            $updates[] = "status = ?";
            $params[] = $newStatus;
        }
        if (in_array('is_active', $propCols)) {
            $updates[] = "is_active = ?";
            $params[] = ($newStatus === 'active') ? 1 : 0;
        }

        if (!empty($updates)) {
            $params[] = $propId;
            $db->prepare("UPDATE properties SET " . implode(", ", $updates) . " WHERE id = ?")->execute($params);
        }

        $statusLabels = [
            'active'   => 'İlan onaylandı ve haritada yayına alındı.',
            'rejected' => 'İlan reddedildi.',
            'archived' => 'İlan donduruldu.'
        ];

        ob_clean();
        echo json_encode(["status" => "success", "message" => $statusLabels[$newStatus] ?? "Durum güncellendi."], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // B) İLAN SİL
    if ($action === 'delete_property') {
        $propId = (int)($input['property_id'] ?? 0);
        $db->prepare("DELETE FROM property_images WHERE property_id = ?")->execute([$propId]);
        $db->prepare("DELETE FROM properties WHERE id = ?")->execute([$propId]);

        ob_clean();
        echo json_encode(["status" => "success", "message" => "İlan ve görselleri kalıcı olarak silindi."], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // C) KULLANICI DONDUR / AKTİFLEŞTİR
    if ($action === 'toggle_user_status') {
        $targetUserId = (int)($input['target_user_id'] ?? 0);
        $newStatus = trim($input['status'] ?? 'active');

        $tStmt = $db->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $tStmt->execute([$targetUserId]);
        $targetUser = $tStmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser) throw new Exception("Kullanıcı bulunamadı.");
        if (!$isSuperAdmin && in_array($targetUser['role'], ['admin', 'superadmin'])) {
            throw new Exception("Standart yöneticiler diğer yöneticileri donduramaz.");
        }

        $userCols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('status', $userCols)) {
            $db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $targetUserId]);
        }

        ob_clean();
        echo json_encode(["status" => "success", "message" => "Kullanıcı durumu güncellendi."], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // D) ROL DEĞİŞTİR (SADECE SÜPER ADMİN)
    if ($action === 'change_user_role') {
        if (!$isSuperAdmin) throw new Exception("Yalnızca Sistem Yöneticisi rol atayabilir.");
        $targetUserId = (int)($input['target_user_id'] ?? 0);
        $newRole = trim($input['new_role'] ?? 'student');

        $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $targetUserId]);
        ob_clean();
        echo json_encode(["status" => "success", "message" => "Kullanıcı rolü başarıyla değiştirildi: " . $newRole], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // E) LOG TEMİZLE
    if ($action === 'clear_audit_logs') {
        if (!$isSuperAdmin) throw new Exception("Yalnızca Sistem Yöneticisi logları temizleyebilir.");
        $db->query("TRUNCATE TABLE audit_logs");
        ob_clean();
        echo json_encode(["status" => "success", "message" => "Denetim günlüğü temizlendi."], JSON_UNESCAPED_UNICODE);
        exit();
    }

    throw new Exception("Tanımsız aksiyon.");

} catch (Throwable $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}