<?php
/**
 * OCR API Proxy Endpoint
 *
 * Secure server-side proxy for Google Cloud Vision API.
 * Keeps API credentials safe and provides additional validation.
 *
 * Standards Applied:
 * - PSR-12: Extended Coding Style
 * - OWASP Top 10 Security Practices
 * - RESTful API Design Principles
 * - JSON API Specification
 * - HTTP Status Codes (RFC 7231)
 * - Error Handling Best Practices
 * - Input Validation & Sanitization
 * - Rate Limiting (Ready for implementation)
 * - Logging & Monitoring
 * - Clean Code Principles
 *
 * @category  API
 * @package   OCR
 * @author    Net Storm
 * @copyright 2025 Net Storm
 * @license   Proprietary
 * @version   1.0.0
 * @link      https://cloud.google.com/vision/docs
 */

declare(strict_types=1);

// =============================================================================
// INITIALIZATION
// =============================================================================

// Load application configuration
require_once __DIR__ . '/../../config/app-config.php';
require_once __DIR__ . '/../../includes/utilities.php';

// Load API keys (gitignored for security)
$apiKeysFile = __DIR__ . '/../../config/api-keys.php';
if (file_exists($apiKeysFile)) {
    require_once $apiKeysFile;
}

// Set response headers
sendAjaxHeaders('application/json');
sendNoCacheHeaders();
sendSecurityHeaders();

// =============================================================================
// REQUEST VALIDATION
// =============================================================================

/**
 * Validate OCR is enabled
 */
if (!defined('OCR_ENABLED') || !OCR_ENABLED) {
    sendJsonResponse([
        'success' => false,
        'error' => 'OCR service not configured',
        'code' => 'OCR_NOT_CONFIGURED',
        'message' => 'Please add Google Vision API key to config/api-keys.php'
    ], 503);
}

/**
 * Validate API key exists
 */
if (!defined('GOOGLE_VISION_API_KEY') || empty(GOOGLE_VISION_API_KEY)) {
    logMessage('OCR: API key not found', 'ERROR');
    sendJsonResponse([
        'success' => false,
        'error' => 'OCR service misconfigured',
        'code' => 'API_KEY_MISSING',
        'message' => 'API key is not set'
    ], 500);
}

/**
 * Only accept POST requests
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse([
        'success' => false,
        'error' => 'Method not allowed',
        'code' => 'METHOD_NOT_ALLOWED',
        'allowed_methods' => ['POST']
    ], 405);
}

/**
 * Validate request is AJAX
 */
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    logMessage('OCR: Non-AJAX request rejected', 'WARNING');
    sendJsonResponse([
        'success' => false,
        'error' => 'Invalid request type',
        'code' => 'NOT_AJAX'
    ], 400);
}

// =============================================================================
// INPUT VALIDATION & SANITIZATION
// =============================================================================

/**
 * Get and validate image path
 */
$imagePath = $_POST['image'] ?? '';

// Security: Whitelist validation (prevent directory traversal)
$allowedImages = ['pic.jpg', 'live.jpg', 'test.jpg'];
if (!in_array($imagePath, $allowedImages, true)) {
    logMessage("OCR: Invalid image path attempted: $imagePath", 'WARNING');
    sendJsonResponse([
        'success' => false,
        'error' => 'Invalid image path',
        'code' => 'INVALID_IMAGE_PATH',
        'allowed_images' => $allowedImages
    ], 400);
}

/**
 * Build full path and validate existence
 */
$fullPath = __DIR__ . '/../../' . $imagePath;

// Security: Verify path is within application root (prevent traversal)
$realPath = realpath($fullPath);
$appRoot = realpath(__DIR__ . '/../..');

if ($realPath === false || strpos($realPath, $appRoot) !== 0) {
    logMessage("OCR: Path traversal attempt: $imagePath", 'ERROR');
    sendJsonResponse([
        'success' => false,
        'error' => 'Security violation',
        'code' => 'PATH_TRAVERSAL'
    ], 403);
}

/**
 * Check if file exists
 */
if (!file_exists($fullPath)) {
    logMessage("OCR: Image not found: $imagePath", 'WARNING');
    sendJsonResponse([
        'success' => false,
        'error' => 'Image not found',
        'code' => 'IMAGE_NOT_FOUND',
        'path' => $imagePath
    ], 404);
}

/**
 * Check if file is readable
 */
if (!is_readable($fullPath)) {
    logMessage("OCR: Image not readable: $imagePath", 'ERROR');
    sendJsonResponse([
        'success' => false,
        'error' => 'Image not readable',
        'code' => 'IMAGE_NOT_READABLE'
    ], 500);
}

/**
 * Validate file size
 */
$fileSize = filesize($fullPath);
if ($fileSize === false || $fileSize === 0) {
    logMessage("OCR: Image file size error: $imagePath", 'ERROR');
    sendJsonResponse([
        'success' => false,
        'error' => 'Image file is empty or corrupted',
        'code' => 'EMPTY_FILE'
    ], 400);
}

if ($fileSize > OCR_MAX_FILE_SIZE) {
    $maxSizeMB = round(OCR_MAX_FILE_SIZE / 1024 / 1024, 1);
    $fileSizeMB = round($fileSize / 1024 / 1024, 1);
    logMessage("OCR: Image too large: {$fileSizeMB}MB (max: {$maxSizeMB}MB)", 'WARNING');
    sendJsonResponse([
        'success' => false,
        'error' => "Image too large (max {$maxSizeMB}MB)",
        'code' => 'FILE_TOO_LARGE',
        'file_size_mb' => $fileSizeMB,
        'max_size_mb' => $maxSizeMB
    ], 413);
}

/**
 * Validate file type (MIME type check)
 */
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $fullPath);
finfo_close($finfo);

$allowedMimeTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
if (!in_array($mimeType, $allowedMimeTypes, true)) {
    logMessage("OCR: Invalid MIME type: $mimeType", 'WARNING');
    sendJsonResponse([
        'success' => false,
        'error' => 'Invalid image format',
        'code' => 'INVALID_MIME_TYPE',
        'detected_type' => $mimeType,
        'allowed_types' => $allowedMimeTypes
    ], 400);
}

// =============================================================================
// IMAGE PROCESSING
// =============================================================================

/**
 * Read and encode image
 */
$imageData = @file_get_contents($fullPath);
if ($imageData === false) {
    logMessage("OCR: Failed to read image: $imagePath", 'ERROR');
    sendJsonResponse([
        'success' => false,
        'error' => 'Failed to read image',
        'code' => 'READ_FAILED'
    ], 500);
}

/**
 * Base64 encode image
 */
$base64Image = base64_encode($imageData);
if ($base64Image === false) {
    logMessage("OCR: Failed to encode image: $imagePath", 'ERROR');
    sendJsonResponse([
        'success' => false,
        'error' => 'Failed to encode image',
        'code' => 'ENCODE_FAILED'
    ], 500);
}

// Free memory
unset($imageData);

// =============================================================================
// GOOGLE VISION API REQUEST
// =============================================================================

/**
 * Build API URL with key
 */
$apiUrl = 'https://vision.googleapis.com/v1/images:annotate?key=' . 
          urlencode(GOOGLE_VISION_API_KEY);

/**
 * Prepare request body
 */
$requestBody = [
    'requests' => [
        [
            'image' => [
                'content' => $base64Image
            ],
            'features' => [
                [
                    'type' => 'TEXT_DETECTION',
                    'maxResults' => 1
                ]
            ],
            'imageContext' => [
                'languageHints' => OCR_LANGUAGE_HINTS
            ]
        ]
    ]
];

$jsonPayload = json_encode($requestBody);
if ($jsonPayload === false) {
    logMessage('OCR: JSON encoding failed', 'ERROR');
    sendJsonResponse([
        'success' => false,
        'error' => 'Failed to prepare request',
        'code' => 'JSON_ENCODE_FAILED'
    ], 500);
}

// Free memory
unset($base64Image, $requestBody);

/**
 * Initialize cURL
 */
$ch = curl_init();
if ($ch === false) {
    logMessage('OCR: cURL initialization failed', 'ERROR');
    sendJsonResponse([
        'success' => false,
        'error' => 'Service temporarily unavailable',
        'code' => 'CURL_INIT_FAILED'
    ], 503);
}

/**
 * Configure cURL options
 */
curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $jsonPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($jsonPayload)
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_MAXREDIRS => 0,
    CURLOPT_USERAGENT => 'CameraControl-OCR/2.0'
]);

/**
 * Execute request
 */
$startTime = microtime(true);
$response = curl_exec($ch);
$executionTime = round((microtime(true) - $startTime) * 1000, 2);

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);
curl_close($ch);

// Free memory
unset($jsonPayload);

/**
 * Handle cURL errors
 */
if ($response === false || $curlErrno !== 0) {
    logMessage("OCR: cURL error [$curlErrno]: $curlError", 'ERROR');
    sendJsonResponse([
        'success' => false,
        'error' => 'Failed to connect to OCR service',
        'code' => 'CURL_ERROR',
        'curl_error' => $curlError,
        'curl_errno' => $curlErrno
    ], 502);
}

/**
 * Parse JSON response
 */
$result = json_decode($response, true);
if ($result === null && json_last_error() !== JSON_ERROR_NONE) {
    logMessage('OCR: Invalid JSON response from API', 'ERROR');
    sendJsonResponse([
        'success' => false,
        'error' => 'Invalid API response',
        'code' => 'INVALID_JSON_RESPONSE',
        'json_error' => json_last_error_msg()
    ], 502);
}

// =============================================================================
// RESPONSE HANDLING
// =============================================================================

/**
 * Handle API errors
 */
if ($httpCode !== 200) {
    $errorMessage = 'Unknown API error';
    $errorCode = 'API_ERROR';
    
    if (isset($result['error']['message'])) {
        $errorMessage = $result['error']['message'];
    }
    
    if (isset($result['error']['status'])) {
        $errorCode = $result['error']['status'];
    }
    
    logMessage("OCR: API error [$httpCode] $errorCode: $errorMessage", 'ERROR');
    
    sendJsonResponse([
        'success' => false,
        'error' => $errorMessage,
        'code' => $errorCode,
        'http_code' => $httpCode
    ], $httpCode >= 400 && $httpCode < 500 ? $httpCode : 502);
}

/**
 * Extract text from response
 */
$extractedText = '';

// Try fullTextAnnotation first (preferred)
if (isset($result['responses'][0]['fullTextAnnotation']['text'])) {
    $extractedText = $result['responses'][0]['fullTextAnnotation']['text'];
}
// Fallback to textAnnotations
elseif (isset($result['responses'][0]['textAnnotations'][0]['description'])) {
    $extractedText = $result['responses'][0]['textAnnotations'][0]['description'];
}

/**
 * Validate and clean extracted text
 */
$extractedText = trim($extractedText);
$hasText = !empty($extractedText);
$charCount = mb_strlen($extractedText, 'UTF-8');

/**
 * Log successful extraction
 */
if ($hasText) {
    logMessage(
        "OCR: Success - extracted $charCount chars from $imagePath in {$executionTime}ms",
        'INFO'
    );
} else {
    logMessage(
        "OCR: No text found in $imagePath ({$executionTime}ms)",
        'INFO'
    );
}

/**
 * Return success response
 */
sendJsonResponse([
    'success' => true,
    'text' => $extractedText,
    'hasText' => $hasText,
    'charCount' => $charCount,
    'metadata' => [
        'image' => $imagePath,
        'file_size_kb' => round($fileSize / 1024, 2),
        'mime_type' => $mimeType,
        'execution_time_ms' => $executionTime,
        'timestamp' => date('Y-m-d H:i:s')
    ]
], 200);