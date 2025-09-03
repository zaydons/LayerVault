<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LayerVault - STL Library</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.2.96/css/materialdesignicons.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="icon" type="image/x-icon" href="data:image/x-icon;base64,AAABAAEAEBAAAAEAIABoBAAAFgAAACgAAAAQAAAAIAAAAAEAIAAAAAAAAAQAABMLAAATCwAAAAAAAAAAAAAAAAAA">
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="header-content">
                <h1 class="logo">
                    <i class="mdi mdi-file-cabinet vault-icon"></i>
                    LayerVault
                </h1>
                <div class="header-stats">
                    <span class="stat">
                        <strong><?= number_format($stats['total_files'] ?? 0) ?></strong> files
                    </span>
                    <span class="stat">
                        <strong><?= STLParser::formatFileSize($stats['total_size'] ?? 0) ?></strong> total
                    </span>
                </div>
            </div>
        </header>

        <div class="upload-section">
            <div class="upload-area" id="uploadArea">
                <div class="upload-content">
                    <div class="upload-icon"><i class="mdi mdi-cloud-upload"></i></div>
                    <h3>Drag & Drop STL Files Here</h3>
                    <p>or click to browse files</p>
                    <input type="file" id="fileInput" accept=".stl" multiple hidden>
                    <div class="upload-limits">
                        Max file size: <?= STLParser::formatFileSize($this->maxFileSize ?? 104857600) ?>
                    </div>
                </div>
                <div class="upload-progress" id="uploadProgress" style="display: none;">
                    <div class="progress-bar">
                        <div class="progress-fill" id="progressFill"></div>
                    </div>
                    <div class="progress-text" id="progressText">Uploading...</div>
                </div>
            </div>
        </div>

        <div class="controls">
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Search files..." 
                       value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                <button type="button" id="searchButton"><i class="mdi mdi-magnify"></i></button>
            </div>
            
            <div class="sort-controls">
                <label>Sort by:</label>
                <select id="sortBy">
                    <option value="uploaded_at" <?= $orderBy === 'uploaded_at' ? 'selected' : '' ?>>Upload Date</option>
                    <option value="filename" <?= $orderBy === 'filename' ? 'selected' : '' ?>>Name</option>
                    <option value="filesize" <?= $orderBy === 'filesize' ? 'selected' : '' ?>>Size</option>
                    <option value="triangles" <?= $orderBy === 'triangles' ? 'selected' : '' ?>>Triangles</option>
                </select>
                <select id="sortOrder">
                    <option value="DESC" <?= $order === 'DESC' ? 'selected' : '' ?>>Descending</option>
                    <option value="ASC" <?= $order === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                </select>
            </div>
        </div>

        <?php if (empty($files)): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="mdi mdi-folder-open-outline"></i></div>
                <h3><?= $search ? 'No files found' : 'No files in vault' ?></h3>
                <p><?= $search ? 'Try a different search term' : 'Upload your first STL file to get started' ?></p>
            </div>
        <?php else: ?>
            <div class="file-grid" id="fileGrid">
                <?php foreach ($files as $file): ?>
                    <div class="file-card" data-id="<?= $file['id'] ?>">
                        <div class="file-header">
                            <h3 class="file-name" title="<?= htmlspecialchars($file['original_filename']) ?>">
                                <?= htmlspecialchars($file['original_filename']) ?>
                            </h3>
                            <div class="file-actions">
                                <button class="action-btn view-btn" onclick="viewFile(<?= $file['id'] ?>)" 
                                        title="View 3D Model"><i class="mdi mdi-eye"></i></button>
                                <button class="action-btn download-btn" onclick="downloadFile(<?= $file['id'] ?>)" 
                                        title="Download"><i class="mdi mdi-download"></i></button>
                                <button class="action-btn delete-btn" onclick="deleteFile(<?= $file['id'] ?>)" 
                                        title="Delete"><i class="mdi mdi-delete"></i></button>
                            </div>
                        </div>
                        
                        <div class="file-info">
                            <div class="file-meta">
                                <div class="meta-row">
                                    <span class="meta-label">Uploaded:</span>
                                    <span><?= $file['formatted_date'] ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="file-preview">
                            <?php 
                            $thumbnailFilename = pathinfo($file['original_filename'], PATHINFO_FILENAME) . '_thumb.png';
                            $thumbnailPath = 'thumbnails/' . $thumbnailFilename;
                            $thumbnailFullPath = '/var/www/html/thumbnails/' . $thumbnailFilename;
                            $thumbnailExists = file_exists($thumbnailFullPath);
                            ?>
                            <?php if ($thumbnailExists): ?>
                                <img src="<?= $thumbnailPath ?>" alt="<?= htmlspecialchars($file['original_filename']) ?>" class="thumbnail-image" onclick="viewFile(<?= $file['id'] ?>)">
                            <?php else: ?>
                                <div class="preview-placeholder">
                                    <div class="preview-icon">🧊</div>
                                    <small>Generating thumbnail...</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($hasMore): ?>
                <div class="load-more">
                    <button class="btn btn-secondary" id="loadMoreBtn">Load More Files</button>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Toast notifications -->
    <div id="toast" class="toast"></div>

    <script src="assets/app.js?v=<?= time() ?>"></script>
</body>
</html>