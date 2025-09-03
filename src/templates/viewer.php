<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($file['original_filename']) ?> - LayerVault</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.2.96/css/materialdesignicons.min.css">
    <link rel="stylesheet" href="../assets/style.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/STLLoader.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
</head>
<body class="viewer-page">
    <div class="viewer-container">
        <header class="viewer-header">
            <div class="viewer-nav">
                <a href="/" class="back-btn"><i class="mdi mdi-arrow-left"></i> Back to Library</a>
                <h1 class="file-title"><?= htmlspecialchars($file['original_filename']) ?></h1>
                <div class="viewer-actions">
                    <button class="btn btn-primary" onclick="downloadFile(<?= $file['id'] ?>)">
                        <i class="mdi mdi-download"></i> Download
                    </button>
                    <button class="btn btn-danger" onclick="deleteFile(<?= $file['id'] ?>)">
                        <i class="mdi mdi-delete"></i> Delete
                    </button>
                </div>
            </div>
        </header>

        <div class="viewer-content">
            <div class="viewer-main">
                <div class="viewer-3d">
                    <canvas id="stlViewer" class="stl-canvas"></canvas>
                    <div class="viewer-overlay">
                        <div class="loading-indicator" id="loadingIndicator">
                            <div class="spinner"></div>
                            <p>Loading 3D model...</p>
                        </div>
                        <div class="viewer-controls">
                            <button class="control-btn" id="resetView" title="Reset View"><i class="mdi mdi-target"></i></button>
                            <button class="control-btn" id="toggleWireframe" title="Toggle Wireframe"><i class="mdi mdi-grid"></i></button>
                            <button class="control-btn" id="fullscreen" title="Fullscreen"><i class="mdi mdi-fullscreen"></i></button>
                        </div>
                        <div class="viewer-info">
                            <div class="info-item">
                                <span class="info-label">Camera:</span>
                                <span id="cameraInfo">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="viewer-sidebar">
                <div class="file-details">
                    <h3>File Information</h3>
                    
                    <div class="detail-group">
                        <h4>General</h4>
                        <div class="detail-item">
                            <span class="detail-label">Filename:</span>
                            <span class="detail-value"><?= htmlspecialchars($file['original_filename']) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">File Size:</span>
                            <span class="detail-value"><?= $file['formatted_size'] ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Format:</span>
                            <span class="detail-value"><?= strtoupper($file['format']) ?> STL</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Uploaded:</span>
                            <span class="detail-value"><?= $file['formatted_date'] ?></span>
                        </div>
                        <?php if (!empty($file['file_hash'])): ?>
                        <div class="detail-item">
                            <span class="detail-label">File Hash:</span>
                            <span class="detail-value" style="font-family: monospace; font-size: 0.8em;"><?= substr($file['file_hash'], 0, 16) ?>...</span>
                        </div>
                        <?php endif; ?>
                    </div>


                    <div class="detail-group">
                        <h4>Dimensions</h4>
                        <div class="detail-item">
                            <span class="detail-label">Width (X):</span>
                            <span class="detail-value"><?= number_format($file['width'] ?? 0, 2) ?> mm</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Height (Y):</span>
                            <span class="detail-value"><?= number_format($file['height'] ?? 0, 2) ?> mm</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Depth (Z):</span>
                            <span class="detail-value"><?= number_format($file['depth'] ?? 0, 2) ?> mm</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Bounding Box:</span>
                            <span class="detail-value"><?= $file['dimensions_str'] ?></span>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Toast notifications -->
    <div id="toast" class="toast"></div>

    <script src="../assets/viewer.js?v=<?= time() ?>"></script>
    <script>
        // Initialize viewer with file data
        document.addEventListener('DOMContentLoaded', function() {
            const viewer = new STLViewer('stlViewer');
            viewer.loadSTL('/serve/<?= $file['id'] ?>');
            
            // Set up viewer controls
            document.getElementById('resetView').addEventListener('click', () => viewer.resetView());
            document.getElementById('toggleWireframe').addEventListener('click', () => viewer.toggleWireframe());
            document.getElementById('fullscreen').addEventListener('click', () => viewer.toggleFullscreen());
        });

        function downloadFile(id) {
            window.open('/serve/' + id, '_blank');
        }

        function deleteFile(id) {
            if (confirm('Are you sure you want to delete this file?')) {
                fetch('/delete/' + id, { method: 'DELETE' })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showToast('File deleted successfully', 'success');
                            setTimeout(() => window.location.href = '/', 1500);
                        } else {
                            showToast(data.error || 'Failed to delete file', 'error');
                        }
                    })
                    .catch(() => showToast('Network error', 'error'));
            }
        }

        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `toast ${type} show`;
            setTimeout(() => toast.className = 'toast', 3000);
        }
    </script>
</body>
</html>