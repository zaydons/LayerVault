/**
 * LayerVault - Main Application JavaScript
 * Handles file upload, search, and general UI interactions
 */

class LayerVaultApp {
    constructor() {
        this.currentPage = 1;
        this.isLoading = false;
        this.searchTimeout = null;
        
        this.init();
    }
    
    init() {
        this.setupFileUpload();
        this.setupSearch();
        this.setupSorting();
        this.setupLoadMore();
        this.setupKeyboardShortcuts();
        this.checkForUploadSuccess();
    }
    
    checkForUploadSuccess() {
        const successMessage = sessionStorage.getItem('uploadSuccess');
        if (successMessage) {
            this.showToast(successMessage, 'success');
            sessionStorage.removeItem('uploadSuccess');
        }
    }
    
    setupFileUpload() {
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('fileInput');
        const uploadProgress = document.getElementById('uploadProgress');
        const progressFill = document.getElementById('progressFill');
        const progressText = document.getElementById('progressText');
        
        if (!uploadArea || !fileInput) return;
        
        // Click to browse files
        uploadArea.addEventListener('click', (e) => {
            if (e.target === uploadArea || e.target.closest('.upload-content')) {
                fileInput.click();
            }
        });
        
        // File selection via input
        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                this.handleFiles(Array.from(e.target.files));
            }
        });
        
        // Drag and drop functionality
        uploadArea.addEventListener('dragenter', (e) => {
            e.preventDefault();
            uploadArea.classList.add('drag-over');
        });
        
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
        });
        
        uploadArea.addEventListener('dragleave', (e) => {
            e.preventDefault();
            if (!uploadArea.contains(e.relatedTarget)) {
                uploadArea.classList.remove('drag-over');
            }
        });
        
        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('drag-over');
            
            const files = Array.from(e.dataTransfer.files).filter(file => 
                file.name.toLowerCase().endsWith('.stl')
            );
            
            if (files.length === 0) {
                this.showToast('Please drop STL files only', 'warning');
                return;
            }
            
            this.handleFiles(files);
        });
    }
    
    async handleFiles(files) {
        if (this.isLoading) return;
        
        const uploadArea = document.getElementById('uploadArea');
        const uploadProgress = document.getElementById('uploadProgress');
        const progressFill = document.getElementById('progressFill');
        const progressText = document.getElementById('progressText');
        
        let successfulUploads = [];
        
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            
            if (!file.name.toLowerCase().endsWith('.stl')) {
                this.showToast(`Skipped ${file.name} - Only STL files are allowed`, 'warning');
                continue;
            }
            
            try {
                this.isLoading = true;
                uploadProgress.style.display = 'flex';
                
                progressText.textContent = `Uploading ${file.name} (${i + 1}/${files.length})...`;
                progressFill.style.width = '0%';
                
                await this.uploadFile(file, (progress) => {
                    progressFill.style.width = `${progress}%`;
                });
                
                successfulUploads.push(file.name);
                
            } catch (error) {
                console.error('Upload error:', error);
                this.showToast(`Failed to upload ${file.name}: ${error.message}`, 'error');
            }
        }
        
        this.isLoading = false;
        uploadProgress.style.display = 'none';
        
        if (successfulUploads.length > 0) {
            const message = successfulUploads.length === 1 
                ? `${successfulUploads[0]} uploaded successfully!`
                : `${successfulUploads.length} files uploaded successfully!`;
            sessionStorage.setItem('uploadSuccess', message);
        }
        
        window.location.reload();
    }
    
    uploadFile(file, onProgress) {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('stl_file', file);
            
            const xhr = new XMLHttpRequest();
            
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const progress = (e.loaded / e.total) * 100;
                    onProgress(progress);
                }
            });
            
            xhr.addEventListener('load', () => {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            resolve(response);
                        } else {
                            reject(new Error(response.error || 'Upload failed'));
                        }
                    } catch (e) {
                        reject(new Error('Invalid server response'));
                    }
                } else {
                    reject(new Error(`Server error: ${xhr.status}`));
                }
            });
            
            xhr.addEventListener('error', () => {
                reject(new Error('Network error'));
            });
            
            xhr.open('POST', '/upload.php');
            xhr.send(formData);
        });
    }
    
    setupSearch() {
        const searchInput = document.getElementById('searchInput');
        const searchButton = document.getElementById('searchButton');
        
        if (!searchInput) return;
        
        // Search on input with debounce
        searchInput.addEventListener('input', (e) => {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => {
                this.performSearch(e.target.value);
            }, 500);
        });
        
        // Search on button click
        if (searchButton) {
            searchButton.addEventListener('click', () => {
                this.performSearch(searchInput.value);
            });
        }
        
        // Search on Enter key
        searchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                this.performSearch(e.target.value);
            }
        });
    }
    
    performSearch(query) {
        const params = new URLSearchParams(window.location.search);
        
        if (query.trim()) {
            params.set('search', query.trim());
        } else {
            params.delete('search');
        }
        
        params.delete('page'); // Reset to first page
        
        // Update URL and reload
        const newUrl = window.location.pathname + '?' + params.toString();
        window.location.href = newUrl;
    }
    
    setupSorting() {
        const sortBy = document.getElementById('sortBy');
        const sortOrder = document.getElementById('sortOrder');
        
        if (sortBy) {
            sortBy.addEventListener('change', () => this.updateSort());
        }
        
        if (sortOrder) {
            sortOrder.addEventListener('change', () => this.updateSort());
        }
    }
    
    updateSort() {
        const sortBy = document.getElementById('sortBy')?.value || 'uploaded_at';
        const sortOrder = document.getElementById('sortOrder')?.value || 'DESC';
        
        const params = new URLSearchParams(window.location.search);
        params.set('order_by', sortBy);
        params.set('order', sortOrder);
        params.delete('page'); // Reset to first page
        
        const newUrl = window.location.pathname + '?' + params.toString();
        window.location.href = newUrl;
    }
    
    setupLoadMore() {
        const loadMoreBtn = document.getElementById('loadMoreBtn');
        if (!loadMoreBtn) return;
        
        loadMoreBtn.addEventListener('click', () => {
            const params = new URLSearchParams(window.location.search);
            const currentPage = parseInt(params.get('page') || '1');
            params.set('page', currentPage + 1);
            
            const newUrl = window.location.pathname + '?' + params.toString();
            window.location.href = newUrl;
        });
    }
    
    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Ctrl/Cmd + U for upload
            if ((e.ctrlKey || e.metaKey) && e.key === 'u') {
                e.preventDefault();
                const fileInput = document.getElementById('fileInput');
                if (fileInput) fileInput.click();
            }
            
            // Escape to clear search
            if (e.key === 'Escape') {
                const searchInput = document.getElementById('searchInput');
                if (searchInput && searchInput.value) {
                    searchInput.value = '';
                    this.performSearch('');
                }
            }
            
            // Ctrl/Cmd + F for search focus
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
        });
    }
    
    showToast(message, type = 'info') {
        const toast = document.getElementById('toast');
        if (!toast) return;
        
        toast.textContent = message;
        toast.className = `toast ${type} show`;
        
        setTimeout(() => {
            toast.className = 'toast';
        }, 6000);
    }
}

// File action functions (global scope for template usage)
window.viewFile = function(id) {
    window.location.href = `/view/${id}`;
};

window.downloadFile = function(id) {
    const link = document.createElement('a');
    link.href = `/serve/${id}`;
    link.download = '';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
};

window.deleteFile = function(id) {
    // Get filename for better confirmation dialog
    const fileCard = document.querySelector(`[data-id="${id}"]`);
    const fileName = fileCard ? (fileCard.querySelector('.file-name')?.textContent || 'this file').trim() : 'this file';
    
    const message = `Are you sure you want to delete "${fileName}"?

This will permanently remove:
• The STL file
• The thumbnail image  
• All file information

This action cannot be undone.`;
    
    if (!confirm(message)) {
        return;
    }
    
    fetch(`/delete/${id}`, {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            app.showToast('File deleted successfully', 'success');
            
            // Remove file card from UI
            const fileCard = document.querySelector(`[data-id="${id}"]`);
            if (fileCard) {
                fileCard.style.opacity = '0';
                fileCard.style.transform = 'scale(0.95)';
                setTimeout(() => fileCard.remove(), 300);
            }
            
            // If no files left, reload page to show empty state
            setTimeout(() => {
                const fileGrid = document.getElementById('fileGrid');
                if (fileGrid && fileGrid.children.length === 0) {
                    window.location.reload();
                }
            }, 500);
        } else {
            app.showToast(data.error || 'Failed to delete file', 'error');
        }
    })
    .catch(error => {
        console.error('Delete error:', error);
        app.showToast('Network error occurred', 'error');
    });
};

// Live search functionality
window.performLiveSearch = function(query) {
    if (query.length < 2) {
        document.getElementById('searchResults').style.display = 'none';
        return;
    }
    
    fetch(`/api/search?q=${encodeURIComponent(query)}`)
        .then(response => response.json())
        .then(data => {
            displaySearchResults(data.results);
        })
        .catch(error => {
            console.error('Search error:', error);
        });
};

function displaySearchResults(results) {
    const resultsContainer = document.getElementById('searchResults');
    if (!resultsContainer) return;
    
    if (results.length === 0) {
        resultsContainer.innerHTML = '<div class="search-no-results">No files found</div>';
    } else {
        const html = results.map(file => `
            <div class="search-result" onclick="viewFile(${file.id})">
                <div class="search-result-icon">🧊</div>
                <div class="search-result-info">
                    <div class="search-result-name">${escapeHtml(file.original_filename)}</div>
                    <div class="search-result-meta">
                        ${file.formatted_size} • ${file.triangles} triangles • ${file.formatted_date}
                    </div>
                </div>
            </div>
        `).join('');
        
        resultsContainer.innerHTML = html;
    }
    
    resultsContainer.style.display = 'block';
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}

// File size formatter
window.formatFileSize = function(bytes) {
    const units = ['B', 'KB', 'MB', 'GB'];
    let size = bytes;
    let unitIndex = 0;
    
    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex++;
    }
    
    return `${size.toFixed(1)} ${units[unitIndex]}`;
};

// Initialize app when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.app = new LayerVaultApp();
});

// Service Worker registration for offline functionality (optional)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        // Uncomment to enable service worker
        // navigator.serviceWorker.register('/sw.js')
        //     .then(registration => console.log('SW registered'))
        //     .catch(error => console.log('SW registration failed'));
    });
}

// Global error handler
window.addEventListener('error', (e) => {
    console.error('Global error:', e.error);
    if (window.app) {
        window.app.showToast('An unexpected error occurred', 'error');
    }
});

// Handle network status changes
window.addEventListener('online', () => {
    if (window.app) {
        window.app.showToast('Back online', 'success');
    }
});

window.addEventListener('offline', () => {
    if (window.app) {
        window.app.showToast('You are offline', 'warning');
    }
});