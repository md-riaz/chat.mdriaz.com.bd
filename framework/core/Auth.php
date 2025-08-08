<?php

namespace Framework\Core;

use Framework\Core\DBManager;

class Auth
{
    public static $userId;
    public static $name;
    public static $email;
    public static $username;
    public static $sessionId;
    public static $token;
    public static $authType;

    /**
     * Simple permission check for chat system
     * In chat context, users can access their own data and conversations they participate in
     */
    public static function checkPermission($section, $controller, $action)
    {
        // For chat system, we use simple ownership-based permissions
        // More complex permissions can be added later if needed

        $user = self::currentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 401, 'msg' => 'Authentication required']);
            exit();
        }

        // Basic permission check - users can access their own resources
        // Specific resource-level permissions are handled in individual controllers
        return true;
    }

    private static function authorized()
    {
        $token = self::getTokenFromAuthorizationHeader();
        if ($token) {
            return self::authorizeWithToken($token);
        }

        return false;
    }

    /**
     * Public helper to get the current authenticated user from Bearer token.
     * Returns an associative array with keys: user_id, name, email, username, session_id, token
     * or null if not authenticated.
     */
    public static function currentUser(): ?array
    {
        $db = DBManager::getDB();

        $token = null;
        // Try common Authorization headers (case-insensitive)
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach (
            [
                'Authorization',
                'authorization',
                'AUTHORIZATION'
            ] as $h
        ) {
            if (!empty($headers[$h])) {
                if (preg_match('/Bearer\s+(.*)$/i', $headers[$h], $m)) {
                    $token = $m[1];
                    break;
                }
            }
        }
        // Fallback to server var
        if (!$token) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $m)) {
                $token = $m[1];
            }
        }

        if (!$token) {
            return null;
        }

        // Query auth_tokens table as per the current schema
        $userQuery = $db->query(
            "SELECT u.id, u.name, u.email, u.username, at.id as session_id
             FROM auth_tokens at
             JOIN users u ON u.id = at.user_id
             WHERE at.token = ?
             AND at.revoked_at IS NULL
             AND (at.expires_at IS NULL OR at.expires_at > NOW())",
            [$token]
        );

        if (!empty($userQuery) && $userQuery->numRows() > 0) {
            $uinfo = $userQuery->fetchArray();
            self::$sessionId = $uinfo['session_id'];
            self::$token = $token;
            self::$userId = $uinfo['id'];
            self::$name = $uinfo['name'];
            self::$email = $uinfo['email'];
            self::$username = $uinfo['username'];
            self::$authType = 'token';

            return [
                'user_id' => $uinfo['id'],
                'name' => $uinfo['name'],
                'email' => $uinfo['email'],
                'username' => $uinfo['username'],
                'session_id' => $uinfo['session_id'],
                'token' => $token,
            ];
        }

        return null;
    }

    private static function getTokenFromAuthorizationHeader()
    {
        $authToken = explode(' ', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
        return $authToken[1] ?? null;
    }

    private static function authorizeWithToken($token)
    {
        $db = DBManager::getDB();

        // Query auth_tokens table as per the current schema
        $userQuery = $db->query(
            "SELECT u.id, u.name, u.email, u.username, at.id as session_id
             FROM auth_tokens at
             JOIN users u ON u.id = at.user_id
             WHERE at.token = ? 
             AND at.revoked_at IS NULL 
             AND (at.expires_at IS NULL OR at.expires_at > NOW())",
            [$token]
        );

        if (!empty($userQuery) && $userQuery->numRows() > 0) {
            $uinfo = $userQuery->fetchArray();
            self::$sessionId = $uinfo['session_id'];
            self::$token = $token;
            self::$userId = $uinfo['id'];
            self::$name = $uinfo['name'];
            self::$email = $uinfo['email'];
            self::$username = $uinfo['username'];
            self::$authType = 'token';
            return true;
        }

        return false;
    }

    /**
     * Get current user ID
     */
    public static function getCurrentUserId(): ?int
    {
        $user = self::currentUser();
        return $user ? (int)$user['user_id'] : null;
    }

    /**
     * Check if user is authenticated
     */
    public static function isAuthenticated(): bool
    {
        return self::currentUser() !== null;
    }

    /**
     * Check if current user owns a resource
     */
    public static function ownsResource(int $userId): bool
    {
        $currentUserId = self::getCurrentUserId();
        return $currentUserId && $currentUserId === $userId;
    }

    /**
     * Check if current user participates in a conversation
     */
    public static function participatesInConversation(int $conversationId): bool
    {
        $currentUserId = self::getCurrentUserId();
        if (!$currentUserId) {
            return false;
        }

        $db = DBManager::getDB();
        $result = $db->query(
            "SELECT COUNT(*) as count FROM conversation_participants 
             WHERE conversation_id = ? AND user_id = ?",
            [$conversationId, $currentUserId]
        );

        $row = $result->fetchArray();
        return $row && $row['count'] > 0;
    }
}
