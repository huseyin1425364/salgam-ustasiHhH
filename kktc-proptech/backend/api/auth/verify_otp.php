<?php
// backend/api/auth/verify_otp.php
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

    $userId  = (int)($input['user_id'] ?? 0);
    // Boşlukları ve harici karakterleri temizle, sadece 6 rakam al
    $otpCode = preg_replace('/[^0-9]/', '', (string)($input['otp_code'] ?? ''));

    if ($userId <= 0 || strlen($otpCode) !== 6) {
        throw new Exception("Lütfen 6 haneli doğrulama kodunu eksiksiz girin.");
    }

    $db = (new Database())->getConnection();

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception("Kullanıcı kaydı bulunamadı.");
    }

    // Demo/Master kod (123456) veya veritabanındaki kayıtlı kod ile doğrulama
    $validOtp = (isset($user['otp_code']) && $user['otp_code'] === $otpCode) || ($otpCode === '123456');

    if (!$validOtp) {
        throw new Exception("Girdiğiniz WhatsApp doğrulama kodu hatalı!");
    }

    // Telefon onaylandı olarak güncelle
    $cols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $upParts = [];
    if (in_array('is_phone_verified', $cols)) $upParts[] = "is_phone_verified = 1";
    if (in_array('otp_code', $cols)) $upParts[] = "otp_code = NULL";

    if (!empty($upParts)) {
        $db->prepare("UPDATE users SET " . implode(', ', $upParts) . " WHERE id = ?")->execute([$userId]);
    }

    // Güncel kullanıcı profilini dön
    $stmt->execute([$userId]);
    $finalUser = $stmt->fetch(PDO::FETCH_ASSOC);
    unset($finalUser['password_hash']);
    unset($finalUser['otp_code']);

    ob_clean();
    echo json_encode([
        "status"  => "success",
        "message" => "Telefon numaranız başarıyla doğrulandı!",
        "user"    => $finalUser
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}