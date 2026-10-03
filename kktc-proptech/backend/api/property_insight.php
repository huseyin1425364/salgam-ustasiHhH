<?php
// backend/api/property_insight.php
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

try {
    $propId   = (int)($_GET['id'] ?? 0);
    $campusId = (int)($_GET['campus_id'] ?? 1);

    if ($propId <= 0) {
        throw new Exception("Geçersiz ilan kimliği.");
    }

    $db = (new Database())->getConnection();

    // 1. Kampüs Koordinatları
    $refLat = 35.1424;
    $refLng = 33.9117;
    $campusName = "DAÜ Kampüs Merkezi";

    $hasGates = $db->query("SHOW TABLES LIKE 'campus_gates'")->fetch();
    if ($hasGates) {
        $gStmt = $db->prepare("SELECT name, ST_X(location) as lat, ST_Y(location) as lng FROM campus_gates WHERE id = ? LIMIT 1");
        $gStmt->execute([$campusId]);
        $gate = $gStmt->fetch(PDO::FETCH_ASSOC);
        if ($gate && !empty($gate['lat'])) {
            $refLat = (float)$gate['lat'];
            $refLng = (float)$gate['lng'];
            $campusName = $gate['name'];
        }
    }

    // 2. İlan Detaylarını Al
    $propCols = $db->query("SHOW COLUMNS FROM properties")->fetchAll(PDO::FETCH_COLUMN);

    $hasDeposit = in_array('deposit_amount', $propCols) ? "COALESCE(p.deposit_amount, p.price * 2)" : "(p.price * 2)";
    $hasDues    = in_array('monthly_dues', $propCols) ? "COALESCE(p.monthly_dues, 0)" : "0";
    $hasPeriod  = in_array('payment_period', $propCols) ? "COALESCE(p.payment_period, 'monthly')" : "'monthly'";
    $hasArea    = in_array('area_sqm', $propCols) ? "COALESCE(p.area_sqm, 65)" : "65";
    $hasFurn    = in_array('is_furnished', $propCols) ? "COALESCE(p.is_furnished, 1)" : "1";
    $hasInv     = in_array('has_inverter_ac', $propCols) ? "COALESCE(p.has_inverter_ac, 1)" : "1";
    $hasGen     = in_array('has_generator', $propCols) ? "COALESCE(p.has_generator, 0)" : "0";
    $hasTank    = in_array('has_water_tank', $propCols) ? "COALESCE(p.has_water_tank, 1)" : "1";

    $sql = "
        SELECT 
            p.id, p.title, p.price, p.bedrooms,
            {$hasDeposit} AS deposit_amount,
            {$hasDues} AS monthly_dues,
            {$hasPeriod} AS payment_period,
            {$hasArea} AS area_sqm,
            {$hasFurn} AS is_furnished,
            {$hasInv} AS has_inverter_ac,
            {$hasGen} AS has_generator,
            {$hasTank} AS has_water_tank,
            ST_X(p.location) AS lat,
            ST_Y(p.location) AS lng,
            COALESCE(d.name, 'Gazimağusa') AS district_name,
            (
                6371 * ACOS(
                    LEAST(1.0, GREATEST(-1.0,
                        COS(RADIANS({$refLat})) * COS(RADIANS(ST_X(p.location))) *
                        COS(RADIANS(ST_Y(p.location)) - RADIANS({$refLng})) +
                        SIN(RADIANS({$refLat})) * SIN(RADIANS(ST_X(p.location)))
                    ))
                )
            ) AS distance_km
        FROM properties p
        LEFT JOIN districts d ON d.id = p.district_id
        WHERE p.id = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([$propId]);
    $property = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$property) {
        throw new Exception("İlan bulunamadı.");
    }

    // 3. Bölgedeki Emsal Ortalamasını Hesapla
    $avgStmt = $db->prepare("SELECT AVG(price) as avg_price, COUNT(*) as total_peer FROM properties WHERE district_id = (SELECT district_id FROM properties WHERE id = ?)");
    $avgStmt->execute([$propId]);
    $peerData = $avgStmt->fetch(PDO::FETCH_ASSOC);
    $avgPrice = round((float)($peerData['avg_price'] ?? $property['price']), 0);
    $peerCount = (int)($peerData['total_peer'] ?? 1);

    // Mesafe ve Maliyet Projeksiyonu
    $crowKm = round((float)($property['distance_km'] ?? 1.2), 2);
    $realKm = round($crowKm * 1.28, 2);
    $walkMin = max(2, (int)round($realKm * 12.5));
    $driveMin = max(1, (int)round(($realKm * 2) + 1));

    $kibtek = ($property['has_inverter_ac'] == 1) ? 45.0 : 75.0;
    $totalLivingCost = round((float)$property['price'] + (float)$property['monthly_dues'] + $kibtek + 10.0, 0);

    // 4. Prompt Oluştur
    $furn = ($property['is_furnished'] == 1) ? "Full Eşyalı" : "Eşyasız";
    $inv = ($property['has_inverter_ac'] == 1) ? "İnverter Klima Mevcut (A++ Tasarruf)" : "Standart Klima";
    $gen = ($property['has_generator'] == 1) ? "Jeneratör Var (Kesintisiz)" : "Jeneratör Yok";

    $prompt = "Sen KKTC Gazimağusa ve DAÜ bölgesinde uzman bir emlak analistisin.\n"
        . "İlan: #{$property['id']} - {$property['title']} ({$property['district_name']})\n"
        . "Kira: £{$property['price']}/ay | Bölge Emsal Ortalaması: £{$avgPrice}/ay ({$peerCount} ilan)\n"
        . "Aidat: £{$property['monthly_dues']} | Tahmini Toplam Yaşam Maliyeti: £{$totalLivingCost}/ay\n"
        . "DAÜ Kampüsüne Mesafe: {$realKm} km | Yürüme: {$walkMin} dk | Sürüş: {$driveMin} dk\n"
        . "Donanımlar: {$property['bedrooms']}+1, {$property['area_sqm']} m², {$furn}, {$inv}, {$gen}\n\n"
        . "Lafı uzatmadan, doğrudan şu 3 başlık altında hap bilgilerle rapor ver:\n"
        . "1. **Bölgesel Emsal ve Fiyat Analizi**: İlan bölge ortalamasına göre ucuz mu, pahalı mı?\n"
        . "2. **KKTC Enerji & Donanım Durumu**: Klima ve su altyapısı faturayı nasıl etkiler?\n"
        . "3. **Öğrenci Yaşam Değerlendirmesi**: Bu evi tutacak öğrenci için avantaj ve dezavantajlar nelerdir?";

    // 5. Gemini API Çağrısı (Fallback'li)
    $apiKey = getenv('GEMINI_API_KEY') ?: '';
    $reportText = '';
    $engine = 'IslandProp Spatial Zekası';

    if (!empty($apiKey)) {
        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . urlencode($apiKey));
        $postData = [
            "contents" => [["parts" => [["text" => $prompt]]]],
            "generationConfig" => ["temperature" => 0.4, "maxOutputTokens" => 700]
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);

        $apiRes = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $apiRes) {
            $apiData = json_decode($apiRes, true);
            $reportText = $apiData['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if (!empty($reportText)) {
                $engine = 'Google Gemini Live Intelligence';
            }
        }
    }

    // 6. Algoritmik Fallback Rapor
    if (empty($reportText)) {
        $diff = (float)$property['price'] - $avgPrice;
        $priceVerdict = ($diff <= 0) 
            ? "Bölge ortalamasının (**£{$avgPrice}**) altında veya denginde seyrediyor; bütçe dostu bir seçenek." 
            : "Bölge ortalamasının (**£{$avgPrice}**) üzerinde seyrediyor; donanım ve bina kalitesi göz önünde bulundurulmalı.";

        $reportText = "### 1. Bölgesel Emsal ve Fiyat Analizi\n";
        $reportText .= "- **Fiyat Durumu:** {$property['district_name']} bölgesindeki benzer ilanlara göre {$priceVerdict}\n";
        $reportText .= "- **Tahmini TrueCost™:** Kira (£{$property['price']}) + Aidat (£{$property['monthly_dues']}) + Elektrik/Su tahmini ile aylık toplam masraf **£{$totalLivingCost}** civarındadır.\n\n";

        $reportText .= "### 2. KKTC Enerji & Altyapı Durumu\n";
        if ($property['has_inverter_ac'] == 1) {
            $reportText .= "- **İnverter Klima (A++):** Kıbrıs'ın sıcak aylarında standart klimalara kıyasla KIB-TEK faturasında aylık ortalama £25-£30 tasarruf sağlar.\n";
        } else {
            $reportText .= "- **Standart Klima:** Yoğun soğutma dönemlerinde elektrik faturası bütçeyi zorlayabilir.\n";
        }
        $reportText .= ($property['has_generator'] == 1) ? "- **Jeneratör:** Bölgesel elektrik kesintilerinde kesintisiz konfor sağlar.\n" : "- **Jeneratör:** Binada jeneratör bulunmamaktadır.\n";

        $reportText .= "\n### 3. Öğrenci Yaşam Değerlendirmesi ({$campusName})\n";
        $reportText .= "- **Ulaşım:** DAÜ Kampüsüne yürüyerek yaklaşık **{$walkMin} dakika**, araçla **{$driveMin} dakika** mesafededir.\n";
        $reportText .= ($walkMin <= 15) ? "- **Yürüme Avantajı:** Günlük yol masrafı olmadan yürüyerek derslere gidip gelinebilir." : "- **Ulaşım Tavsiyesi:** Yürüme süresi 15 dakikanın üzerinde olduğu için bisiklet veya servis kullanımı tavsiye edilir.";
    }

    ob_clean();
    echo json_encode([
        "status" => "success",
        "engine" => $engine,
        "report" => $reportText
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}