<?php
// backend/services/GeminiService.php

class GeminiService {
    private static string $apiKey = "AQ.Ab8RN6IzHerydo4CUxtDHfYXaGQ9TSdV8d-9qFMsu9-W17aprQ";
    
    // Sırasıyla denenecek model zinciri (Biri meşgulse diğerine geçer)
    private static array $models = [
        "gemini-2.5-flash",
        "gemini-2.0-flash",
        "gemini-1.5-flash"
    ];

    /**
     * Gemini API'ye istek atar. Yoğunluk veya hata olursa bir sonraki modeli dener.
     */
    public static function generateAnalysis(string $prompt): string {
        $lastError = "";

        foreach (self::$models as $modelName) {
            $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent?key=" . self::$apiKey;

            $payload = [
                "contents" => [
                    [
                        "parts" => [
                            ["text" => $prompt]
                        ]
                    ]
                ],
                "generationConfig" => [
                    "temperature" => 0.4,
                    "maxOutputTokens" => 3000
                ]
            ];

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $response) {
                $result = json_decode($response, true);
                $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
                if (!empty($text)) {
                    return $text;
                }
            } else {
                $errData = json_decode($response, true);
                $lastError = $errData['error']['message'] ?? "HTTP {$httpCode}";
                // Eğer model bulunamadı veya yoğunluk varsa sonraki modeli dene
                continue;
            }
        }

        // Eğer Google tarafında tüm modeller meşgulse kullanıcıyı hatayla karşılaştırmamak için yerel motor devreye girsin
        throw new Exception("GEMINI_BUSY: " . $lastError);
    }
}