<?php

namespace Framework\Core;

use Framework\Core\DBManager;
use App\Api\Models\UserModel;

class Auth
{
    public static $userId;
    public static $name;
    public static $email;
    public static $username;
    public static $sessionId;
    public static $token;
    public static $authType;
    private static $currentUser;

    /**
     * Simple permission check for chat system.
     * Currently just verifies that a valid auth token is present.
     */
    public static function checkPermission($section, $controller, $action)
    {
        // Ensure user is authenticated globally
        self::requireAuth();

        // Additional resource-level checks can be added here in the future
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
     * Returns an associative array with keys: user_id, name, email, username,
     * avatar_url, session_id and token or null if not authenticated.
     */
    public static function currentUser(): ?array
    {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }

        $token = null;
        // Try common Authorization headers (case-insensitive)
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach (['Authorization', 'authorization', 'AUTHORIZATION'] as $h) {
            if (!empty($headers[$h]) && preg_match('/Bearer\s+(.*)$/i', $headers[$h], $m)) {
                $token = $m[1];
                break;
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

        $uinfo = UserModel::validateToken($token);
        if ($uinfo) {
            self::$sessionId = $uinfo['session_id'];
            self::$token = $uinfo['token'];
            self::$userId = $uinfo['user_id'];
            self::$name = $uinfo['name'];
            self::$email = $uinfo['email'];
            self::$username = $uinfo['username'];
            self::$authType = 'token';
            self::$currentUser = $uinfo;
            return $uinfo;
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
        $uinfo = UserModel::validateToken($token);
        if ($uinfo) {
            self::$sessionId = $uinfo['session_id'];
            self::$token = $uinfo['token'];
            self::$userId = $uinfo['user_id'];
            self::$name = $uinfo['name'];
            self::$email = $uinfo['email'];
            self::$username = $uinfo['username'];
            self::$authType = 'token';
            self::$currentUser = $uinfo;
            return true;
        }

        return false;
    }

    /**
     * Require authentication and return the current user.
     * Sends a 401 response and exits if not authenticated.
     */
    public static function requireAuth(): array
    {
        $user = self::currentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 401, 'msg' => 'Authentication required']);
            exit();
        }
        return $user;
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

