<?php

require_once __DIR__ . '/bootstrap.php';

const PASSWORD_RESET_TOKEN_TTL_SECONDS = 3600;

function password_reset_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function password_reset_create(PDO $pdo, int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id');
        $delete->execute([':user_id' => $userId]);

        $insert = $pdo->prepare("
            INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
            VALUES (:user_id, :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR), UTC_TIMESTAMP())
        ");
        $insert->execute([
            ':user_id' => $userId,
            ':token_hash' => $tokenHash,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $token;
}

function password_reset_request_is_throttled(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
          FROM password_reset_tokens
         WHERE user_id = :user_id
           AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)
         LIMIT 1
    ");
    $stmt->execute([':user_id' => $userId]);
    return (bool)$stmt->fetchColumn();
}

function password_reset_find_valid(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT prt.id AS reset_id, prt.user_id, u.email, u.first_name, u.last_name
          FROM password_reset_tokens prt
          JOIN users u ON u.id = prt.user_id
         WHERE prt.token_hash = :token_hash
           AND prt.used_at IS NULL
           AND prt.expires_at > UTC_TIMESTAMP()
         LIMIT 1
    ");
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function password_reset_complete(PDO $pdo, int $resetId, int $userId, string $passwordHash): bool
{
    $pdo->beginTransaction();
    try {
        $consume = $pdo->prepare("
            UPDATE password_reset_tokens
               SET used_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND user_id = :user_id
               AND used_at IS NULL
               AND expires_at > UTC_TIMESTAMP()
        ");
        $consume->execute([':id' => $resetId, ':user_id' => $userId]);
        if ($consume->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }

        $update = $pdo->prepare("UPDATE users SET password_hash = :password_hash, auth_source = 'local' WHERE id = :id");
        $update->execute([':password_hash' => $passwordHash, ':id' => $userId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
