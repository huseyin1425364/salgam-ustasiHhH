<?php
// backend/api/auth/register.php
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

require_once '../../config/Database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $fullName   = trim($input['full_name'] ?? '');
    $email      = trim($input['email'] ?? '');
    $phone      = trim($input['phone'] ?? '');
    $cityRegion = trim($input['city_region'] ?? 'Gazimağusa');
    $role       = trim($input['role'] ?? 'student');
    $password   = $input['password'] ?? '';
    $passConf   = $input['password_confirm'] ?? '';

    if (empty($fullName) || empty($email) || empty($phone) || empty($password)) {
        throw new Exception("Lütfen tüm zorunlu alanları doldurun.");
    }

    if ($password !== $passConf) {
        throw new Exception("Şifreler birbiriyle eşleşmiyor.");
    }

    if (strlen($password) < 6) {
        throw new Exception("Şifre en az 6 karakter olmalıdır.");
    }

    $db = (new Database())->getConnection();

    // E-posta veya telefon mükerrerlik kontrolü
    $checkStmt = $db->prepare("SELECT id FROM users WHERE email = ? OR phone = ? LIMIT 1");
    $checkStmt->execute([$email, $phone]);
    if ($checkStmt->fetch()) {
        throw new Exception("Bu e-posta veya telefon numarası zaten sisteme kayıtlı.");
    }

    // STANDART: Kesinlikle 6 haneli güvenli OTP kodu üretilir (100000 - 999999)
    $otpCode = (string)rand(100000, 999999);
    $passHash = password_hash($password, PASSWORD_BCRYPT);

    // users tablosu kolonlarını dinamik kontrol et
    $cols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);

    $insertCols = ['full_name', 'email', 'phone', 'password_hash', 'role'];
    $insertPlaceholders = ['?', '?', '?', '?', '?'];
    $insertValues = [$fullName, $email, $phone, $passHash, $role];

    if (in_array('city_region', $cols)) {
        $insertCols[] = 'city_region';
        $insertPlaceholders[] = '?';
        $insertValues[] = $cityRegion;
    }

    if (in_array('status', $cols)) {
        $insertCols[] = 'status';
        $insertPlaceholders[] = '?';
        $insertValues[] = 'active';
    }

    if (in_array('otp_code', $cols)) {
        $insertCols[] = 'otp_code';
        $insertPlaceholders[] = '?';
        $insertValues[] = $otpCode;
    }

    $sql = "INSERT INTO users (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertPlaceholders) . ")";
    $stmt = $db->prepare($sql);
    $stmt->execute($insertValues);

    $userId = (int)$db->lastInsertId();

    // users tablosunda otp_code kolonu yoksa ekleyelim
    if (!in_array('otp_code', $cols)) {
        @$db->query("ALTER TABLE users ADD COLUMN otp_code VARCHAR(10) NULL");
        $up = $db->prepare("UPDATE users SET otp_code = ? WHERE id = ?");
        $up->execute([$otpCode, $userId]);
    }

    ob_clean();
    echo json_encode([
        "status"   => "success",
        "message"  => "Kayıt oluşturuldu, WhatsApp doğrulama kodu gönderildi.",
        "user_id"  => $userId,
        "phone"    => $phone,
        "demo_otp" => $otpCode // 6 haneli demo kod
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}