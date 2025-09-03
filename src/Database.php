<?php

class Database {
    private $pdo;
    private $dbPath;
    
    public function __construct($dbPath = null) {
        $this->dbPath = $dbPath ?: ($_ENV['LAYERVAULT_DB_PATH'] ?? __DIR__ . '/data/layervault.db');
        $this->connect();
        $this->createTables();
    }
    
    private function connect() {
        try {
            // Create data directory if it doesn't exist
            $dataDir = dirname($this->dbPath);
            if (!is_dir($dataDir)) {
                if (!mkdir($dataDir, 0755, true)) {
                    error_log("LayerVault ERROR: Cannot create data directory: $dataDir");
                    throw new Exception("Cannot create data directory: $dataDir");
                }
            }
            
            // Check if directory is writable
            if (!is_writable($dataDir)) {
                error_log("LayerVault ERROR: Data directory is not writable: $dataDir");
                throw new Exception("Data directory is not writable: $dataDir");
            }
            
            $this->pdo = new PDO('sqlite:' . $this->dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // Enable foreign keys
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        } catch (PDOException $e) {
            error_log("LayerVault ERROR: Database connection failed: " . $e->getMessage());
            throw new Exception('Database connection failed: ' . $e->getMessage());
        } catch (Exception $e) {
            error_log("LayerVault ERROR: Database initialization error: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function createTables() {
        try {
            $sql = "
                CREATE TABLE IF NOT EXISTS files (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    filename TEXT NOT NULL,
                    original_filename TEXT NOT NULL,
                    filepath TEXT NOT NULL,
                    filesize INTEGER NOT NULL,
                    file_hash TEXT UNIQUE,
                    triangles INTEGER,
                    vertices INTEGER,
                    format TEXT,
                    bounds_min_x REAL,
                    bounds_min_y REAL,
                    bounds_min_z REAL,
                    bounds_max_x REAL,
                    bounds_max_y REAL,
                    bounds_max_z REAL,
                    width REAL,
                    height REAL,
                    depth REAL,
                    volume REAL,
                    surface_area REAL,
                    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                
                CREATE INDEX IF NOT EXISTS idx_filename ON files(filename);
                CREATE INDEX IF NOT EXISTS idx_uploaded_at ON files(uploaded_at);
                CREATE INDEX IF NOT EXISTS idx_filesize ON files(filesize);
                CREATE INDEX IF NOT EXISTS idx_file_hash ON files(file_hash);
            ";
            
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            error_log("LayerVault ERROR: Failed to create database tables: " . $e->getMessage());
            throw new Exception('Database table creation failed: ' . $e->getMessage());
        }
    }
    
    public function insertFile($data) {
        try {
            $sql = "
                INSERT INTO files (
                    filename, original_filename, filepath, filesize, file_hash, triangles, vertices,
                    format, bounds_min_x, bounds_min_y, bounds_min_z, bounds_max_x, bounds_max_y, bounds_max_z,
                    width, height, depth, volume, surface_area
                ) VALUES (
                    :filename, :original_filename, :filepath, :filesize, :file_hash, :triangles, :vertices,
                    :format, :bounds_min_x, :bounds_min_y, :bounds_min_z, :bounds_max_x, :bounds_max_y, :bounds_max_z,
                    :width, :height, :depth, :volume, :surface_area
                )
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($data);
            
            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log("LayerVault ERROR: Failed to insert file: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function getFiles($search = null, $orderBy = 'uploaded_at', $order = 'DESC', $limit = null, $offset = 0) {
        try {
            $sql = "SELECT * FROM files";
            $params = [];
            
            if ($search) {
                $sql .= " WHERE filename LIKE :search OR original_filename LIKE :search";
                $params['search'] = '%' . $search . '%';
            }
            
            $allowedOrderBy = ['filename', 'filesize', 'triangles', 'uploaded_at', 'volume', 'surface_area'];
            $allowedOrder = ['ASC', 'DESC'];
            
            if (in_array($orderBy, $allowedOrderBy) && in_array($order, $allowedOrder)) {
                $sql .= " ORDER BY {$orderBy} {$order}";
            } else {
                error_log("LayerVault WARNING: Invalid orderBy '{$orderBy}' or order '{$order}', using defaults");
                $sql .= " ORDER BY uploaded_at DESC";
            }
            
            if ($limit) {
                $sql .= " LIMIT :limit OFFSET :offset";
                $params['limit'] = (int)$limit;
                $params['offset'] = (int)$offset;
            }
            
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($params as $key => $value) {
                if (is_int($value)) {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("LayerVault ERROR: Failed to get files: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function getFile($id) {
        try {
            $sql = "SELECT * FROM files WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("LayerVault ERROR: Failed to get file {$id}: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function deleteFile($id) {
        try {
            $sql = "DELETE FROM files WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute(['id' => $id]);
            
            if ($result && $stmt->rowCount() > 0) {
                return true;
            } else {
                error_log("LayerVault WARNING: No rows affected when deleting file {$id}");
                return false;
            }
        } catch (PDOException $e) {
            error_log("LayerVault ERROR: Failed to delete file {$id}: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function updateFile($id, $data) {
        $fields = [];
        $params = ['id' => $id];
        
        foreach ($data as $key => $value) {
            $fields[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $sql = "UPDATE files SET " . implode(', ', $fields) . ", updated_at = CURRENT_TIMESTAMP WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        
        return $stmt->execute($params);
    }
    
    public function getStats() {
        try {
            $sql = "
                SELECT 
                    COUNT(*) as total_files,
                    SUM(filesize) as total_size,
                    SUM(triangles) as total_triangles,
                    SUM(volume) as total_volume,
                    AVG(filesize) as avg_file_size,
                    AVG(triangles) as avg_triangles,
                    MIN(uploaded_at) as first_upload,
                    MAX(uploaded_at) as last_upload
                FROM files
            ";
            
            $stmt = $this->pdo->query($sql);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("LayerVault ERROR: Failed to get stats: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function searchFiles($query, $limit = 50) {
        try {
            $sql = "
                SELECT * FROM files 
                WHERE filename LIKE :query 
                   OR original_filename LIKE :query
                ORDER BY 
                    CASE 
                        WHEN filename LIKE :exact_query THEN 1
                        WHEN filename LIKE :starts_query THEN 2
                        ELSE 3
                    END,
                    uploaded_at DESC
                LIMIT :limit
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue('query', '%' . $query . '%');
            $stmt->bindValue('exact_query', $query);
            $stmt->bindValue('starts_query', $query . '%');
            $stmt->bindValue('limit', (int)$limit, PDO::PARAM_INT);
            
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("LayerVault ERROR: Search failed for query '{$query}': " . $e->getMessage());
            throw $e;
        }
    }
    
    public function fileExists($filename) {
        try {
            $sql = "SELECT COUNT(*) FROM files WHERE filename = :filename";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['filename' => $filename]);
            
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("LayerVault ERROR: Failed to check file existence for '{$filename}': " . $e->getMessage());
            throw $e;
        }
    }
    
    public function fileExistsByHash($hash) {
        try {
            $sql = "SELECT COUNT(*) FROM files WHERE file_hash = :hash";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['hash' => $hash]);
            
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("LayerVault ERROR: Failed to check file existence by hash: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function close() {
        $this->pdo = null;
    }
}