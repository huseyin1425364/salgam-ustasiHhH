<?php
// backend/config/Database.php

class Database {
    private string $host = "localhost";
    private string $db_name = "kktc_proptech";
    private string $username = "root";
    private string $password = ""; // XAMPP varsayılanında şifre boştur

    public function getConnection(): PDO {
        $conn = null;
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4";
            $conn = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header("Content-Type: application/json; charset=UTF-8");
            echo json_encode([
                "status"  => "error",
                "message" => "Veritabanı bağlantı hatası: " . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
        return $conn;
    }
}