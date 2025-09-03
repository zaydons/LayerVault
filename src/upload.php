<?php
// Direct upload endpoint - bypasses routing issues
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set memory limit for processing large STL files
ini_set('memory_limit', $_ENV['PHP_MEMORY_LIMIT'] ?? '256M');
ini_set('max_execution_time', $_ENV['PHP_MAX_EXECUTION_TIME'] ?? '300');

require_once 'STLParser.php';
require_once 'Database.php';
require_once 'ThumbnailService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Create LayerVault instance and handle upload
class DirectUploadHandler {
    private $db;
    private $uploadPath;
    private $maxFileSize;
    private $thumbnailService;
    
    public function __construct() {
        try {
            $this->db = new Database();
        } catch (Exception $e) {
            error_log("LayerVault ERROR: Database connection failed in upload handler: " . $e->getMessage());
            throw $e;
        }
        
        $this->uploadPath = $_ENV['LAYERVAULT_UPLOAD_PATH'] ?? __DIR__ . '/uploads';
        $this->maxFileSize = $this->parseSize($_ENV['PHP_UPLOAD_MAX_FILESIZE'] ?? '100M');
        
        try {
            $this->thumbnailService = new ThumbnailService();
        } catch (Exception $e) {
            error_log("LayerVault WARNING: ThumbnailService initialization failed: " . $e->getMessage());
            $this->thumbnailService = null;
        }
        
        // Create upload directory if it doesn't exist
        if (!is_dir($this->uploadPath)) {
            if (!mkdir($this->uploadPath, 0777, true)) {
                error_log("LayerVault ERROR: Failed to create upload directory: " . $this->uploadPath);
                throw new Exception('Failed to create upload directory');
            }
        }
    }
    
    private function parseSize($size) {
        $unit = strtoupper(substr($size, -1));
        $value = (int)$size;
        
        switch($unit) {
            case 'G': return $value * 1024 * 1024 * 1024;
            case 'M': return $value * 1024 * 1024;
            case 'K': return $value * 1024;
            default: return $value;
        }
    }
    
    public function handleUpload() {
        header('Content-Type: application/json');
        
        if (!isset($_FILES['stl_file'])) {
            error_log("LayerVault ERROR: No file uploaded");
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded']);
            return;
        }
        
        $file = $_FILES['stl_file'];
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            error_log("LayerVault ERROR: Upload failed: " . $this->getUploadError($file['error']));
            http_response_code(400);
            echo json_encode(['error' => 'Upload failed: ' . $this->getUploadError($file['error'])]);
            return;
        }
        
        // Validate file size
        if ($file['size'] > $this->maxFileSize) {
            error_log("LayerVault ERROR: File too large - Size: {$file['size']}, Max: {$this->maxFileSize}");
            http_response_code(400);
            echo json_encode(['error' => 'File too large. Maximum size: ' . STLParser::formatFileSize($this->maxFileSize)]);
            return;
        }
        
        // Use original filename
        $originalFilename = $file['name'];
        $filename = $originalFilename;
        $filepath = $this->uploadPath . '/' . $filename;
        
        // Generate file hash for duplicate detection
        $fileHash = hash_file('sha256', $file['tmp_name']);
        if ($fileHash === false) {
            error_log("LayerVault ERROR: Failed to generate file hash for: " . $originalFilename);
            http_response_code(500);
            echo json_encode(['error' => 'Failed to process file']);
            return;
        }
        
        // Check for duplicate content (by hash)
        try {
            if ($this->db->fileExistsByHash($fileHash)) {
                error_log("LayerVault WARNING: Duplicate file detected by hash for: " . $originalFilename);
                http_response_code(409);
                echo json_encode(['error' => 'Duplicate file detected - this content has already been uploaded']);
                return;
            }
        } catch (Exception $e) {
            error_log("LayerVault ERROR: Database hash check failed: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database error during duplicate check']);
            return;
        }
        
        // Check if filename already exists
        if (file_exists($filepath)) {
            error_log("LayerVault ERROR: Duplicate filename: " . $filename);
            http_response_code(409);
            echo json_encode(['error' => 'File with this name already exists: ' . $filename]);
            return;
        }
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            error_log("LayerVault ERROR: Failed to move file to: " . $filepath);
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save file']);
            return;
        }
        
        // Parse STL file
        try {
            $metadata = STLParser::parse($filepath);
            if ($metadata === false) {
                throw new Exception('Failed to parse STL file - invalid format or corrupted data');
            }
        } catch (Exception $e) {
            error_log("LayerVault ERROR: STL parsing failed for {$originalFilename}: " . $e->getMessage());
            // Clean up file on parse error
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            http_response_code(400);
            echo json_encode(['error' => 'Invalid STL file: ' . $e->getMessage()]);
            return;
        }
        
        // Save to database
        try {
            // Prepare data array for database insertion
            $data = [
                ':filename' => $filename,
                ':original_filename' => $originalFilename,
                ':filepath' => $filepath,
                ':filesize' => $file['size'],
                ':file_hash' => $fileHash,
                ':triangles' => $metadata['triangles'] ?? null,
                ':vertices' => $metadata['vertices'] ?? null,
                ':format' => $metadata['format'] ?? null,
                ':bounds_min_x' => $metadata['bounds']['min']['x'] ?? null,
                ':bounds_min_y' => $metadata['bounds']['min']['y'] ?? null,
                ':bounds_min_z' => $metadata['bounds']['min']['z'] ?? null,
                ':bounds_max_x' => $metadata['bounds']['max']['x'] ?? null,
                ':bounds_max_y' => $metadata['bounds']['max']['y'] ?? null,
                ':bounds_max_z' => $metadata['bounds']['max']['z'] ?? null,
                ':width' => $metadata['bounds']['dimensions']['width'] ?? null,
                ':height' => $metadata['bounds']['dimensions']['height'] ?? null,
                ':depth' => $metadata['bounds']['dimensions']['depth'] ?? null,
                ':volume' => $metadata['volume'] ?? null,
                ':surface_area' => $metadata['surface_area'] ?? null
            ];
            
            $fileId = $this->db->insertFile($data);
            
            // Generate thumbnail in background (don't block upload response)
            if ($this->thumbnailService) {
                $this->generateThumbnailAsync($filename);
            } else {
                error_log("LayerVault WARNING: Skipping thumbnail generation - service unavailable");
            }
            
            echo json_encode([
                'success' => true,
                'file_id' => $fileId,
                'message' => 'File uploaded successfully',
                'metadata' => $metadata
            ]);
            
        } catch (Exception $e) {
            error_log("LayerVault ERROR: Database save failed for {$originalFilename}: " . $e->getMessage());
            // Clean up file on database error
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save file information: ' . $e->getMessage()]);
        }
    }
    
    private function generateUniqueFilename($originalFilename) {
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        
        do {
            $filename = uniqid() . '_' . time() . '.' . $extension;
        } while ($this->db->fileExists($filename) || file_exists($this->uploadPath . '/' . $filename));
        
        return $filename;
    }
    
    private function generateThumbnailAsync($filename) {
        try {
            // Generate thumbnail asynchronously to avoid blocking upload response
            $thumbnailFilename = $this->thumbnailService->generateThumbnailFilename($filename);
            
            // Check if thumbnail service is available
            if (!$this->thumbnailService->isHealthy()) {
                error_log("LayerVault WARNING: Thumbnail service not healthy for: " . $filename);
                return;
            }
            
            // Generate thumbnail with 45-degree angle view
            $result = $this->thumbnailService->generateThumbnail($filename, $thumbnailFilename, 45);
            
            if ($result['success']) {
                error_log("LayerVault INFO: Thumbnail generated successfully: " . $thumbnailFilename);
            } else {
                error_log("LayerVault WARNING: Thumbnail generation failed for " . $filename . ": " . ($result['error'] ?? 'Unknown error'));
            }
        } catch (Exception $e) {
            error_log("LayerVault ERROR: Thumbnail generation exception for {$filename}: " . $e->getMessage());
        }
    }
    
    private function getUploadError($errorCode) {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE: return 'File exceeds upload_max_filesize';
            case UPLOAD_ERR_FORM_SIZE: return 'File exceeds MAX_FILE_SIZE';
            case UPLOAD_ERR_PARTIAL: return 'File was only partially uploaded';
            case UPLOAD_ERR_NO_FILE: return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR: return 'Missing temporary folder';
            case UPLOAD_ERR_CANT_WRITE: return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION: return 'Upload stopped by extension';
            default: return 'Unknown upload error';
        }
    }
}

// Handle the upload
$handler = new DirectUploadHandler();
$handler->handleUpload();
?>