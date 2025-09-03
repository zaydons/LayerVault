<?php

class STLParser {
    
    /**
     * Parse STL file and extract metadata
     * 
     * @param string $filepath Path to the STL file
     * @return array|false Array containing metadata or false on error
     */
    public static function parse($filepath) {
        if (!file_exists($filepath)) {
            error_log("LayerVault ERROR: STL file not found: {$filepath}");
            return false;
        }
        
        $filesize = filesize($filepath);
        if ($filesize === false) {
            error_log("LayerVault ERROR: Cannot get file size for: {$filepath}");
            return false;
        }
        
        if ($filesize < 80) {
            error_log("LayerVault ERROR: STL file too small ({$filesize} bytes): {$filepath}");
            return false;
        }
        
        $handle = fopen($filepath, 'rb');
        if ($handle === false) {
            error_log("LayerVault ERROR: Cannot open STL file for reading: {$filepath}");
            return false;
        }
        
        // Read first 80 bytes to determine format
        $header = fread($handle, 80);
        if ($header === false || strlen($header) < 80) {
            error_log("LayerVault ERROR: Cannot read STL header from: {$filepath}");
            fclose($handle);
            return false;
        }
        
        // Check if it's ASCII format (starts with 'solid' and contains printable characters)
        if (substr($header, 0, 5) === 'solid' && self::isPrintableASCII($header)) {
            fclose($handle);
            return self::parseASCII($filepath);
        } else {
            // Binary format - triangle count is in bytes 80-83
            $triangleCountData = fread($handle, 4);
            fclose($handle);
            
            if (strlen($triangleCountData) < 4) {
                error_log("LayerVault ERROR: Cannot read triangle count from binary STL: {$filepath}");
                return false;
            }
            
            $triangleCount = unpack('V', $triangleCountData)[1];
            
            // Verify file size matches expected binary format
            $expectedSize = 80 + 4 + ($triangleCount * 50);
            if ($filesize !== $expectedSize) {
                error_log("LayerVault ERROR: Binary STL size mismatch - Expected: {$expectedSize}, Actual: {$filesize} for file: {$filepath}");
                return false;
            }
            
            return self::parseBinary($filepath, $triangleCount);
        }
    }
    
    /**
     * Check if string contains printable ASCII characters
     */
    private static function isPrintableASCII($string) {
        $length = strlen($string);
        for ($i = 0; $i < $length; $i++) {
            $char = ord($string[$i]);
            // Allow printable ASCII (32-126) plus common whitespace (9,10,13)
            if (!($char >= 32 && $char <= 126) && !in_array($char, [9, 10, 13])) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Parse ASCII STL format
     */
    private static function parseASCII($filepath) {
        $content = file_get_contents($filepath);
        if ($content === false) {
            error_log("LayerVault ERROR: Cannot read ASCII STL content from: {$filepath}");
            return false;
        }
        
        // Count triangles by counting 'endfacet' occurrences
        $triangleCount = substr_count($content, 'endfacet');
        
        // Extract all vertex coordinates
        $vertices = [];
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, 'vertex') === 0) {
                $parts = preg_split('/\s+/', $line);
                if (count($parts) >= 4) {
                    $vertices[] = [
                        'x' => (float)$parts[1],
                        'y' => (float)$parts[2],
                        'z' => (float)$parts[3]
                    ];
                }
            }
        }
        
        if (empty($vertices)) {
            error_log("LayerVault ERROR: No vertices found in ASCII STL: {$filepath}");
            return false;
        }
        
        $bounds = self::calculateBounds($vertices);
        $volume = self::calculateVolume($vertices);
        $surfaceArea = self::calculateSurfaceArea($vertices);
        
        return [
            'format' => 'ascii',
            'triangles' => $triangleCount,
            'bounds' => $bounds,
            'volume' => $volume,
            'surface_area' => $surfaceArea,
            'vertices' => count($vertices)
        ];
    }
    
    /**
     * Parse Binary STL format
     */
    private static function parseBinary($filepath, $triangleCount) {
        $handle = fopen($filepath, 'rb');
        if ($handle === false) {
            error_log("LayerVault ERROR: Cannot open binary STL for parsing: {$filepath}");
            return false;
        }
        
        // Skip 80-byte header and 4-byte triangle count
        fseek($handle, 84);
        
        $vertices = [];
        
        // Read each triangle (50 bytes each)
        for ($i = 0; $i < $triangleCount; $i++) {
            // Skip normal vector (12 bytes)
            fseek($handle, 12, SEEK_CUR);
            
            // Read 3 vertices (36 bytes total)
            for ($j = 0; $j < 3; $j++) {
                $vertexData = fread($handle, 12);
                if (strlen($vertexData) < 12) {
                    error_log("LayerVault ERROR: Incomplete vertex data in binary STL triangle {$i}: {$filepath}");
                    fclose($handle);
                    return false;
                }
                
                $coords = unpack('f3', $vertexData);
                $vertices[] = [
                    'x' => $coords[1],
                    'y' => $coords[2],
                    'z' => $coords[3]
                ];
            }
            
            // Skip attribute byte count (2 bytes)
            fseek($handle, 2, SEEK_CUR);
        }
        
        fclose($handle);
        
        if (empty($vertices)) {
            error_log("LayerVault ERROR: No vertices found in binary STL: {$filepath}");
            return false;
        }
        
        $bounds = self::calculateBounds($vertices);
        $volume = self::calculateVolume($vertices);
        $surfaceArea = self::calculateSurfaceArea($vertices);
        
        return [
            'format' => 'binary',
            'triangles' => $triangleCount,
            'bounds' => $bounds,
            'volume' => $volume,
            'surface_area' => $surfaceArea,
            'vertices' => count($vertices)
        ];
    }
    
    /**
     * Calculate bounding box from vertices
     */
    private static function calculateBounds($vertices) {
        if (empty($vertices)) {
            error_log("LayerVault WARNING: Cannot calculate bounds - no vertices provided");
            return null;
        }
        
        $minX = $maxX = $vertices[0]['x'];
        $minY = $maxY = $vertices[0]['y'];
        $minZ = $maxZ = $vertices[0]['z'];
        
        foreach ($vertices as $vertex) {
            $minX = min($minX, $vertex['x']);
            $maxX = max($maxX, $vertex['x']);
            $minY = min($minY, $vertex['y']);
            $maxY = max($maxY, $vertex['y']);
            $minZ = min($minZ, $vertex['z']);
            $maxZ = max($maxZ, $vertex['z']);
        }
        
        return [
            'min' => ['x' => $minX, 'y' => $minY, 'z' => $minZ],
            'max' => ['x' => $maxX, 'y' => $maxY, 'z' => $maxZ],
            'dimensions' => [
                'width' => $maxX - $minX,
                'height' => $maxY - $minY,
                'depth' => $maxZ - $minZ
            ]
        ];
    }
    
    /**
     * Calculate approximate volume using triangulated mesh
     */
    private static function calculateVolume($vertices) {
        if (count($vertices) < 9) { // Need at least 3 triangles
            return 0;
        }
        
        $volume = 0;
        
        // Process vertices in groups of 3 (triangles)
        for ($i = 0; $i < count($vertices) - 2; $i += 3) {
            $v1 = $vertices[$i];
            $v2 = $vertices[$i + 1];
            $v3 = $vertices[$i + 2];
            
            // Calculate signed volume of tetrahedron formed by origin and triangle
            $volume += ($v1['x'] * ($v2['y'] * $v3['z'] - $v3['y'] * $v2['z']) +
                       $v2['x'] * ($v3['y'] * $v1['z'] - $v1['y'] * $v3['z']) +
                       $v3['x'] * ($v1['y'] * $v2['z'] - $v2['y'] * $v1['z'])) / 6.0;
        }
        
        return abs($volume);
    }
    
    /**
     * Calculate surface area
     */
    private static function calculateSurfaceArea($vertices) {
        if (count($vertices) < 9) {
            return 0;
        }
        
        $surfaceArea = 0;
        
        // Process vertices in groups of 3 (triangles)
        for ($i = 0; $i < count($vertices) - 2; $i += 3) {
            $v1 = $vertices[$i];
            $v2 = $vertices[$i + 1];
            $v3 = $vertices[$i + 2];
            
            // Calculate triangle area using cross product
            $edge1 = [
                'x' => $v2['x'] - $v1['x'],
                'y' => $v2['y'] - $v1['y'],
                'z' => $v2['z'] - $v1['z']
            ];
            
            $edge2 = [
                'x' => $v3['x'] - $v1['x'],
                'y' => $v3['y'] - $v1['y'],
                'z' => $v3['z'] - $v1['z']
            ];
            
            // Cross product
            $cross = [
                'x' => $edge1['y'] * $edge2['z'] - $edge1['z'] * $edge2['y'],
                'y' => $edge1['z'] * $edge2['x'] - $edge1['x'] * $edge2['z'],
                'z' => $edge1['x'] * $edge2['y'] - $edge1['y'] * $edge2['x']
            ];
            
            // Magnitude of cross product / 2 = triangle area
            $magnitude = sqrt($cross['x'] * $cross['x'] + $cross['y'] * $cross['y'] + $cross['z'] * $cross['z']);
            $surfaceArea += $magnitude / 2.0;
        }
        
        return $surfaceArea;
    }
    
    /**
     * Validate STL file format and basic integrity
     */
    public static function validate($filepath) {
        try {
            $result = self::parse($filepath);
            
            if ($result === false) {
                error_log("LayerVault ERROR: STL validation failed - parse returned false for: {$filepath}");
                return ['valid' => false, 'error' => 'Invalid STL file format'];
            }
        } catch (Exception $e) {
            error_log("LayerVault ERROR: STL validation exception for {$filepath}: " . $e->getMessage());
            return ['valid' => false, 'error' => 'STL parsing error: ' . $e->getMessage()];
        }
        
        if ($result['triangles'] <= 0) {
            error_log("LayerVault ERROR: STL validation failed - no triangles in: {$filepath}");
            return ['valid' => false, 'error' => 'STL file contains no triangles'];
        }
        
        if ($result['vertices'] <= 0) {
            error_log("LayerVault ERROR: STL validation failed - no vertices in: {$filepath}");
            return ['valid' => false, 'error' => 'STL file contains no vertices'];
        }
        
        // Check for reasonable bounds
        $bounds = $result['bounds'];
        if (!$bounds || $bounds['dimensions']['width'] <= 0 || 
            $bounds['dimensions']['height'] <= 0 || 
            $bounds['dimensions']['depth'] <= 0) {
            error_log("LayerVault ERROR: STL validation failed - invalid dimensions in: {$filepath}");
            return ['valid' => false, 'error' => 'STL file has invalid dimensions'];
        }
        
        return ['valid' => true, 'metadata' => $result];
    }
    
    /**
     * Get human-readable file size
     */
    public static function formatFileSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}