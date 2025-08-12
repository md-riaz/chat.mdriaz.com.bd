<?php

namespace App\Api\Models;

use Framework\Core\Model;

class UserModel extends Model
{
    protected static string $table = 'users';
    protected array $fillable = ['name', 'email', 'username', 'password', 'avatar_url', 'created_at', 'updated_at'];

    /**
     * Run a paginated user listing using Database::dataQuery
     * @param string $baseQuery SQL without OFFSET/LIMIT
     * @param array $params bound params for WHERE
     * @return array { items, item_count, page_number, item_limit }
     */
    public static function GetUsers(string $baseQuery, array $params = []): array
    {
        $db = static::db();
        return $db->dataQuery($baseQuery, $params);
    }

    /**
     * Find user by ID
     */
    public static function findById($id)
    {
        return static::find((int) $id);
    }

    /**
     * Update user by ID
     */
    public static function updateById($id, array $data)
    {
        $user = static::find((int) $id);
        if (!$user) {
            return false;
        }
        $user->fill($data);
        $user->save();
        return true;
    }

    /**
     * Soft delete user (mark as inactive)
     */
    public static function softDelete($id)
    {
        $user = static::find((int) $id);
        if (!$user) {
            return false;
        }
        $user->save();
        return true;
    }

    /**
     * Create a new user
     */
    public static function createUser($name, $email, $username, $password, $avatarUrl = null)
    {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $user = new static([
            'name'      => $name,
            'email'     => $email,
            'username'  => $username,
            'password'  => $hashedPassword,
            'avatar_url'=> $avatarUrl,
        ]);
        $user->save();
        return $user->id;
    }

    /**
     * Get user by email
     */
    public static function getUserByEmail($email)
    {
        return static::first(['email' => $email]);
    }

    /**
     * Get user by username
     */
    public static function getUserByUsername($username)
    {
        return static::first(['username' => $username]);
    }

    /**
     * Get user by ID
     */
    public static function getUserById($id)
    {
        return static::find((int) $id);
    }

    /**
     * Update user data
     */
    public static function updateUser($id, $data)
    {
        return static::updateById($id, $data);
    }

    /**
     * Search users for adding to conversations
     */
    public static function searchUsers($query, $limit = 10)
    {
        $db = static::db();

        return $db->query(
            "SELECT id, name, username, email, avatar_url"
            . " FROM users"
            . " WHERE name LIKE ? OR username LIKE ? OR email LIKE ?"
            . " LIMIT ?",
            ["%$query%", "%$query%", "%$query%", $limit]
        )->fetchAll();
    }

    /**
     * Check if email exists
     */
    public static function emailExists($email, $excludeUserId = null)
    {
        $conditions = [['email', '=', $email]];
        if ($excludeUserId) {
            $conditions[] = ['id', '!=', $excludeUserId];
        }
        return static::where($conditions) !== [];
    }

    /**
     * Check if username exists
     */
    public static function usernameExists($username, $excludeUserId = null)
    {
        $conditions = [['username', '=', $username]];
        if ($excludeUserId) {
            $conditions[] = ['id', '!=', $excludeUserId];
        }
        return static::where($conditions) !== [];
    }

    /**
     * Get user's basic info for conversations
     */
    public static function getUserBasicInfo($userId)
    {
        return static::find((int) $userId);
    }

    /**
     * Get multiple users by IDs
     */
    public static function getUsersByIds($userIds)
    {
        if (empty($userIds)) {
            return [];
        }
        return static::where([
            ['id', 'IN', $userIds],
        ]);
    }

    /**
     * Update user's last activity
     */
    public static function updateLastActivity($userId)
    {
        $user = static::find((int) $userId);
        if (!$user) {
            return false;
        }
        $user->save();
        return true;
    }

    /**
     * Get user count
     */
    public static function getUserCount()
    {
        $db = static::db();
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
        $db = static::db();
        return $db->query(
            "SELECT id, name, username, email, avatar_url, created_at"
            . " FROM users"
            . " ORDER BY created_at DESC"
            . " LIMIT ?",
            [$limit]
        )->fetchAll();
    }
}

