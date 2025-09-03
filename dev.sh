#!/usr/bin/env bash

# LayerVault Development Script
# Usage: 
#   ./dev.sh          - Rebuild and restart containers
#   ./dev.sh -c       - Clean database, uploads, and thumbnails, then rebuild
#   ./dev.sh logs     - Show live container logs

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

show_help() {
    echo "LayerVault Development Script"
    echo ""
    echo "Usage:"
    echo "  ./dev.sh          - Rebuild and restart containers"
    echo "  ./dev.sh -c       - Clean database and uploads, then rebuild"
    echo "  ./dev.sh logs     - Show live logs"
    echo "  ./dev.sh help     - Show this help"
    echo ""
}

clean_data() {
    echo "Cleaning database and uploads..."
    
    # Stop containers
    echo "   Stopping containers..."
    sudo docker compose down
    
    # Clean uploads directory
    if [ -d "uploads" ]; then
        echo "   Removing uploaded files..."
        sudo rm -rf uploads/*
    fi
    
    # Clean thumbnails directory
    if [ -d "thumbnails" ]; then
        echo "   Removing thumbnail files..."
        sudo rm -rf thumbnails/*
    fi
    
    # Reset database
    if [ -f "data/layervault.db" ]; then
        echo "   Resetting database..."
        sudo rm -f data/layervault.db
    fi
    
    # Recreate directories with proper permissions
    mkdir -p data uploads thumbnails
    chmod 777 data uploads thumbnails
    
    echo "Clean complete!"
}

rebuild_containers() {
    echo "Rebuilding containers..."
    
    # Stop containers
    echo "   Stopping containers..."
    sudo docker compose down
    
    # Build without cache
    echo "   Building containers (no cache)..."
    sudo docker compose build --no-cache
    
    # Start containers
    echo "   Starting containers..."
    sudo docker compose up -d
    
    echo "Rebuild complete!"
}

show_logs() {
    echo "Showing live logs (Ctrl+C to exit)..."
    sudo docker compose logs -f layervault
}

wait_for_container() {
    echo "Waiting for container to be ready..."
    timeout=30
    while [ $timeout -gt 0 ]; do
        if sudo docker compose exec layervault curl -f http://localhost/ >/dev/null 2>&1; then
            echo "Container is ready!"
            echo "Access at: http://localhost:8080"
            return 0
        fi
        echo "   Waiting... ($timeout seconds remaining)"
        sleep 2
        timeout=$((timeout - 2))
    done
    echo "Container may not be ready yet"
}

# Main script logic
case "${1:-}" in
    "help"|"-h"|"--help")
        show_help
        ;;
    "logs")
        show_logs
        ;;
    "-c"|"--clean")
        clean_data
        rebuild_containers
        wait_for_container
        echo ""
        echo "LayerVault development environment reset and ready!"
        echo "Database and uploads have been cleared"
        echo "Containers rebuilt from scratch"
        echo ""
        ;;
    "")
        rebuild_containers
        wait_for_container
        echo ""
        echo "LayerVault development environment ready!"
        echo "Containers rebuilt and running"
        echo ""
        ;;
    *)
        echo "Unknown option: $1"
        show_help
        exit 1
        ;;
esac