<?php
// backend/api/nlp_search.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit();
}

require_once '../config/Database.php';

$input = json_decode(file_get_contents('php://input'), true);
$query = trim($input['query'] ?? '');

if (empty($query)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Arama sorgusu boş olamaz."]);
    exit();
}

$db = (new Database())->getConnection();

// Küçük harfe çevirip normalize et (Türkçe karakter duyarlı)
$q = mb_strtolower($query, 'UTF-8');

$parsed = [
    'campus_id'    => null,
    'campus_name'  => null,
    'max_price'    => null,
    'bedrooms'     => null,
    'max_walk'     => null,
    'tags_detected' => []
];

// 1. Kampüs Noktası Tespiti
$campusPoints = $db->query("SELECT id, name FROM campus_locations WHERE university_id = 1")->fetchAll();
foreach ($campusPoints as $cp) {
    $cName = mb_strtolower($cp['name'], 'UTF-8');
    if (
        (str_contains($cName, 'mühendislik') && str_contains($q, 'mühendis')) ||
        (str_contains($cName, 'kütüphane') && str_contains($q, 'kütüphane')) ||
        (str_contains($cName, 'derslik') && (str_contains($q, 'derslik') || str_contains($q, 'cl'))) ||
        (str_contains($cName, 'salamis') && (str_contains($q, 'salamis') || str_contains($q, 'ana kapı') || str_contains($q, '2 nolu')))
    ) {
        $parsed['campus_id'] = (int)$cp['id'];
        $parsed['campus_name'] = $cp['name'];
        $parsed['tags_detected'][] = "Kampüs: " . $cp['name'];
        break;
    }
}

// 2. Fiyat Tespiti (Örn: "450 pound", "400£", "500 sterlin altı", "< 450", "max 450")
if (preg_match('/(\d+)\s*(pound|sterlin|gbp|£|tl|euro|usd)?\s*(altı|kadar|max|maksimum)?/iu', $q, $matches)) {
    $foundNum = (int)$matches[1];
    // Eğer bulunan sayı yürüme dakikası değilse ve makul bir kira aralığındaysa (150 - 3000)
    if ($foundNum >= 150 && $foundNum <= 3000) {
        $parsed['max_price'] = $foundNum;
        $parsed['tags_detected'][] = "Maks. Fiyat: £" . $foundNum;
    }
}

// 3. Oda Sayısı Tespiti (Örn: "1+1", "2+1", "3+1", "stüdyo", "tek kişilik")
if (preg_match('/(\d)\s*\+\s*1/i', $q, $matches)) {
    $parsed['bedrooms'] = (int)$matches[1];
    $parsed['tags_detected'][] = "Oda: " . $matches[1] . "+1";
} elseif (str_contains($q, 'stüdyo') || str_contains($q, 'studio') || str_contains($q, 'tek kişilik')) {
    $parsed['bedrooms'] = 1;
    $parsed['tags_detected'][] = "Oda: Stüdyo / 1+1";
}

// 4. Yürüme Süresi Tespiti (Örn: "15 dk", "10 dakika", "15 dakika yürüme")
if (str_contains($q, 'arabam var') || str_contains($q, 'araçla') || str_contains($q, 'araba ile') || str_contains($q, 'farketmez') || str_contains($q, 'fark etmez')) {
    $parsed['max_walk'] = ""; // Filtresiz
    $parsed['tags_detected'][] = "Ulaşım: Arabam Var / Mesafe Serbest";
} elseif (preg_match('/(\d+)\s*(dk|dakika)\s*(yürüme|yürüyüş)?/iu', $q, $matches)) {
    $walkVal = (int)$matches[1];
    if ($walkVal <= 45) {
        $parsed['max_walk'] = $walkVal;
        $parsed['tags_detected'][] = "Maks. " . $walkVal . " Dk Yürüyüş";
    }
}

echo json_encode([
    "status" => "success",
    "query" => $query,
    "parsed" => $parsed
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);