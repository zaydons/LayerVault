<?php

class ThumbnailService {
    private $serviceUrl;
    
    public function __construct($serviceUrl = null) {
        $serviceUrl = $serviceUrl ?: ($_ENV['LAYERVAULT_THUMBNAIL_SERVICE'] ?? 'http://thumbnail-service:3000');
        $this->serviceUrl = rtrim($serviceUrl, '/');
    }
    
    /**
     * Generate a thumbnail for an STL file
     * @param string $stlFilePath Path to STL file relative to uploads directory
     * @param string $outputFilename Desired output filename (with .png extension)
     * @param int|null $rotation Camera rotation angle in degrees (optional)
     * @return array Result array with success status and details
     */
    public function generateThumbnail($stlFilePath, $outputFilename, $rotation = null) {
        $data = [
            'stlFilePath' => $stlFilePath,
            'outputFilename' => $outputFilename
        ];
        
        if ($rotation !== null) {
            $data['rotation'] = (int) $rotation;
        }
        
        $ch = curl_init();
        if ($ch === false) {
            error_log("LayerVault ERROR: Failed to initialize cURL for thumbnail generation");
            return [
                'success' => false,
                'error' => 'Failed to initialize cURL'
            ];
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->serviceUrl . '/generate-thumbnail',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        if ($curlError) {
            error_log("LayerVault ERROR: Thumbnail service cURL error for {$stlFilePath}: {$curlError}");
            curl_close($ch);
            return [
                'success' => false,
                'error' => 'cURL Error: ' . $curlError
            ];
        }
        
        if ($response === false) {
            error_log("LayerVault ERROR: Thumbnail service returned no response for {$stlFilePath}");
            curl_close($ch);
            return [
                'success' => false,
                'error' => 'No response from thumbnail service'
            ];
        }
        
        curl_close($ch);
        
        $result = json_decode($response, true);
        if ($result === null) {
            error_log("LayerVault ERROR: Invalid JSON response from thumbnail service for {$stlFilePath}: " . substr($response, 0, 200));
            return [
                'success' => false,
                'error' => 'Invalid JSON response from thumbnail service'
            ];
        }
        
        if ($httpCode !== 200) {
            error_log("LayerVault ERROR: Thumbnail service HTTP error {$httpCode} for {$stlFilePath}: " . ($result['error'] ?? 'Unknown error'));
            return [
                'success' => false,
                'error' => $result['error'] ?? 'HTTP Error: ' . $httpCode,
                'details' => $result['details'] ?? null
            ];
        }
        
        return $result;
    }
    
    /**
     * Generate thumbnail filename based on original filename
     * @param string $originalFilename The original STL filename
     * @return string Generated thumbnail filename
     */
    public function generateThumbnailFilename($originalFilename) {
        $pathInfo = pathinfo($originalFilename);
        $basename = $pathInfo['filename'];
        return $basename . '_thumb.png';
    }
    
    /**
     * Check if thumbnail service is healthy
     * @return bool True if service is responding
     */
    public function isHealthy() {
        $ch = curl_init();
        if ($ch === false) {
            error_log("LayerVault ERROR: Failed to initialize cURL for thumbnail service health check");
            return false;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->serviceUrl . '/health',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("LayerVault WARNING: Thumbnail service health check failed - cURL error: {$curlError}");
            return false;
        }
        
        $healthy = $httpCode === 200 && $response !== false;
        if (!$healthy) {
            error_log("LayerVault WARNING: Thumbnail service health check failed - HTTP {$httpCode}");
        }
        
        return $healthy;
    }
}