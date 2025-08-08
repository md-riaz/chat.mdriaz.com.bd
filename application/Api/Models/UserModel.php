<?php

namespace App\Api\Models;

use Framework\Core\Database;
use Framework\Core\DBManager;
use Framework\Core\Auth;

class UserModel
{
    private static $db;

    public static function initDB()
    {
        if (!self::$db) {
            self::$db = new Database();
        }
        return self::$db;
    }

    /**
     * Run a paginated user listing using Database::dataQuery
     * @param string $baseQuery SQL without OFFSET/LIMIT
     * @param array $params bound params for WHERE
     * @return array { items, item_count, page_number, item_limit }
     */
    public static function GetUsers(string $baseQuery, array $params = []): array
    {
        $db = self::initDB();
        return $db->dataQuery($baseQuery, $params);
    }

    /**
     * Find user by ID
     */
    public static function findById($id)
    {
        $db = self::initDB();
        return $db->query("SELECT id, name, email, username, avatar_url, created_at, updated_at FROM users WHERE id = ?", [$id])->fetchArray();
    }

    /**
     * Update user by ID
     */
    public static function updateById($id, array $data)
    {
        $db = self::initDB();
        if (empty($data)) return false;

        $set = [];
        $vals = [];
        foreach ($data as $k => $v) {
            $set[] = "$k = ?";
            $vals[] = $v;
        }
        $vals[] = $id;

        return $db->query("UPDATE users SET " . implode(', ', $set) . ", updated_at = NOW() WHERE id = ?", $vals);
    }

    /**
     * Soft delete user (mark as inactive)
     */
    public static function softDelete($id)
    {
        $db = self::initDB();
        return $db->query("UPDATE users SET updated_at = NOW() WHERE id = ?", [$id]);
    }

    /**
     * Create a new user
     */
    public static function createUser($name, $email, $username, $password, $avatarUrl = null)
    {
        $db = self::initDB();

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $result = $db->query(
            "INSERT INTO users (name, email, username, password, avatar_url, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
            [$name, $email, $username, $hashedPassword, $avatarUrl]
        );

        return $db->lastInsertId();
    }

    /**
     * Get user by email
     */
    public static function getUserByEmail($email)
    {
        $db = self::initDB();
        return $db->query("SELECT * FROM users WHERE email = ?", [$email])->fetchArray();
    }

    /**
     * Get user by username
     */
    public static function getUserByUsername($username)
    {
        $db = self::initDB();
        return $db->query("SELECT * FROM users WHERE username = ?", [$username])->fetchArray();
    }

    /**
     * Get user by ID
     */
    public static function getUserById($id)
    {
        $db = self::initDB();
        return $db->query("SELECT * FROM users WHERE id = ?", [$id])->fetchArray();
    }

    /**
     * Update user data
     */
    public static function updateUser($id, $data)
    {
        $db = self::initDB();

        $setParts = [];
        $values = [];

        foreach ($data as $key => $value) {
            $setParts[] = "$key = ?";
            $values[] = $value;
        }

        $values[] = $id;

        return $db->query(
            "UPDATE users SET " . implode(', ', $setParts) . ", updated_at = NOW() WHERE id = ?",
            $values
        );
    }

    /**
     * Search users for adding to conversations
     */
    public static function searchUsers($query, $limit = 10)
    {
        $db = self::initDB();

        return $db->query(
            "SELECT id, name, username, email, avatar_url 
             FROM users 
             WHERE name LIKE ? OR username LIKE ? OR email LIKE ?
             LIMIT ?",
            ["%$query%", "%$query%", "%$query%", $limit]
        )->fetchAll();
    }

    /**
     * Check if email exists
     */
    public static function emailExists($email, $excludeUserId = null)
    {
        $db = self::initDB();

        if ($excludeUserId) {
            $result = $db->query("SELECT COUNT(*) as count FROM users WHERE email = ? AND id != ?", [$email, $excludeUserId]);
        } else {
            $result = $db->query("SELECT COUNT(*) as count FROM users WHERE email = ?", [$email]);
        }

        $row = $result->fetchArray();
        return $row['count'] > 0;
    }

    /**
     * Check if username exists
     */
    public static function usernameExists($username, $excludeUserId = null)
    {
        $db = self::initDB();

        if ($excludeUserId) {
            $result = $db->query("SELECT COUNT(*) as count FROM users WHERE username = ? AND id != ?", [$username, $excludeUserId]);
        } else {
            $result = $db->query("SELECT COUNT(*) as count FROM users WHERE username = ?", [$username]);
        }

        $row = $result->fetchArray();
        return $row['count'] > 0;
    }

    /**
     * Get user's basic info for conversations
     */
    public static function getUserBasicInfo($userId)
    {
        $db = self::initDB();
        return $db->query(
            "SELECT id, name, username, email, avatar_url FROM users WHERE id = ?",
            [$userId]
        )->fetchArray();
    }

    /**
     * Get multiple users by IDs
     */
    public static function getUsersByIds($userIds)
    {
        if (empty($userIds)) {
            return [];
        }

        $db = self::initDB();
        $placeholders = str_repeat('?,', count($userIds) - 1) . '?';

        return $db->query(
            "SELECT id, name, username, email, avatar_url, created_at 
             FROM users 
             WHERE id IN ($placeholders)",
            $userIds
        )->fetchAll();
    }

    /**
     * Update user's last activity
     */
    public static function updateLastActivity($userId)
    {
        $db = self::initDB();
        return $db->query(
            "UPDATE users SET updated_at = NOW() WHERE id = ?",
            [$userId]
        );
    }

    /**
     * Get user count
     */
    public static function getUserCount()
    {
        $db = self::initDB();
        $result = $db->query("SELECT COUNT(*) as count FROM users")->fetchArray();
        return $result['count'];
    }

    /**
     * Validate user data for registration
     */
    public static function validateUserData($data)
    {
        $errors = [];

        // Required fields
        $required = ['name', 'email', 'username', 'password'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[] = "Field '$field' is required";
            }
        }

        // Email validation
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format";
        }

        // Username validation
        if (!empty($data['username'])) {
            if (strlen($data['username']) < 3) {
                $errors[] = "Username must be at least 3 characters";
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $data['username'])) {
                $errors[] = "Username can only contain letters, numbers, and underscores";
            }
        }

        // Password validation
        if (!empty($data['password']) && strlen($data['password']) < 8) {
            $errors[] = "Password must be at least 8 characters";
        }

        // Check for existing email/username
        if (!empty($data['email']) && self::emailExists($data['email'])) {
            $errors[] = "Email already exists";
        }

        if (!empty($data['username']) && self::usernameExists($data['username'])) {
            $errors[] = "Username already exists";
        }

        return $errors;
    }

    /**
     * Get recent users for suggestions
     */
    public static function getRecentUsers($limit = 10)
    {
        $db = self::initDB();
        return $db->query(
            "SELECT id, name, username, email, avatar_url, created_at 
             FROM users 
             ORDER BY created_at DESC 
             LIMIT ?",
            [$limit]
        )->fetchAll();
    }
}
