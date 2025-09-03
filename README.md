# LayerVault

Professional STL file library with dark theme and 3D visualization.

## Features

- **STL File Management** - Upload, view, delete STL files with drag & drop
- **Dark Theme Interface** - Professional design with Material Design icons  
- **3D Viewer** - Dark background with purple STL objects, interactive controls
- **Thumbnail Generation** - Automatic STL file previews via Node.js service
- **File Metadata** - Triangle count, dimensions, file size extraction
- **Search & Sort** - Real-time search with multiple sorting options
- **Responsive Design** - Works on desktop and mobile devices

## Quick Start

### Requirements
- Docker and Docker Compose

### Installation
```bash
# Start the application
docker-compose up -d

# Access at http://localhost:8080
```

## Configuration

### Environment Variables
All variables have defaults and are optional:

```bash
# PHP Settings  
PHP_UPLOAD_MAX_FILESIZE=100M
PHP_POST_MAX_SIZE=100M
PHP_MEMORY_LIMIT=256M
PHP_MAX_EXECUTION_TIME=300

# Application Paths
LAYERVAULT_DB_PATH=/var/www/html/data/layervault.db
LAYERVAULT_UPLOAD_PATH=/var/www/html/uploads
LAYERVAULT_THUMBNAIL_SERVICE=http://thumbnail-service:3000

# Node.js Environment
NODE_ENV=production
```

### Custom Configuration
Create `.env` file to override defaults:
```bash
cp .env.example .env
# Edit .env as needed
docker-compose up -d
```

## Architecture

### Services
- **layervault** - PHP application (port 8080)
- **thumbnail-service** - Node.js thumbnail generator

### File Structure
```
src/
├── index.php             # Application entry point
├── Database.php          # SQLite database layer
├── STLParser.php         # STL file parser
├── ThumbnailService.php  # Thumbnail service client
├── upload.php            # File upload handler
├── templates/            # HTML templates
└── assets/               # CSS, JavaScript

thumbnail-service/        # Node.js thumbnail service
├── server.js
├── package.json
└── Dockerfile

data/                    # Database storage
uploads/                 # STL files
thumbnails/              # Generated previews
```

## Data Persistence

Data is stored in Docker volumes:
- `./data/` - SQLite database
- `./uploads/` - STL files  
- `./thumbnails/` - Generated thumbnails

## Management

### Basic Commands
```bash
# Start
docker-compose up -d

# Stop  
docker-compose down

# View logs
docker-compose logs -f layervault

# Rebuild after changes
docker-compose down
docker-compose build --no-cache  
docker-compose up -d
```

### Development
Use the included development script:
```bash
# Development with rebuild options
./dev.sh

# Options:
# --rebuild - Rebuild containers
# --clean   - Clean volumes and rebuild
```

## File Uploads

### Supported Formats
- STL files (ASCII and Binary)
- Maximum 100MB per file (configurable)

### Upload Process
1. Files uploaded via drag & drop or file browser
2. STL validation and metadata extraction
3. Thumbnail generation via Node.js service
4. Database record creation
5. Success notification (6-second duration)

### Increasing Upload Limits
```bash
# In .env file
PHP_UPLOAD_MAX_FILESIZE=500M
PHP_POST_MAX_SIZE=500M  
PHP_MEMORY_LIMIT=512M
PHP_MAX_EXECUTION_TIME=600
```

## 3D Viewer

### Features
- Dark background (#0F172A) with purple objects (#8B5CF6)
- Interactive orbit, zoom, pan controls
- Wireframe toggle, fullscreen mode, view reset
- Multiple lighting setup optimized for dark theme
- Visible control buttons with primary color background

### Controls
- **Mouse/Touch** - Rotate, zoom, pan model
- **Reset View** - Return to optimal angle
- **Wireframe** - Toggle wireframe mode  
- **Fullscreen** - Full-screen viewing

## Database

### SQLite Database
- Single file: `./data/layervault.db`
- Automatic initialization
- Includes file hash for duplicate detection

### Manual Access
```bash
# Access database
docker-compose exec layervault sqlite3 /var/www/html/data/layervault.db

# Backup
docker-compose exec layervault sqlite3 /var/www/html/data/layervault.db .dump > backup.sql

# Restore  
docker-compose exec -T layervault sqlite3 /var/www/html/data/layervault.db < backup.sql
```

## Security

- STL file validation via header detection
- Input sanitization for all user data
- Directory traversal protection
- Non-root container execution
- Configurable upload size limits

## Troubleshooting

### Common Issues

**Port in use**
```bash
# Change port in docker-compose.yml
ports:
  - "9000:80"
```

**Upload failures**
```bash
# Check PHP limits
docker-compose exec layervault php -i | grep upload

# Increase limits in .env file
```

**3D viewer not loading**
- Check browser console for JavaScript errors
- Verify STL file validity
- Clear browser cache (cache busting implemented)

**Thumbnail service issues**
```bash
# Check thumbnail service logs
docker-compose logs thumbnail-service

# Restart thumbnail service
docker-compose restart thumbnail-service
```

### Health Checks
```bash
# Check all services
docker-compose ps

# Test application
curl http://localhost:8080

# Check resource usage
docker stats
```

## Backup

### Create Backup
```bash
# Backup all data
docker run --rm \
  -v layervault_layervault-data:/data \
  -v $(pwd):/backup \
  alpine tar czf /backup/layervault-$(date +%Y%m%d).tar.gz -C /data .
```

### Restore Backup
```bash
# Stop services
docker-compose down

# Restore data  
docker run --rm \
  -v layervault_layervault-data:/data \
  -v $(pwd):/backup \
  alpine tar xzf /backup/layervault-YYYYMMDD.tar.gz -C /data

# Start services
docker-compose up -d
```

## Recent Updates

- **Dark Theme** - Complete UI overhaul with professional dark design
- **Thumbnail Service** - Automatic STL preview generation  
- **Enhanced Upload** - Improved flow with persistent notifications
- **Material Design Icons** - Consistent iconography throughout
- **File Hash Detection** - Prevents duplicate uploads
- **Simplified File Info** - Removed technical geometry/bounds sections
- **Cache Busting** - JavaScript updates load properly
- **Environment Defaults** - All variables have container defaults

## Libraries and Dependencies

### Frontend Dependencies (CDN)
- **[Three.js r128](https://threejs.org/)** - 3D rendering engine for STL visualization
- **[Material Design Icons 7.2.96](https://pictogrammers.com/library/mdi/)** - Icon library for consistent UI
- **[IBM Plex Sans](https://fonts.google.com/specimen/IBM+Plex+Sans)** - Typography via Google Fonts

### Backend Libraries
- **PHP Built-in Functions** - Native file handling, SQLite PDO, string processing
- **Custom STL Parser** - Pure PHP implementation for ASCII/Binary STL parsing

### Node.js Thumbnail Service
- **[Express](https://expressjs.com/)** - Web framework for thumbnail API
- **[@scalenc/stl-to-png](https://www.npmjs.com/package/@scalenc/stl-to-png)** - STL to PNG conversion
- **[CORS](https://www.npmjs.com/package/cors)** - Cross-origin resource sharing

### Infrastructure
- **[Docker](https://docker.com/)** - Containerization platform
- **[SQLite](https://sqlite.org/)** - Database engine
- **[Apache HTTP Server](https://httpd.apache.org/)** - Web server (via php:8.1-apache)

## Development

### Local Development
```bash
# Use development script (recommended)
./dev.sh

# Development script options
./dev.sh          # Rebuild and restart containers
./dev.sh -c       # Clean database and uploads, then rebuild
./dev.sh logs     # Show live container logs

# Manual development setup
docker-compose up -d
```

### Code Organization
- **PHP** - Pure PHP, no frameworks
- **JavaScript** - Vanilla ES6+, Three.js for 3D
- **CSS** - Custom properties, dark theme, responsive
- **Icons** - Material Design Icons via CDN

---

**Default Access:** http://localhost:8080  
**Default Limits:** 100MB uploads, 256MB PHP memory  
**Data Storage:** `./data/` (database), `./uploads/` (files), `./thumbnails/` (previews)