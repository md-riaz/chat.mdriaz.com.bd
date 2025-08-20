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

        // Set JSON response headers
        header('Content-Type: application/json');

        // Handle CORS
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
            http_response_code(200);
            exit;
        }
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
        $user = $required ? CoreAuth::requireAuth() : CoreAuth::currentUser();
        $this->currentUser = $user;
        return $user;
    }

    /**
     * Get JSON input data
     */
    protected function getJsonInput()
    {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->respondError(400, 'Invalid JSON body');
        }

        return $data ?? [];
    }

    /**
     * Validate required fields in input data
     */
    protected function validateRequired($data, $requiredFields)
    {
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $this->respondError(400, "Field '{$field}' is required");
            }
        }
    }

    /**
     * Validate email format
     */
    protected function validateEmail($email)
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->respondError(400, 'Invalid email format');
        }
    }

    /**
     * Validate array input
     */
    protected function validateArray($data, $field, $minLength = 1)
    {
        if (!isset($data[$field]) || !is_array($data[$field]) || count($data[$field]) < $minLength) {
            $this->respondError(400, "Field '{$field}' must be an array with at least {$minLength} items");
        }
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
     * Sanitize and validate email address
     */
    protected function sanitizeEmail($email)
    {
        $email = filter_var(trim(strtolower($email)), FILTER_SANITIZE_EMAIL);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->respondError(400, 'Invalid email format');
        }

        return $email;
    }

    /**
     * Sanitize and format phone number to E.164 using libphonenumber
     */
    protected function sanitizePhone($phone, $region = 'BD')
    {
        $phone = preg_replace('/\s+/', '', $phone);

        $util = \libphonenumber\PhoneNumberUtil::getInstance();

        try {
            $numberProto = $util->parse($phone, $region);
            if (!$util->isValidNumber($numberProto)) {
                $this->respondError(400, 'Invalid phone number format');
            }
            return $util->format($numberProto, \libphonenumber\PhoneNumberFormat::E164);
        } catch (\libphonenumber\NumberParseException $e) {
            $this->respondError(400, 'Invalid phone number format');
        }
    }

    /**
     * Send success response
     */
    protected function respondSuccess($data = null, $message = 'Success', $statusCode = 200)
    {
        http_response_code($statusCode);

        $response = [
            'success' => true,
            'message' => $message
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        echo json_encode($response);
        exit;
    }

    /**
     * Send error response
     */
    protected function respondError($statusCode, $message, $errors = null)
    {
        http_response_code($statusCode);

        $response = [
            'success' => false,
            'message' => $message
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        echo json_encode($response);
        exit;
    }

    /**
     * Send paginated response
     */
    protected function respondPaginated($data, $total, $page, $perPage, $message = 'Success')
    {
        $totalPages = ceil($total / $perPage);

        $this->respondSuccess([
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

    /**
     * Send cursor-based paginated response
     */
    protected function respondCursor($data, $limit, $message = 'Success')
    {
        $hasMore = count($data) === $limit;
        $next = null;

        if ($hasMore && !empty($data)) {
            $last = end($data);
            $next = [
                'last_id' => $last['id'] ?? null,
                'last_timestamp' => $last['created_at'] ?? ($last['last_message_time'] ?? null)
            ];
        }

        $this->respondSuccess([
            'items' => $data,
            'pagination' => [
                'has_more' => $hasMore,
                'next' => $next
            ]
        ], $message);
    }
}
