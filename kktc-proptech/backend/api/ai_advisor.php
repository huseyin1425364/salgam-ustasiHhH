<?php
// backend/api/ai_advisor.php
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
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception("Geçersiz veri formatı.");
    }

    $propertyIds = $input['property_ids'] ?? [];
    $campusId    = (int)($input['campus_id'] ?? 1);
    $hasCar      = !empty($input['has_car']);

    if (empty($propertyIds) || !is_array($propertyIds)) {
        throw new Exception("Lütfen karşılaştırmak için en az 1 ilan seçin.");
    }

    // Maksimum 4 ilanı kıyasla
    $propertyIds = array_slice(array_map('intval', $propertyIds), 0, 4);
    $inPlaceholders = implode(',', array_fill(0, count($propertyIds), '?'));

    $db = (new Database())->getConnection();

    // 1. Kampüs Referans Noktası
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

    // 2. properties tablosu kolonlarını dinamik kontrol et
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
        WHERE p.id IN ({$inPlaceholders})
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($propertyIds);
    $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($listings)) {
        throw new Exception("Seçilen ilanlar bulunamadı.");
    }

    // 3. İlanları Analitik Olarak Zenginleştir
    $analyzedListings = [];
    foreach ($listings as $l) {
        $crowKm = round((float)($l['distance_km'] ?? 1.2), 2);
        $realKm = round($crowKm * 1.28, 2);
        $walkMin = max(2, (int)round($realKm * 12.5));
        $driveMin = max(1, (int)round(($realKm * 2) + 1));

        $kibtek = ($l['has_inverter_ac'] == 1) ? 45.0 : 75.0;
        $totalLivingCost = round((float)$l['price'] + (float)$l['monthly_dues'] + $kibtek + 10.0, 0);

        $l['real_km'] = $realKm;
        $l['walk_min'] = $walkMin;
        $l['drive_min'] = $driveMin;
        $l['total_cost'] = $totalLivingCost;
        $analyzedListings[] = $l;
    }

    // 4. AI Promptu Oluştur
    $promptLines = [];
    $promptLines[] = "Sen Kuzey Kıbrıs Türk Cumhuriyeti (KKTC) Gazimağusa ve Doğu Akdeniz Üniversitesi (DAÜ) emlak piyasasında uzmanlaşmış akıllı bir gayrimenkul danışmanısın.";
    $promptLines[] = "Referans Kampüs: {$campusName}. Kullanıcının aracı var mı?: " . ($hasCar ? "Evet (Araçlı)" : "Hayır (Yaya/Toplu Taşıma)");
    $promptLines[] = "\nKarşılaştırılacak İlanlar:";

    foreach ($analyzedListings as $idx => $al) {
        $n = $idx + 1;
        $furn = ($al['is_furnished'] == 1) ? "Full Eşyalı" : "Boş";
        $inv = ($al['has_inverter_ac'] == 1) ? "İnverter Klima (Tasarruflu)" : "Standart Klima (Yüksek Fatura)";
        $gen = ($al['has_generator'] == 1) ? "Jeneratör Var" : "Jeneratör Yok";
        $promptLines[] = "İlan {$n}: #{$al['id']} - {$al['title']} ({$al['district_name']}) | Kira: £{$al['price']}/ay | Aidat: £{$al['monthly_dues']} | Tahmini Toplam Yaşam: £{$al['total_cost']}/ay | Mesafe: {$al['real_km']} km | Yürüme: {$al['walk_min']} dk | Sürüş: {$al['drive_min']} dk | {$al['bedrooms']}+1, {$al['area_sqm']} m² | {$furn} | {$inv} | {$gen}";
    }

    $promptLines[] = "\nGÖREV: Bu ilanları doğrudan karşılaştır. Lafı dolandırma. Şu 3 başlık altında net maddelerle yanıt ver:";
    $promptLines[] = "1. **Fiyat/Performans ve Gerçek Maliyet (TrueCost™)**: Hangisi faturalar ve aidat dahil daha hesaplı?";
    $promptLines[] = "2. **Ulaşım ve Konum Avantajı**: Kampüse erişilebilirlik açısından hangisi öğrenciye daha uygun?";
    $promptLines[] = "3. **Sonuç ve Net Öneri**: Hangi öğrenci profili hangi evi seçmeli?";

    $fullPrompt = implode("\n", $promptLines);

    // 5. Google Gemini API Çağrısı (Fallback Güvenlikli)
    $apiKey = getenv('GEMINI_API_KEY') ?: '';
    $reportText = '';
    $engine = 'IslandProp AI Analiz Motoru';

    if (!empty($apiKey)) {
        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . urlencode($apiKey));
        $postData = [
            "contents" => [
                ["parts" => [["text" => $fullPrompt]]]
            ],
            "generationConfig" => [
                "temperature" => 0.4,
                "maxOutputTokens" => 800
            ]
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

    // 6. Gemini Yanıt Vermezse veya API Yoksa Kusursuz Algoritmik Rapor Üret (Fallback)
    if (empty($reportText)) {
        usort($analyzedListings, function($a, $b) {
            return $a['total_cost'] <=> $b['total_cost'];
        });

        $bestCost = $analyzedListings[0];
        $closest  = $analyzedListings[0];
        foreach ($analyzedListings as $item) {
            if ($item['walk_min'] < $closest['walk_min']) {
                $closest = $item;
            }
        }

        $reportText = "### 1. Fiyat/Performans ve Gerçek Maliyet (TrueCost™)\n";
        $reportText .= "- **En Ekonomik Tercih:** #{$bestCost['id']} - **{$bestCost['title']}** (Aylık toplam yaşam maliyeti yaklaşık **£{$bestCost['total_cost']}**).\n";
        foreach ($analyzedListings as $al) {
            $acNote = ($al['has_inverter_ac'] == 1) ? "İnverter klima sayesinde KIB-TEK faturasında aylık ~£25 tasarruf sağlar." : "Standart klima yaz aylarında yüksek elektrik faturası oluşturabilir.";
            $reportText .= "- **#{$al['id']} ({$al['district_name']}):** Taban kira £{$al['price']}, aidat £{$al['monthly_dues']}. {$acNote}\n";
        }

        $reportText .= "\n### 2. Ulaşım ve Kampüs Erişimi ({$campusName})\n";
        $reportText .= "- **En Yakın Konum:** #{$closest['id']} - **{$closest['title']}**, kampüse sadece **{$closest['walk_min']} dakika** yürüme mesafesinde.\n";
        if (!$hasCar) {
            $reportText .= "- Aracınız olmadığı için DAÜ kampüsüne 15 dakikadan kısa sürede yürünebilen evler öncelikli olmalıdır.\n";
        } else {
            $reportText .= "- Aracınız bulunduğu için sürüş süreleri tüm seçeneklerde 2-5 dakika aralığında olup konum esnekliği sunmaktadır.\n";
        }

        $reportText .= "\n### 3. Sonuç ve Net Tavsiye\n";
        $reportText .= "- Bütçe odaklı bir öğrenci için **#{$bestCost['id']} ({$bestCost['district_name']})**, derslere hızlı ulaşım ve sıfır yol masrafı isteyen için ise **#{$closest['id']}** en mantıklı tercihtir.";
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