/**
 * LayerVault STL Viewer - Professional 3D STL file viewer
 * Professional STL file viewer with Three.js
 */

class STLViewer {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        if (!this.container) {
            throw new Error(`Container with ID '${containerId}' not found`);
        }

        // Three.js core components
        this.scene = null;
        this.camera = null;
        this.renderer = null;
        this.controls = null;
        this.model = null;
        this.wireframe = false;
        
        // Animation and rendering
        this.animationId = null;
        this.isRendering = false;
        
        // Model properties
        this.modelBounds = null;
        this.modelCenter = null;
        this.modelSize = 0;
        
        // Initialization
        this.init();
        this.setupEventListeners();
        this.startRenderLoop();
    }
    
    init() {
        this.scene = new THREE.Scene();
        this.scene.background = new THREE.Color('#0F172A');
        
        const aspect = this.container.clientWidth / this.container.clientHeight;
        this.camera = new THREE.PerspectiveCamera(75, aspect, 0.1, 10000);
        
        this.renderer = new THREE.WebGLRenderer({
            canvas: this.container,
            antialias: true,
            alpha: false
        });
        this.renderer.setSize(this.container.clientWidth, this.container.clientHeight);
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        
        this.renderer.shadowMap.enabled = true;
        this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        
        this.setupLighting();
        
        this.setupControls();
        
        this.setupGroundPlane();
    }
    
    setupLighting() {
        const lights = this.scene.children.filter(child => child.isLight);
        lights.forEach(light => this.scene.remove(light));
        
        const ambientLight = new THREE.AmbientLight('#FFFFFF', 0.6);
        this.scene.add(ambientLight);
        
        const mainLight = new THREE.DirectionalLight('#FFFFFF', 0.8);
        mainLight.position.set(50, 100, 50);
        mainLight.castShadow = true;
        
        mainLight.shadow.mapSize.width = 2048;
        mainLight.shadow.mapSize.height = 2048;
        mainLight.shadow.camera.near = 0.1;
        mainLight.shadow.camera.far = 500;
        mainLight.shadow.camera.left = -100;
        mainLight.shadow.camera.right = 100;
        mainLight.shadow.camera.top = 100;
        mainLight.shadow.camera.bottom = -100;
        
        this.scene.add(mainLight);
        
        const fillLight = new THREE.DirectionalLight('#FFFFFF', 0.4);
        fillLight.position.set(-30, 50, -30);
        this.scene.add(fillLight);
        
        const rimLight = new THREE.DirectionalLight('#FFFFFF', 0.2);
        rimLight.position.set(0, 10, -50);
        this.scene.add(rimLight);
    }
    
    setupControls() {
        // Setup OrbitControls with smooth, responsive behavior
        this.controls = new THREE.OrbitControls(this.camera, this.renderer.domElement);
        
        // Smooth, responsive controls
        this.controls.enableDamping = true;
        this.controls.dampingFactor = 0.05;
        this.controls.screenSpacePanning = false;
        
        // Zoom and pan limits
        this.controls.minDistance = 1;
        this.controls.maxDistance = 1000;
        this.controls.maxPolarAngle = Math.PI; // Allow full rotation
        
        // Smooth zoom and rotation
        this.controls.zoomSpeed = 1.0;
        this.controls.rotateSpeed = 1.0;
        this.controls.panSpeed = 0.8;
        
        // Auto-rotate (disabled by default)
        this.controls.autoRotate = false;
        this.controls.autoRotateSpeed = 0.5;
    }
    
    setupGroundPlane() {
        // Add subtle ground plane for reference
        const planeGeometry = new THREE.PlaneGeometry(1000, 1000);
        const planeMaterial = new THREE.MeshLambertMaterial({
            color: '#FFFFFF',
            transparent: true,
            opacity: 0.1
        });
        
        const plane = new THREE.Mesh(planeGeometry, planeMaterial);
        plane.rotation.x = -Math.PI / 2;
        plane.position.y = 0;
        plane.receiveShadow = true;
        
        this.scene.add(plane);
    }
    
    setupEventListeners() {
        // Handle window resize
        window.addEventListener('resize', () => this.handleResize());
        
        // Handle container resize (for responsive layouts)
        if (window.ResizeObserver) {
            const resizeObserver = new ResizeObserver(() => this.handleResize());
            resizeObserver.observe(this.container.parentElement);
        }
        
        // Controls event listeners
        this.controls.addEventListener('change', () => {
            this.updateCameraInfo();
        });
    }
    
    startRenderLoop() {
        const animate = () => {
            this.animationId = requestAnimationFrame(animate);
            
            // Update controls (for damping)
            this.controls.update();
            
            // Render scene
            this.renderer.render(this.scene, this.camera);
        };
        
        animate();
    }
    
    async loadSTL(url) {
        try {
            this.showLoading(true);
            
            // Remove existing model
            if (this.model) {
                this.scene.remove(this.model);
                this.model.geometry.dispose();
                this.model.material.dispose();
                this.model = null;
            }
            
            // Load STL file
            const loader = new THREE.STLLoader();
            
            const geometry = await new Promise((resolve, reject) => {
                loader.load(
                    url,
                    (geometry) => resolve(geometry),
                    (progress) => {
                        const percent = (progress.loaded / progress.total) * 100;
                        this.updateLoadingProgress(percent);
                    },
                    (error) => reject(error)
                );
            });
            
            // Process geometry
            geometry.computeVertexNormals(); // Smooth shading
            geometry.center(); // Center the geometry
            
            // Create material with primary purple color
            const material = new THREE.MeshPhongMaterial({
                color: '#8B5CF6', // Brighter purple color for visibility
                shininess: 100,
                transparent: false,
                side: THREE.DoubleSide
            });
            
            // Create mesh
            this.model = new THREE.Mesh(geometry, material);
            this.model.castShadow = true;
            this.model.receiveShadow = true;
            
            // Add to scene
            this.scene.add(this.model);
            
            // Calculate model bounds and fit camera
            this.calculateModelBounds();
            this.fitCameraToModel();
            
            this.showLoading(false);
            this.updateCameraInfo();
            
        } catch (error) {
            console.error('Error loading STL file:', error);
            this.showError('Failed to load STL file: ' + error.message);
        }
    }
    
    calculateModelBounds() {
        if (!this.model) return;
        
        const box = new THREE.Box3().setFromObject(this.model);
        this.modelBounds = box;
        this.modelCenter = box.getCenter(new THREE.Vector3());
        this.modelSize = box.getSize(new THREE.Vector3()).length();
    }
    
    fitCameraToModel() {
        if (!this.model || !this.modelBounds) return;
        
        const size = this.modelBounds.getSize(new THREE.Vector3());
        const center = this.modelBounds.getCenter(new THREE.Vector3());
        const maxDim = Math.max(size.x, size.y, size.z);
        
        // Calculate optimal camera distance
        const fov = this.camera.fov * (Math.PI / 180);
        const distance = Math.abs(maxDim / Math.sin(fov / 2)) * 1.2; // Add some padding
        
        // Position camera for optimal viewing angle
        const offset = new THREE.Vector3(
            maxDim * 0.5,  // Side offset
            maxDim * 0.3,  // Height offset  
            maxDim * 0.8   // Distance offset
        );
        
        this.camera.position.copy(center).add(offset);
        this.camera.lookAt(center);
        
        // Update controls target
        this.controls.target.copy(center);
        this.controls.update();
        
        // Adjust ground plane position
        const groundPlane = this.scene.children.find(child => child.geometry && child.geometry.type === 'PlaneGeometry');
        if (groundPlane) {
            groundPlane.position.y = this.modelBounds.min.y - 1;
        }
    }
    
    resetView() {
        if (this.model) {
            this.fitCameraToModel();
        } else {
            // Default camera position
            this.camera.position.set(100, 100, 100);
            this.camera.lookAt(0, 0, 0);
            this.controls.target.set(0, 0, 0);
            this.controls.update();
        }
        this.updateCameraInfo();
    }
    
    toggleWireframe() {
        if (!this.model) return;
        
        this.wireframe = !this.wireframe;
        
        if (this.wireframe) {
            // Switch to wireframe material
            this.model.material = new THREE.MeshBasicMaterial({
                color: '#8B5CF6', // Brighter purple color for visibility
                wireframe: true,
                transparent: true,
                opacity: 0.8
            });
        } else {
            // Switch back to solid material
            this.model.material = new THREE.MeshPhongMaterial({
                color: '#8B5CF6', // Brighter purple color for visibility
                shininess: 100,
                transparent: false,
                side: THREE.DoubleSide
            });
        }
    }
    
    toggleFullscreen() {
        if (!document.fullscreenElement) {
            this.container.parentElement.requestFullscreen().then(() => {
                this.handleResize();
            });
        } else {
            document.exitFullscreen().then(() => {
                this.handleResize();
            });
        }
    }
    
    handleResize() {
        const width = this.container.clientWidth;
        const height = this.container.clientHeight;
        
        // Update camera aspect ratio
        this.camera.aspect = width / height;
        this.camera.updateProjectionMatrix();
        
        // Update renderer size
        this.renderer.setSize(width, height);
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    }
    
    updateCameraInfo() {
        const cameraInfoElement = document.getElementById('cameraInfo');
        if (cameraInfoElement) {
            const distance = this.camera.position.distanceTo(this.controls.target);
            cameraInfoElement.textContent = `Distance: ${distance.toFixed(1)} units`;
        }
    }
    
    showLoading(show) {
        const loadingElement = document.getElementById('loadingIndicator');
        if (loadingElement) {
            loadingElement.style.display = show ? 'flex' : 'none';
        }
    }
    
    updateLoadingProgress(percent) {
        const progressFill = document.getElementById('progressFill');
        const progressText = document.getElementById('progressText');
        
        if (progressFill) {
            progressFill.style.width = `${percent}%`;
        }
        
        if (progressText) {
            progressText.textContent = `Loading... ${Math.round(percent)}%`;
        }
    }
    
    showError(message) {
        this.showLoading(false);
        
        // Create error overlay
        const errorDiv = document.createElement('div');
        errorDiv.className = 'viewer-error';
        errorDiv.innerHTML = `
            <div class="error-content">
                <h3>⚠️ Error</h3>
                <p>${message}</p>
                <button onclick="window.location.reload()" class="btn btn-primary">Reload Page</button>
            </div>
        `;
        
        errorDiv.style.cssText = `
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(248, 250, 252, 0.95);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 100;
        `;
        
        this.container.parentElement.appendChild(errorDiv);
    }
    
    getStats() {
        if (!this.model) return null;
        
        const geometry = this.model.geometry;
        const triangles = geometry.attributes.position ? geometry.attributes.position.count / 3 : 0;
        const vertices = geometry.attributes.position ? geometry.attributes.position.count : 0;
        
        return {
            triangles: Math.floor(triangles),
            vertices: vertices,
            bounds: this.modelBounds ? {
                min: this.modelBounds.min,
                max: this.modelBounds.max,
                size: this.modelBounds.getSize(new THREE.Vector3())
            } : null
        };
    }
    
    dispose() {
        // Clean up resources
        if (this.animationId) {
            cancelAnimationFrame(this.animationId);
        }
        
        if (this.model) {
            this.scene.remove(this.model);
            this.model.geometry.dispose();
            this.model.material.dispose();
        }
        
        if (this.controls) {
            this.controls.dispose();
        }
        
        if (this.renderer) {
            this.renderer.dispose();
        }
        
        // Remove event listeners
        window.removeEventListener('resize', this.handleResize);
    }
}

// Export for use in other scripts
window.STLViewer = STLViewer;