<?php
/*
|--------------------------------------------------------------------------
| Simple Model API Key Functions
|--------------------------------------------------------------------------
| Direct API key management functions for Simple Model architecture
| Replaces the Laravel App\Auth\ApiKeyAuth class
*/

/**
 * Generate a new API token
 */
function generateApiToken($pdo, $userId, $name, $abilities = ['*'], $expiresAt = null) {
    try {
        // Generate secure token
        $token = bin2hex(random_bytes(32)); // 64 character token
        
        // Insert into database
        $stmt = $pdo->prepare("
            INSERT INTO api_tokens (user_id, name, token, abilities, expires_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        $abilitiesJson = json_encode($abilities);
        $stmt->execute([$userId, $name, $token, $abilitiesJson, $expiresAt]);
        
        return [
            'id' => $pdo->lastInsertId(),
            'token' => $token,
            'name' => $name,
            'abilities' => $abilities,
            'expires_at' => $expiresAt
        ];
        
    } catch (Exception $e) {
        error_log("Failed to generate API token: " . $e->getMessage());
        return false;
    }
}

/**
 * Revoke an API token
 */
function revokeApiToken($pdo, $token) {
    try {
        $stmt = $pdo->prepare("DELETE FROM api_tokens WHERE token = ?");
        $stmt->execute([$token]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log("Failed to revoke API token: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all tokens for a user
 */
function getUserApiTokens($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, name, token, abilities, expires_at, last_used_at, created_at, updated_at
            FROM api_tokens 
            WHERE user_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Failed to get user API tokens: " . $e->getMessage());
        return [];
    }
}

/**
 * Validate an API token and return user data
 */
function validateApiToken($pdo, $token) {
    try {
        $stmt = $pdo->prepare("
            SELECT at.*, u.id as user_id, u.name, u.email, u.role, u.active
            FROM api_tokens at
            JOIN users u ON at.user_id = u.id
            WHERE at.token = ? AND u.active = 1
        ");
        $stmt->execute([$token]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tokenData) {
            return false;
        }
        
        // Check if token is expired
        if ($tokenData['expires_at'] && strtotime($tokenData['expires_at']) < time()) {
            return false;
        }
        
        // Update last used timestamp
        $updateStmt = $pdo->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE token = ?");
        $updateStmt->execute([$token]);
        
        return $tokenData;
        
    } catch (Exception $e) {
        error_log("Failed to validate API token: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if API tokens table exists and create if needed
 */
function ensureApiTokensTable($pdo) {
    try {
        $stmt = $pdo->query("
            CREATE TABLE IF NOT EXISTS api_tokens (
                id BIGINT PRIMARY KEY AUTO_INCREMENT,
                user_id BIGINT NOT NULL,
                name VARCHAR(255) NOT NULL,
                token VARCHAR(64) UNIQUE NOT NULL,
                abilities JSON NULL,
                last_used_at DATETIME NULL,
                expires_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (user_id),
                INDEX (token),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        return true;
    } catch (Exception $e) {
        error_log("Failed to ensure API tokens table: " . $e->getMessage());
        return false;
    }
}