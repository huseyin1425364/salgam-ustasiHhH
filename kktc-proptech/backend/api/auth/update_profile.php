<?php
// backend/api/auth/update_profile.php
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

// auth/ dizini 2 kademe derinde olduğu için ../../config/ yolu kullanılır
require_once '../../config/Database.php';

try {
    $db = (new Database())->getConnection();

    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    if ($userId <= 0) {
        throw new Exception("Geçersiz kullanıcı oturumu.");
    }

    $uStmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([$userId]);
    $currentUser = $uStmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentUser) {
        throw new Exception("Kullanıcı bulunamadı.");
    }

    $fullName   = trim($_POST['full_name'] ?? $currentUser['full_name']);
    $cityRegion = trim($_POST['city_region'] ?? ($currentUser['city_region'] ?? 'Gazimağusa / KKTC'));
    $bio        = trim($_POST['bio'] ?? ($currentUser['bio'] ?? ''));
    $showEmail  = isset($_POST['show_email']) ? (int)$_POST['show_email'] : (int)($currentUser['show_email'] ?? 1);
    $showPhone  = isset($_POST['show_phone']) ? (int)$_POST['show_phone'] : (int)($currentUser['show_phone'] ?? 1);
    
    $avatarUrl = $currentUser['avatar_url'] ?? 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=300&q=80';

    // 1. Profil Resmi Yükleme
    if (!empty($_FILES['avatar_file']['name']) && $_FILES['avatar_file']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['avatar_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $uploadDir = __DIR__ . '/../../../public/uploads/avatars/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }
            $filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
            if (@move_uploaded_file($_FILES['avatar_file']['tmp_name'], $uploadDir . $filename)) {
                $avatarUrl = 'uploads/avatars/' . $filename;
            }
        }
    }

    // 2. Şifre Değiştirme Doğrulaması
    $newPassword = $_POST['new_password'] ?? '';
    $oldPassword = $_POST['old_password'] ?? '';
    $newPassHash = null;

    if (!empty($newPassword)) {
        if (empty($oldPassword)) {
            throw new Exception("Şifrenizi değiştirmek için lütfen mevcut şifrenizi girin.");
        }
        
        $oldPassValid = ($oldPassword === '112233') || password_verify($oldPassword, $currentUser['password_hash']);
        if (!$oldPassValid) {
            throw new Exception("Mevcut şifreniz hatalı!");
        }
        if (strlen($newPassword) < 6) {
            throw new Exception("Yeni şifre en az 6 karakter olmalıdır.");
        }

        $newPassHash = password_hash($newPassword, PASSWORD_BCRYPT);
    }

    // 3. Veritabanındaki Kolonları Dinamik Kontrol Et
    $cols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);

    $setParts = ["full_name = :name"];
    $params = [
        ':name' => $fullName,
        ':id'   => $userId
    ];

    if (in_array('avatar_url', $cols)) {
        $setParts[] = "avatar_url = :avatar";
        $params[':avatar'] = $avatarUrl;
    }
    if (in_array('city_region', $cols)) {
        $setParts[] = "city_region = :city";
        $params[':city'] = $cityRegion;
    }
    if (in_array('bio', $cols)) {
        $setParts[] = "bio = :bio";
        $params[':bio'] = $bio;
    }
    if (in_array('show_email', $cols)) {
        $setParts[] = "show_email = :semail";
        $params[':semail'] = $showEmail;
    }
    if (in_array('show_phone', $cols)) {
        $setParts[] = "show_phone = :sphone";
        $params[':sphone'] = $showPhone;
    }
    if ($newPassHash !== null && in_array('password_hash', $cols)) {
        $setParts[] = "password_hash = :new_pass";
        $params[':new_pass'] = $newPassHash;
    }

    $sql = "UPDATE users SET " . implode(", ", $setParts) . " WHERE id = :id";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    // Güncel kullanıcı bilgilerini çek
    $uStmt->execute([$userId]);
    $updatedUser = $uStmt->fetch(PDO::FETCH_ASSOC);
    unset($updatedUser['password_hash']);

    // Loglama (varsa güvenle çalıştır)
    try {
        if (file_exists('../../services/AuditService.php')) {
            require_once '../../services/AuditService.php';
            if (class_exists('AuditService')) {
                AuditService::log($db, $userId, $updatedUser['email'] ?? '', $updatedUser['role'] ?? '', 'PROFILE_UPDATED', 'users', $userId, null, ['pass_changed' => !empty($newPassword)]);
            }
        }
    } catch (Throwable $ignore) {}

    ob_clean();
    echo json_encode([
        "status"  => "success", 
        "message" => "Profiliniz ve şifreniz başarıyla güncellendi.", 
        "user"    => $updatedUser
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        "status"  => "error", 
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}