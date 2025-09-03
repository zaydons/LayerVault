<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set memory limit for processing large STL files
ini_set('memory_limit', $_ENV['PHP_MEMORY_LIMIT'] ?? '256M');
ini_set('max_execution_time', $_ENV['PHP_MAX_EXECUTION_TIME'] ?? '300');

require_once 'STLParser.php';
require_once 'Database.php';

class LayerVault {
    private $db;
    private $uploadPath;
    private $maxFileSize;
    
    public function __construct() {
        try {
            $this->db = new Database();
        } catch (Exception $e) {
            error_log("LayerVault ERROR: Database connection failed: " . $e->getMessage());
            throw $e;
        }
        
        $this->uploadPath = $_ENV['LAYERVAULT_UPLOAD_PATH'] ?? __DIR__ . '/uploads';
        $this->maxFileSize = $this->parseSize($_ENV['PHP_UPLOAD_MAX_FILESIZE'] ?? '100M');
        
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
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $path = rtrim($path, '/');
        
        // Remove any duplicate slashes
        $path = preg_replace('#/+#', '/', $path);
        
        
        // Route requests
        switch ($path) {
            case '':
            case '/':
                $this->showLibrary();
                break;
                
            case '/upload':
                if ($method === 'POST') {
                    $this->handleUpload();
                } else {
                    $this->jsonResponse(['error' => 'Method not allowed'], 405);
                }
                break;
                
            case '/api/search':
                $this->handleSearch();
                break;
                
            case '/api/stats':
                $this->handleStats();
                break;
                
            default:
                if (preg_match('#^/view/(\d+)$#', $path, $matches)) {
                    $this->viewFile((int)$matches[1]);
                } elseif (preg_match('#^/serve/(\d+)$#', $path, $matches)) {
                    $this->serveFile((int)$matches[1]);
                } elseif (preg_match('#^/delete/(\d+)$#', $path, $matches)) {
                    if ($method === 'POST' || $method === 'DELETE') {
                        $this->deleteFile((int)$matches[1]);
                    } else {
                        $this->jsonResponse(['error' => 'Method not allowed'], 405);
                    }
                } else {
                    $this->show404();
                }
                break;
        }
    }
    
    private function showLibrary() {
        $search = $_GET['search'] ?? '';
        $orderBy = $_GET['order_by'] ?? 'uploaded_at';
        $order = $_GET['order'] ?? 'DESC';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        try {
            $files = $this->db->getFiles($search, $orderBy, $order, $limit, $offset);
            $stats = $this->db->getStats();
        } catch (Exception $e) {
            error_log("LayerVault ERROR: Failed to load library data: " . $e->getMessage());
            $files = [];
            $stats = ['total_files' => 0, 'total_size' => 0];
        }
        
        // Format file data for display
        foreach ($files as &$file) {
            $file['formatted_size'] = STLParser::formatFileSize($file['filesize']);
            $file['formatted_date'] = date('M j, Y g:i A', strtotime($file['uploaded_at']));
            $file['dimensions_str'] = sprintf('%.1f × %.1f × %.1f mm', 
                $file['width'] ?? 0, $file['height'] ?? 0, $file['depth'] ?? 0);
        }
        
        $this->renderTemplate('library', [
            'files' => $files,
            'stats' => $stats,
            'search' => $search,
            'orderBy' => $orderBy,
            'order' => $order,
            'page' => $page,
            'hasMore' => count($files) === $limit
        ]);
    }
    
    private function handleUpload() {
        header('Content-Type: application/json');
        
        if (!isset($_FILES['stl_file'])) {
            error_log("LayerVault ERROR: No file uploaded in request");
            $this->jsonResponse(['error' => 'No file uploaded'], 400);
            return;
        }
        
        $file = $_FILES['stl_file'];
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            error_log("LayerVault ERROR: File upload error code {$file['error']}: " . $this->getUploadError($file['error']));
            $this->jsonResponse(['error' => 'Upload failed: ' . $this->getUploadError($file['error'])], 400);
            return;
        }
        
        // Validate file size
        if ($file['size'] > $this->maxFileSize) {
            error_log("LayerVault ERROR: File too large - Size: {$file['size']}, Max: {$this->maxFileSize}");
            $this->jsonResponse(['error' => 'File too large. Maximum size: ' . STLParser::formatFileSize($this->maxFileSize)], 400);
            return;
        }
        
        // Validate file extension
        $originalName = $file['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension !== 'stl') {
            error_log("LayerVault ERROR: Invalid file extension '{$extension}' for file: {$originalName}");
            $this->jsonResponse(['error' => 'Only STL files are allowed'], 400);
            return;
        }
        
        // Validate STL file format
        $validation = STLParser::validate($file['tmp_name']);
        if (!$validation['valid']) {
            error_log("LayerVault ERROR: STL validation failed for {$originalName}: " . $validation['error']);
            $this->jsonResponse(['error' => $validation['error']], 400);
            return;
        }
        
        // Generate unique filename
        $filename = $this->generateUniqueFilename($originalName);
        $filepath = $this->uploadPath . '/' . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            error_log("LayerVault ERROR: Failed to move uploaded file from {$file['tmp_name']} to {$filepath}");
            $this->jsonResponse(['error' => 'Failed to save file'], 500);
            return;
        }
        
        // Parse STL file
        $metadata = $validation['metadata'];
        $bounds = $metadata['bounds'];
        
        // Prepare data for database
        $data = [
            'filename' => $filename,
            'original_filename' => $originalName,
            'filepath' => $filepath,
            'filesize' => $file['size'],
            'triangles' => $metadata['triangles'],
            'vertices' => $metadata['vertices'] ?? 0,
            'format' => $metadata['format'],
            'bounds_min_x' => $bounds['min']['x'],
            'bounds_min_y' => $bounds['min']['y'],
            'bounds_min_z' => $bounds['min']['z'],
            'bounds_max_x' => $bounds['max']['x'],
            'bounds_max_y' => $bounds['max']['y'],
            'bounds_max_z' => $bounds['max']['z'],
            'width' => $bounds['dimensions']['width'],
            'height' => $bounds['dimensions']['height'],
            'depth' => $bounds['dimensions']['depth'],
            'volume' => $metadata['volume'] ?? 0,
            'surface_area' => $metadata['surface_area'] ?? 0
        ];
        
        try {
            $fileId = $this->db->insertFile($data);
            $this->jsonResponse([
                'success' => true,
                'file_id' => $fileId,
                'message' => 'File uploaded successfully',
                'metadata' => $metadata
            ]);
        } catch (Exception $e) {
            error_log("LayerVault ERROR: Database insertion failed for {$filename}: " . $e->getMessage());
            // Clean up file on database error
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            $this->jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
        }
    }
    
    private function handleSearch() {
        $query = $_GET['q'] ?? '';
        if (strlen($query) < 2) {
            $this->jsonResponse(['results' => []]);
            return;
        }
        
        try {
            $results = $this->db->searchFiles($query);
        } catch (Exception $e) {
            error_log("LayerVault ERROR: Search failed for query '{$query}': " . $e->getMessage());
            $this->jsonResponse(['results' => []], 500);
            return;
        }
        
        foreach ($results as &$file) {
            $file['formatted_size'] = STLParser::formatFileSize($file['filesize']);
            $file['formatted_date'] = date('M j, Y', strtotime($file['uploaded_at']));
        }
        
        $this->jsonResponse(['results' => $results]);
    }
    
    private function handleStats() {
        try {
            $stats = $this->db->getStats();
            $stats['formatted_total_size'] = STLParser::formatFileSize($stats['total_size'] ?? 0);
            $stats['formatted_avg_size'] = STLParser::formatFileSize($stats['avg_file_size'] ?? 0);
            
            $this->jsonResponse($stats);
        } catch (Exception $e) {
            error_log("LayerVault ERROR: Failed to get stats: " . $e->getMessage());
            $this->jsonResponse(['error' => 'Failed to get statistics'], 500);
        }
    }
    
    private function viewFile($id) {
        try {
            $file = $this->db->getFile($id);
        } catch (Exception $e) {
            error_log("LayerVault ERROR: Failed to get file {$id}: " . $e->getMessage());
            $this->show404();
            return;
        }
        
        if (!$file) {
            error_log("LayerVault WARNING: File {$id} not found");
            $this->show404();
            return;
        }
        
        $file['formatted_size'] = STLParser::formatFileSize($file['filesize']);
        $file['formatted_date'] = date('M j, Y g:i A', strtotime($file['uploaded_at']));
        $file['dimensions_str'] = sprintf('%.2f × %.2f × %.2f mm', 
            $file['width'], $file['height'], $file['depth']);
        
        $this->renderTemplate('viewer', ['file' => $file]);
    }
    
    private function serveFile($id) {
        try {
            $file = $this->db->getFile($id);
        } catch (Exception $e) {
            error_log("LayerVault ERROR: Failed to get file {$id} for serving: " . $e->getMessage());
            http_response_code(404);
            exit;
        }
        
        if (!$file) {
            error_log("LayerVault WARNING: File {$id} not found for serving");
            http_response_code(404);
            exit;
        }
        
        if (!file_exists($file['filepath'])) {
            error_log("LayerVault ERROR: File {$id} ({$file['filepath']}) missing from filesystem");
            http_response_code(404);
            exit;
        }
        
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file['original_filename'] . '"');
        header('Content-Length: ' . $file['filesize']);
        header('Cache-Control: public, max-age=3600');
        
        readfile($file['filepath']);
        exit;
    }
    
    private function deleteFile($id) {
        try {
            $file = $this->db->getFile($id);
        } catch (Exception $e) {
            error_log("LayerVault ERROR: Failed to get file {$id} for deletion: " . $e->getMessage());
            $this->jsonResponse(['error' => 'Database error'], 500);
            return;
        }
        
        if (!$file) {
            error_log("LayerVault WARNING: Attempted to delete non-existent file {$id}");
            $this->jsonResponse(['error' => 'File not found'], 404);
            return;
        }
        
        // Delete from database first
        try {
            $deleted = $this->db->deleteFile($id);
        } catch (Exception $e) {
            error_log("LayerVault ERROR: Database deletion failed for file {$id}: " . $e->getMessage());
            $this->jsonResponse(['error' => 'Failed to delete from database'], 500);
            return;
        }
        
        if ($deleted) {
            $deletedFiles = [];
            
            // Delete STL file from filesystem
            if (file_exists($file['filepath'])) {
                if (unlink($file['filepath'])) {
                    $deletedFiles[] = 'STL file';
                } else {
                    error_log("LayerVault WARNING: Failed to delete STL file: {$file['filepath']}");
                }
            }
            
            // Delete thumbnail if it exists
            $thumbnailFilename = pathinfo($file['original_filename'], PATHINFO_FILENAME) . '_thumb.png';
            $thumbnailPath = '/var/www/html/thumbnails/' . $thumbnailFilename;
            if (file_exists($thumbnailPath)) {
                if (unlink($thumbnailPath)) {
                    $deletedFiles[] = 'thumbnail';
                } else {
                    error_log("LayerVault WARNING: Failed to delete thumbnail: {$thumbnailPath}");
                }
            }
            
            $message = 'File deleted successfully';
            if (!empty($deletedFiles)) {
                $message .= ' (' . implode(', ', $deletedFiles) . ' removed)';
            }
            
            $this->jsonResponse(['success' => true, 'message' => $message]);
        } else {
            error_log("LayerVault ERROR: Database deletion returned false for file {$id}");
            $this->jsonResponse(['error' => 'Failed to delete file from database'], 500);
        }
    }
    
    private function generateUniqueFilename($originalName) {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $basename = pathinfo($originalName, PATHINFO_FILENAME);
        $basename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $basename);
        
        $counter = 0;
        do {
            $filename = $basename . ($counter ? "_$counter" : '') . '.' . $extension;
            $counter++;
        } while ($this->db->fileExists($filename) || file_exists($this->uploadPath . '/' . $filename));
        
        return $filename;
    }
    
    private function getUploadError($code) {
        switch ($code) {
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
    
    private function jsonResponse($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    
    private function show404() {
        http_response_code(404);
        $this->renderTemplate('404', []);
    }
    
    private function renderTemplate($template, $data = []) {
        $templatePath = "templates/$template.php";
        if (!file_exists($templatePath)) {
            error_log("LayerVault ERROR: Template not found: {$templatePath}");
            http_response_code(500);
            echo "Template error";
            return;
        }
        
        extract($data);
        include $templatePath;
    }
}

// Create templates directory and run application
if (!is_dir(__DIR__ . '/templates')) {
    mkdir(__DIR__ . '/templates', 0755, true);
}

$app = new LayerVault();
$app->handleRequest();
?>