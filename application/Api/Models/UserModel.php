<?php

namespace App\Api\Models;

use Framework\Core\Model;
use Framework\Core\Collection;
use App\Api\Models\AuthTokenModel;
use App\Api\Models\DeviceModel;
use App\Api\Models\ConversationModel;
use App\Api\Models\MessageModel;

class UserModel extends Model
{
    protected static string $table = 'users';
    protected array $fillable = ['name', 'email', 'username', 'password', 'avatar_url', 'created_at', 'updated_at', 'deleted_at'];

    public function tokens(): Collection
    {
        return $this->hasMany(AuthTokenModel::class, 'user_id');
    }

    public function devices(): Collection
    {
        return $this->hasMany(DeviceModel::class, 'user_id');
    }

    public function conversations(): Collection
    {
        return $this->belongsToMany(
            ConversationModel::class,
            'conversation_participants',
            'user_id',
            'conversation_id'
        );
    }

    public function messages(): Collection
    {
        return $this->hasMany(MessageModel::class, 'sender_id');
    }

    /**
     * Run a paginated user listing using Database::dataQuery
     * @param string $baseQuery SQL without OFFSET/LIMIT
     * @param array $params bound params for WHERE
     * @return array { items, item_count, page_number, item_limit }
     */
    public static function getUsers(string $baseQuery, array $params = []): array
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
        $user->deleted_at = date('Y-m-d H:i:s');
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
     * Validate authentication token and return user data
     */
    public static function validateToken(string $token): ?array
    {
        $tokenModel = AuthTokenModel::validateToken($token);
        if (!$tokenModel) {
            return null;
        }

        $user = $tokenModel->user;
        if (!$user) {
            return null;
        }

        return [
            'user_id'    => (int) $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'username'   => $user->username,
            'avatar_url' => $user->avatar_url ?? null,
            'session_id' => (int) $tokenModel->id,
            'token'      => $tokenModel->token,
        ];
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
     * Search users for adding to conversations.
     *
     * Uses {@see QueryBuilder::whereNull()} to omit soft-deleted users.
     */
    public static function searchUsers($query, $limit = 10)
    {
        $like = "%$query%";

        return array_map(
            fn($user) => $user->toArray(),
            static::query()
                ->select(['id', 'name', 'username', 'email', 'avatar_url'])
                ->whereNull('deleted_at')
                ->whereRaw('(name LIKE :q OR username LIKE :q OR email LIKE :q)', ['q' => $like])
                ->limit($limit)
                ->get()
        );
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
            ['deleted_at', 'IS', null],
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
     * Get user count of non-deleted users using the {@see QueryBuilder::whereNull()} helper.
     */
    public static function getUserCount()
    {
        return static::query()
            ->whereNull('deleted_at')
            ->count();
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
     * Get recent users for suggestions.
     *
     * Applies {@see QueryBuilder::whereNull()} to exclude soft-deleted users.
     */
    public static function getRecentUsers($limit = 10)
    {
        return array_map(
            fn($user) => $user->toArray(),
            static::query()
                ->select(['id', 'name', 'username', 'email', 'avatar_url', 'created_at'])
                ->whereNull('deleted_at')
                ->orderBy('created_at', 'DESC')
                ->limit($limit)
                ->get()
        );
    }
}

