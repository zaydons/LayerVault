FROM node:18-alpine

# Install dependencies for 3D rendering and health checks
RUN apk add --no-cache \
    python3 \
    make \
    g++ \
    cairo-dev \
    jpeg-dev \
    pango-dev \
    giflib-dev \
    librsvg-dev \
    pixman-dev \
    curl

# Create app directory
WORKDIR /app

# Copy package files
COPY package*.json ./

# Install dependencies
RUN npm install --only=production --verbose

# Copy application code
COPY server.js ./

# Create required directories
RUN mkdir -p /app/uploads /app/thumbnails && \
    chmod 755 /app/uploads /app/thumbnails

# Expose port
EXPOSE 3000

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=15s --retries=3 \
    CMD curl -f http://localhost:3000/health || exit 1

# Start the service
CMD ["node", "server.js"]
