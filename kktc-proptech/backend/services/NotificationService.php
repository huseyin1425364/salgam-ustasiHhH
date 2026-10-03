<?php
// backend/services/NotificationService.php

class NotificationService {
    /**
     * WhatsApp OTP Gönderici (Geliştirme / Demo Modu)
     * Canlıya geçerken Twilio veya Meta WhatsApp Cloud API endpoint'i bağlanır.
     */
    public static function sendWhatsAppOtp(string $phone, string $otpCode): array {
        // Canlı modda buraya cURL Meta API çağrısı gelir.
        // Demo modda kodu ve mesaj şablonunu döneriz:
        $message = "🔒 IslandProp Güvenlik Kodu: {$otpCode}. Bu kodu kimseyle paylaşmayınız. DAÜ Güvenli Öğrenci Konut Ağı.";
        
        // Simülasyon logu
        error_log("[WHATSAPP OTP DEMO] Alıcı: {$phone} | Kod: {$otpCode}");

        return [
            "success" => true,
            "provider" => "IslandProp WhatsApp Gateway (Dev/Sandbox)",
            "phone" => $phone,
            "demo_code" => $otpCode, // Geliştirme kolaylığı için API cevabında da döneriz
            "message" => $message
        ];
    }

    /**
     * E-posta Aktivasyon Bağlantısı
     */
    public static function sendEmailVerification(string $email, string $token): bool {
        $verifyLink = "http://localhost/kktc-proptech/backend/api/auth/verify_email.php?token=" . $token;
        
        // PHP mail() veya SMTP PHPMailer entegrasyonu:
        $subject = "IslandProp Hesap Doğrulama";
        $headers = "From: noreply@islandprop.com\r\nContent-Type: text/html; charset=UTF-8";
        $body = "
            <h2>IslandProp Hesabınızı Doğrulayın</h2>
            <p>Doğu Akdeniz Üniversitesi güvenli emlak ağına hoş geldiniz.</p>
            <p>Hesabınızı aktif etmek için lütfen aşağıdaki bağlantıya tıklayın:</p>
            <p><a href='{$verifyLink}'>{$verifyLink}</a></p>
        ";

        // Yerel XAMPP'ta mail server kurulu değilse error_log'a basarız
        @mail($email, $subject, $body, $headers);
        error_log("[EMAIL VERIFY DEMO] Link: " . $verifyLink);

        return true;
    }
}