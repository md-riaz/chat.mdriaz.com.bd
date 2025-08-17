<?php

namespace App\Api;

use App\Api\ApiController;
use App\Api\Models\UserModel;
use App\Api\Models\DeviceModel;
use App\Api\Models\AuthTokenModel;

class User extends ApiController
{
    /**
     * GET /api/user - List all users with pagination and search
     */
    public function Index()
    {
        $search = $_GET['search'] ?? '';

        $this->authenticate();

        // Build search conditions
        $whereClause = "WHERE deleted_at IS NULL";
        $params = [];

        if (!empty($search)) {
            $whereClause .= " AND (name LIKE ? OR email LIKE ? OR username LIKE ?)";
            $searchTerm = "%{$search}%";
            $params = [$searchTerm, $searchTerm, $searchTerm];
        }

        // Use dataQuery for automatic pagination
        $query = "SELECT id, name, email, username, avatar_url, created_at, updated_at 
                  FROM users {$whereClause} 
                  ORDER BY created_at DESC";

        $result = UserModel::GetUsers($query, $params);

        $this->respondPaginated(
            $result['items'],
            $result['item_count'],
            $result['page_number'],
            $result['item_limit'],
            'Users retrieved successfully'
        );
    }

    /**
     * GET /api/user/{id} - Get a specific user
     */
    public function Show($id = null)
    {
        if (!$id) {
            $this->respondError(400, 'User ID is required');
        }

        $this->authenticate();

        $user = UserModel::findById($id);

        if (!$user) {
            $this->respondError(404, 'User not found');
        }

        $this->respondSuccess($user, 'User retrieved successfully');
    }

    /**
     * PUT /api/user/{id} - Update a user
     */
    public function Update($id = null)
    {
        if (!$id) {
            $this->respondError(400, 'User ID is required');
        }

        $currentUser = $this->authenticate();
        $data = $this->getJsonInput();

        // Check if user can update this profile (own profile or admin)
        if ($currentUser['user_id'] != $id) {
            // TODO: Add admin role check here
            $this->respondError(403, 'You can only update your own profile');
        }

        // Validate input
        $allowedFields = ['name', 'username', 'avatar_url'];
        $updateData = [];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($field === 'username' && !empty($data[$field])) {
                    if (UserModel::usernameExists($data[$field], $id)) {
                        $this->respondError(400, 'Username already taken');
                    }

                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $data[$field])) {
                        $this->respondError(400, 'Username can only contain letters, numbers, and underscores');
                    }
                }

                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            $this->respondError(400, 'No valid fields to update');
        }

        try {
            UserModel::updateById($id, $updateData);

            // Get updated user
            $user = UserModel::findById($id);

            $this->respondSuccess($user, 'User updated successfully');
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to update user');
        }
    }

    /**
     * DELETE /api/user/{id} - Soft delete a user
     */
    public function Delete($id = null)
    {
        if (!$id) {
            $this->respondError(400, 'User ID is required');
        }

        $currentUser = $this->authenticate();

        // Check if user can delete this profile (own profile or admin)
        if ($currentUser['user_id'] != $id) {
            // TODO: Add admin role check here
            $this->respondError(403, 'You can only delete your own profile');
        }

        try {
            $this->db->beginTransaction();

            // Soft delete user
            UserModel::softDelete($id);

            // Revoke all auth tokens
            $this->db->query(
                "UPDATE auth_tokens SET revoked_at = NOW() WHERE user_id = ?",
                [$id]
            );

            $this->db->commit();

            $this->respondSuccess(null, 'User deleted successfully');
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->respondError(500, 'Failed to delete user');
        }
    }

    /**
     * POST /api/user/{id}/UploadAvatar - Upload user avatar
     */
    public function UploadAvatar($id = null)
    {
        if (!$id) {
            $this->respondError(400, 'User ID is required');
        }

        $currentUser = $this->authenticate();

        // Check if user can update this profile
        if ($currentUser['user_id'] != $id) {
            $this->respondError(403, 'You can only update your own avatar');
        }

        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            $this->respondError(400, 'No valid file uploaded');
        }

        $file = $_FILES['avatar'];

        // Detect and validate MIME type
        $allowedTypes = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png'  => ['png'],
            'image/gif'  => ['gif'],
            'image/webp' => ['webp']
        ];

        $mimeType = mime_content_type($file['tmp_name']);
        if (!isset($allowedTypes[$mimeType])) {
            $this->respondError(400, 'Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed');
        }

        // Validate file extension and determine safe extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedTypes[$mimeType], true)) {
            $this->respondError(400, 'File extension does not match MIME type');
        }
        $safeExtension = $allowedTypes[$mimeType][0];

        // Validate file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            $this->respondError(400, 'File too large. Maximum size is 5MB');
        }

        try {
            // Create upload directory if not exists
            $uploadDir = ROOT . 'uploads/avatars/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename using safe extension
            $filename = 'avatar_' . $id . '_' . time() . '.' . $safeExtension;
            $filePath = $uploadDir . $filename;

            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                $avatarUrl = '/uploads/avatars/' . $filename;

                // Update user avatar URL
                UserModel::updateUser($id, ['avatar_url' => $avatarUrl]);

                $this->respondSuccess([
                    'avatar_url' => $avatarUrl
                ], 'Avatar uploaded successfully');
            } else {
                $this->respondError(500, 'Failed to save uploaded file');
            }
        } catch (\Exception $e) {
            $this->respondError(500, 'Failed to upload avatar');
        }
    }

    /**
     * GET /api/user/Search - Search users (for adding to conversations)
     */
    public function Search()
    {
        $query = $_GET['q'] ?? '';
        $limit = min((int)($_GET['limit'] ?? 10), 50);

        if (empty($query)) {
            $this->respondError(400, 'Search query is required');
        }

        $this->authenticate();

        $users = UserModel::searchUsers($query, $limit);

        $this->respondSuccess($users, 'Users found successfully');
    }

    /**
     * GET /api/user/Me - Get current authenticated user info
     */
    public function Me()
    {
        $user = $this->authenticate();

        $userInfo = UserModel::findById($user['user_id']);

        if (!$userInfo) {
            $this->respondError(404, 'User not found');
        }

        $this->respondSuccess($userInfo, 'User info retrieved successfully');
    }

    /**
     * POST /api/user/Login - User login (merged from Auth controller)
     */
    public function Login()
    {
        $data = $this->getJsonInput();
        $this->validateRequired($data, ['email', 'password']);

        // Try to find user by email or username
        $user = UserModel::getUserByEmail($data['email']);
        if (!$user) {
            $user = UserModel::getUserByUsername($data['email']);
        }

        if (!$user || !password_verify($data['password'], $user['password'])) {
            $this->respondError(401, 'Invalid credentials');
        }

        // Register/update device if provided
        if (!empty($data['device_id'])) {
            DeviceModel::registerDevice(
                $user['id'],
                $data['device_id'],
                $data['platform'] ?? 'web',
                $data['fcm_token'] ?? null,
                $data['device_name'] ?? null,
                $data['app_version'] ?? null,
                $data['os_version'] ?? null
            );
        }

        // Create auth token
        $token = AuthTokenModel::createToken(
            $user['id'],
            $data['client_ip'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
            $data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? '',
            $data['device_id'] ?? null,
            date('Y-m-d H:i:s', strtotime('+30 days')) // 30 days expiry
        );

        // Remove password from response
        unset($user['password']);

        $this->respondSuccess([
            'token' => $token,
            'user' => $user
        ], 'Login successful');
    }

    /**
     * POST /api/user/Register - User registration (merged from Auth controller)
     */
    public function Register()
    {
        $data = $this->getJsonInput();
        $this->validateRequired($data, ['name', 'email', 'username', 'password']);

        // Validation
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $this->respondError(400, 'Invalid email format');
        }

        if (strlen($data['password']) < 8) {
            $this->respondError(400, 'Password must be at least 8 characters');
        }

        if (strlen($data['username']) < 3 || !preg_match('/^[a-zA-Z0-9_]+$/', $data['username'])) {
            $this->respondError(400, 'Username must be at least 3 characters and contain only letters, numbers, and underscores');
        }

        try {
            $this->db->beginTransaction();

            // Check for existing email
            $emailExists = UserModel::getUserByEmail($data['email']);
            if ($emailExists) {
                $this->respondError(400, 'Email already exists');
            }

            // Check for existing username
            $usernameExists = UserModel::getUserByUsername($data['username']);
            if ($usernameExists) {
                $this->respondError(400, 'Username already exists');
            }

            // Create user
            $userId = UserModel::createUser(
                $data['name'],
                $data['email'],
                $data['username'],
                $data['password'],
                $data['avatar_url'] ?? null
            );

            $this->db->commit();

            $this->respondSuccess([
                'user_id' => $userId,
                'message' => 'Registration successful'
            ], 'User registered successfully', 201);
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->respondError(500, 'Registration failed');
        }
    }

    /**
     * POST /api/user/Logout - User logout (merged from Auth controller)
     */
    public function Logout()
    {
        $user = $this->authenticate();
        $token = $this->getAuthToken();

        if ($token) {
            AuthTokenModel::revokeToken($token);
        }

        $this->respondSuccess(null, 'Logged out successfully');
    }

    /**
     * POST /api/user/Refresh - Refresh token (merged from Auth controller)
     */
    public function Refresh()
    {
        $user = $this->authenticate();

        // Create new token
        $newToken = AuthTokenModel::createToken(
            $user['user_id'],
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            null,
            date('Y-m-d H:i:s', strtotime('+30 days'))
        );

        // Optionally revoke old token
        $oldToken = $this->getAuthToken();
        if ($oldToken) {
            AuthTokenModel::revokeToken($oldToken);
        }

        $this->respondSuccess([
            'token' => $newToken
        ], 'Token refreshed successfully');
    }
}
