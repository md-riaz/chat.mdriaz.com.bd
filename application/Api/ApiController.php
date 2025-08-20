<?php

namespace App\Api;

use Framework\Core\Controller;
use Framework\Core\DBManager;
use Framework\Core\Auth as CoreAuth;

abstract class ApiController extends Controller
{
    public $db;
    protected $currentUser;
    protected $errors = [];

    public function __construct()
    {
        $this->db = DBManager::getDB();
        $this->setup();
    }

    protected function setup()
    {
        header('Content-Type: application/json');
        $this->handleCORS();
    }

    protected function handleCORS()
    {
        $allowed_origins = ALLOWED_ORIGINS;
        if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowed_origins)) {
            header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
        } else {
            header("Access-Control-Allow-Origin: " . $allowed_origins[0]);
        }

        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /**
     * Get authentication token from request headers
     */
    protected function getAuthToken()
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

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
        if ($required && !$user) {
            $this->respondError(401, 'Unauthorized');
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
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->respondError(400, 'Invalid JSON body');
        }

        return $data ?? [];
    }

    /**
     * Validate input data against a set of rules
     */
    protected function validate($data, $rules)
    {
        foreach ($rules as $field => $rule) {
            $validations = explode('|', $rule);
            foreach ($validations as $validation) {
                $params = explode(':', $validation);
                $method = 'validate' . ucfirst($params[0]);
                if (method_exists($this, $method)) {
                    $this->$method($data, $field, $params[1] ?? null);
                }
            }
        }

        if (!empty($this->errors)) {
            $this->respondError(400, 'Validation failed', $this->errors);
        }
    }

    protected function validateRequired($data, $field)
    {
        if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
            $this->errors[$field][] = "Field '{$field}' is required";
        }
    }

    protected function validateEmail($data, $field)
    {
        if (isset($data[$field]) && !filter_var($data[$field], FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field][] = 'Invalid email format';
        }
    }

    protected function validateArray($data, $field, $minLength = 1)
    {
        if (!isset($data[$field]) || !is_array($data[$field]) || count($data[$field]) < $minLength) {
            $this->errors[$field][] = "Field '{$field}' must be an array with at least {$minLength} items";
        }
    }

    /**
     * Sanitize string input
     */
    protected function sanitizeString($input, $maxLength = null)
    {
        $sanitized = trim(strip_tags($input));

        if ($maxLength && mb_strlen($sanitized) > $maxLength) {
            $sanitized = mb_substr($sanitized, 0, $maxLength);
        }

        return $sanitized;
    }

    /**
     * Send a JSON response
     */
    protected function respond($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }

    /**
     * Send success response
     */
    protected function respondSuccess($data = null, $message = 'Success', $statusCode = 200)
    {
        $response = [
            'success' => true,
            'message' => $message
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        $this->respond($response, $statusCode);
    }

    /**
     * Send error response
     */
    protected function respondError($statusCode, $message, $errors = null)
    {
        $response = [
            'success' => false,
            'message' => $message
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        $this->respond($response, $statusCode);
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
}
