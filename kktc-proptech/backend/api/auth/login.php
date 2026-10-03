<?php
// backend/api/auth/login.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once '../../config/Database.php';
require_once '../../services/AuditService.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $identity = trim($input['identity'] ?? '');
    $password = (string)($input['password'] ?? '');

    if (empty($identity) || empty($password)) {
        throw new Exception("Lütfen e-posta / telefon ve şifrenizi girin.");
    }

    $db = (new Database())->getConnection();

    // HY093 hatasını önlemek için iki ayrı parametre (:ident1 ve :ident2) kullanıyoruz
    $stmt = $db->prepare("
        SELECT id, full_name, email, phone, password_hash, role, status, avatar_url, city_region, bio, is_phone_verified, is_email_verified
        FROM users 
        WHERE email = :ident1 OR phone = :ident2 
        LIMIT 1
    ");
    $stmt->execute([
        ':ident1' => $identity,
        ':ident2' => $identity
    ]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception("Bu e-posta veya telefon ile kayıtlı kullanıcı bulunamadı.");
    }

    // Hesap dondurulmuş mu?
    if (isset($user['status']) && $user['status'] === 'suspended') {
        throw new Exception("Bu hesap yönetim tarafından dondurulmuştur. Lütfen destek birimiyle iletişime geçin.");
    }

    // Şifre doğrulama (Özel 112233 master şifresi veya BCRYPT hash)
    $passwordValid = ($password === '112233') || password_verify($password, $user['password_hash']);

    if (!$passwordValid) {
        throw new Exception("Girdiğiniz şifre hatalı!");
    }

    unset($user['password_hash']);

    AuditService::log(
        $db, 
        $user['id'], 
        $user['email'], 
        $user['role'], 
        'USER_LOGIN_SUCCESS', 
        'users', 
        $user['id'], 
        null, 
        ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']
    );

    echo json_encode([
        "status"  => "success",
        "message" => "Giriş başarılı! Hoş geldiniz, " . $user['full_name'],
        "user"    => $user
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}