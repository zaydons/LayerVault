const express = require('express');
const cors = require('cors');
const fs = require('fs');
const path = require('path');
const { stl2png, makeStandardMaterial, makeAmbientLight, makeDirectionalLight, makeEdgeMaterial, makeNormalMaterial } = require('@scalenc/stl-to-png');

const app = express();
const port = 3000;

// Middleware
app.use(cors());
app.use(express.json());

// Health check endpoint
app.get('/health', (req, res) => {
    res.json({ status: 'healthy', service: 'layervault-thumbnail-service' });
});

// Generate thumbnail from STL file path
app.post('/generate-thumbnail', async (req, res) => {
    try {
        const { stlFilePath, outputFilename, rotation } = req.body;
        
        if (!stlFilePath || !outputFilename) {
            return res.status(400).json({ 
                error: 'Missing stlFilePath or outputFilename' 
            });
        }

        const stlPath = `/app/uploads/${path.basename(stlFilePath)}`;
        const outputPath = `/app/thumbnails/${outputFilename}`;
        
        // Check if STL file exists
        if (!fs.existsSync(stlPath)) {
            return res.status(404).json({ 
                error: `STL file not found: ${stlPath}` 
            });
        }

        // Ensure thumbnails directory exists
        const thumbnailDir = path.dirname(outputPath);
        if (!fs.existsSync(thumbnailDir)) {
            fs.mkdirSync(thumbnailDir, { recursive: true });
        }

        console.log(`Generating thumbnail: ${stlPath} -> ${outputPath}`);
        
        // Read STL file data
        const stlData = fs.readFileSync(stlPath);
        
        // Generate thumbnail using @scalenc/stl-to-png with flat lighting
        const options = { 
            width: 300, 
            height: 300,
            backgroundColor: '#ffffff',
            // Use standard material with edge material for definition
            materials: [makeStandardMaterial(1.0, '#818589')],
            edgeMaterials: [makeEdgeMaterial(0.8, '#5a5d61')],
            // Balanced lighting: ambient + gentle directional for depth
            lights: [
                makeAmbientLight('#ffffff', 0.8),
                makeDirectionalLight(1, 1, 1, '#ffffff', 0.2)
            ]
        };
        
        // Set camera position for centered angled view
        if (rotation !== undefined) {
            const rad = (rotation * Math.PI) / 180;
            const distance = 3.5; // Closer for better centering
            options.cameraPosition = [
                Math.sin(rad) * distance,
                1.2, // Lower for better centering
                Math.cos(rad) * distance
            ];
        } else {
            // Default: centered angled view
            options.cameraPosition = [2.5, 1.2, 2.5];
        }
        
        const pngData = stl2png(stlData, options);
        
        // Write thumbnail to output path
        fs.writeFileSync(outputPath, pngData);

        // Verify thumbnail was created
        if (!fs.existsSync(outputPath)) {
            throw new Error('Thumbnail file was not created');
        }

        const stats = fs.statSync(outputPath);
        
        res.json({
            success: true,
            message: 'Thumbnail generated successfully',
            filename: outputFilename,
            size: stats.size
        });

        console.log(`Thumbnail completed: ${outputFilename} (${stats.size} bytes)`);

    } catch (error) {
        console.error('Thumbnail generation error:', error);
        
        res.status(500).json({ 
            error: 'Failed to generate thumbnail',
            details: error.message 
        });
    }
});

// Error handling middleware
app.use((error, req, res, next) => {
    console.error('Unhandled error:', error);
    res.status(500).json({ error: 'Internal server error' });
});

app.listen(port, '0.0.0.0', () => {
    console.log(`LayerVault Thumbnail Service running on port ${port}`);
    console.log('Ready to generate STL thumbnails');
});

process.on('SIGTERM', () => {
    console.log('Thumbnail service shutting down gracefully');
    process.exit(0);
});