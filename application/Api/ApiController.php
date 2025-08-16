<?php

namespace App\Api;

use Framework\Core\Controller;
use Framework\Core\DBManager;
use Framework\Core\Auth as CoreAuth;

abstract class ApiController extends Controller
{
    public $db;
    protected $currentUser;

    public function __construct()
    {
        $this->db = DBManager::getDB();
    }

    /**
     * Get authentication token from request headers
     */
    protected function getAuthToken()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Authenticate the current request
     */
    protected function authenticate($required = true)
    {
        $user = CoreAuth::currentUser();

        if (!$user) {
            if ($required) {
                return $this->respondError(401, 'Authentication token required or invalid');
            }
            return null;
        }

        $this->currentUser = $user;
        return $user;
    }

    /**
     * Get JSON input data
     */
    protected function getJsonInput()
    {
        $json = file_get_contents('php://input');
        return json_decode($json, true) ?? [];
    }

    /**
     * Validate required fields in input data
     */
    protected function validateRequired($data, $requiredFields)
    {
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                return $this->respondError(400, "Field '{$field}' is required");
            }
        }

        return null;
    }

    /**
     * Validate email format
     */
    protected function validateEmail($email)
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->respondError(400, 'Invalid email format');
        }

        return null;
    }

    /**
     * Validate array input
     */
    protected function validateArray($data, $field, $minLength = 1)
    {
        if (!isset($data[$field]) || !is_array($data[$field]) || count($data[$field]) < $minLength) {
            return $this->respondError(400, "Field '{$field}' must be an array with at least {$minLength} items");
        }

        return null;
    }

    /**
     * Sanitize string input
     */
    protected function sanitizeString($input, $maxLength = null)
    {
        $sanitized = trim(strip_tags($input));

        if ($maxLength && strlen($sanitized) > $maxLength) {
            $sanitized = substr($sanitized, 0, $maxLength);
        }

        return $sanitized;
    }

    /**
     * Send success response
     */
    protected function respondSuccess($data = null, $message = 'Success', $statusCode = 200)
    {
        $response = [
            'status_code' => $statusCode,
            'success' => true,
            'message' => $message
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return $response;
    }

    /**
     * Send error response
     */
    protected function respondError($statusCode, $message, $errors = null)
    {
        $response = [
            'status_code' => $statusCode,
            'success' => false,
            'message' => $message
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return $response;
    }

    /**
     * Send paginated response
     */
    protected function respondPaginated($data, $total, $page, $perPage, $message = 'Success')
    {
        $totalPages = ceil($total / $perPage);

        return $this->respondSuccess([
            'items' => $data,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ]
        ], $message);
    }
}
