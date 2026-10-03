<?php
// backend/services/SpatialAnalytics.php

class SpatialAnalytics {
    // Kıbrıs arazi/sokak kıvrım faktörü: Gerçek yol mesafesi ~ kuş uçuşunun 1.25 katıdır
    private const DETOUR_FACTOR = 1.25;
    
    // Öğrenci yürüme hızı: Dakikada ~75 metre (~4.5 km/s)
    private const WALKING_SPEED_METERS_PER_MIN = 75;
    
    // Mağusa içi ortalama araç/scooter hızı: Dakikada ~350 metre (~21 km/s)
    private const DRIVING_SPEED_METERS_PER_MIN = 350;

    /**
     * İlan verisini mesafe ve tahmini maliyet metrikleri ile zenginleştirir.
     */
    public static function enrich(array $property, float $rawDistanceMeters): array {
        $realMeters = $rawDistanceMeters * self::DETOUR_FACTOR;
        
        $walkMin = (int)ceil($realMeters / self::WALKING_SPEED_METERS_PER_MIN);
        $driveMin = (int)max(2, ceil($realMeters / self::DRIVING_SPEED_METERS_PER_MIN));

        // Finansal Yaşam Maliyeti Simülasyonu (GBP)
        $rent = (float)$property['price'];
        $dues = (float)$property['monthly_dues'];
        
        // KKTC ortalama klima ve elektrik tüketimi (Oda sayısına ve klima tipine göre)
        $inverterDiscount = !empty($property['has_inverter_ac']) ? 0.80 : 1.0; // İnverter klima %20 tasarruf
        $baseUtility = match ((int)$property['bedrooms']) {
            1 => 60.00,
            2 => 90.00,
            default => 130.00
        };
        $estUtilities = round($baseUtility * $inverterDiscount, 2);
        $totalLivingCost = round($rent + $dues + $estUtilities, 2);

        // Durum Değerlendirmesi
        $badge = match (true) {
            $walkMin <= 8  => "Kampüs İçi Hissiyatı (0-8 Dk)",
            $walkMin <= 15 => "İdeal Yürüme Mesafesi (9-15 Dk)",
            $walkMin <= 25 => "Bisiklet / Scooter Tavsiye Edilir",
            default        => "Servis / Araç Gerekir"
        };

        return [
            'distance_raw_m'       => round($rawDistanceMeters),
            'distance_real_km'     => round($realMeters / 1000, 2),
            'walk_time_min'        => $walkMin,
            'drive_time_min'       => $driveMin,
            'is_walkable'          => $walkMin <= 20,
            'badge'                => $badge,
            'financials' => [
                'base_rent_gbp'        => $rent,
                'monthly_dues_gbp'     => $dues,
                'est_utilities_gbp'    => $estUtilities,
                'total_living_cost_gbp'=> $totalLivingCost
            ]
        ];
    }

    /**
     * MariaDB/XAMPP uyumlu Haversine mesafe formülü (Kuş uçuşu metre)
     */
    public static function haversineSql(float $lat, float $lng, string $latCol = "ST_X(p.location)", string $lngCol = "ST_Y(p.location)"): string {
        return "(
            6371000 * 2 * ASIN(
                SQRT(
                    POWER(SIN(RADIANS({$lat} - {$latCol}) / 2), 2) +
                    COS(RADIANS({$lat})) * COS(RADIANS({$latCol})) *
                    POWER(SIN(RADIANS({$lng} - {$lngCol}) / 2), 2)
                )
            )
        )";
    }
}