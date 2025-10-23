<?php

namespace App\Helpers;

class SecurityHelper
{
    /**
     * Sanitize string input to prevent XSS attacks
     *
     * @param string|null $input
     * @return string|null
     */
    public static function sanitizeString(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        
        // Remove any HTML tags and encode special characters
        return htmlspecialchars(strip_tags($input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Sanitize HTML content while allowing safe tags
     *
     * @param string|null $input
     * @return string|null
     */
    public static function sanitizeHtml(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        
        // Allow only safe HTML tags
        $allowedTags = '<p><br><strong><em><u><a><ul><ol><li>';
        return strip_tags($input, $allowedTags);
    }
    
    /**
     * Sanitize phone number
     *
     * @param string|null $phone
     * @return string|null
     */
    public static function sanitizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        
        // Remove all non-numeric characters except + and @ (for WhatsApp JIDs)
        return preg_replace('/[^0-9+@]/', '', $phone);
    }
    
    public static function sanitizeJid(?string $jid): ?string
    {
        if ($jid === null) {
            return null;
        }
        
        $jid = trim($jid);
        if ($jid === '') {
            return $jid;
        }
        
        if (strpos($jid, '@') === false) {
            return self::sanitizePhone($jid);
        }
        
        $jid = strtolower($jid);
        
        if (preg_match('/^(\+?\d{5,})@s\.whatsapp\.net$/', $jid, $m)) {
            return $m[1] . '@s.whatsapp.net';
        }
        
        if (preg_match('/^(\d{5,})@g\.us$/', $jid, $m)) {
            return $m[1] . '@g.us';
        }
        
        return preg_replace('/[^0-9+@a-z\.]/', '', $jid);
    }
    
    /**
     * Sanitize filename to prevent directory traversal
     *
     * @param string|null $filename
     * @return string|null
     */
    public static function sanitizeFilename(?string $filename): ?string
    {
        if ($filename === null) {
            return null;
        }
        
        // Remove directory separators and null bytes
        $filename = str_replace(['/', '\\', "\0"], '', $filename);
        
        // Remove leading dots
        $filename = ltrim($filename, '.');
        
        // Limit length
        if (strlen($filename) > 255) {
            $filename = substr($filename, 0, 255);
        }
        
        return $filename;
    }
    
    /**
     * Validate and sanitize URL
     *
     * @param string|null $url
     * @return string|null
     */
    public static function sanitizeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        
        // Validate URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        
        // Only allow http and https protocols
        $parsed = parse_url($url);
        if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'])) {
            return null;
        }
        
        return $url;
    }
    
    /**
     * Generate a secure random token
     *
     * @param int $length
     * @return string
     */
    public static function generateSecureToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }
    
    /**
     * Validate file upload
     *
     * @param string $mimetype
     * @param int $size
     * @param int $maxSize Maximum size in bytes
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validateFileUpload(string $mimetype, int $size, int $maxSize = 52428800): array
    {
        // Define allowed mimetypes
        $allowedMimetypes = [
            // Images
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/svg+xml',
            // Documents
            'application/pdf', 'application/msword', 
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain', 'text/csv',
            // Archives
            'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed',
            // Video
            'video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/webm',
            // Audio
            'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/aac', 'audio/x-m4a',
        ];
        
        // Check mimetype
        if (!in_array($mimetype, $allowedMimetypes)) {
            return ['valid' => false, 'error' => 'File type not allowed'];
        }
        
        // Check size
        if ($size > $maxSize) {
            return ['valid' => false, 'error' => 'File size exceeds maximum allowed size'];
        }
        
        // Additional checks for potentially dangerous files
        if (str_contains($mimetype, 'script') || str_contains($mimetype, 'executable')) {
            return ['valid' => false, 'error' => 'Potentially dangerous file type'];
        }
        
        return ['valid' => true, 'error' => null];
    }
}
