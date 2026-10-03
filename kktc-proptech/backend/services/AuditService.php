<?php
// backend/services/AuditService.php

class AuditService {
    /**
     * Güvenlik ve denetim kaydı oluşturur.
     */
    public static function log(
        PDO $db,
        ?int $actorUserId,
        ?string $actorEmail,
        string $actorRole,
        string $action,
        string $targetEntity,
        int $targetId,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
            }
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

            $sql = "
                INSERT INTO audit_logs (
                    actor_user_id, actor_email, actor_role,
                    action, target_entity, target_id,
                    old_values, new_values, ip_address, user_agent
                ) VALUES (
                    :actor_id, :actor_email, :actor_role,
                    :action, :target_entity, :target_id,
                    :old_values, :new_values, :ip_address, :user_agent
                )
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':actor_id'      => $actorUserId,
                ':actor_email'   => $actorEmail,
                ':actor_role'    => $actorRole,
                ':action'        => $action,
                ':target_entity' => $targetEntity,
                ':target_id'     => $targetId,
                ':old_values'    => $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
                ':new_values'    => $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
                ':ip_address'    => $ip,
                ':user_agent'    => substr($userAgent, 0, 500)
            ]);
        } catch (Exception $e) {
            // Loglama hatası ana işlemi durdurmasın ama error_log'a yazılsın
            error_log("Audit Loglama Hatası: " . $e->getMessage());
        }
    }
}