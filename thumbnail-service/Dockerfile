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
    curl

# Create app directory
WORKDIR /app

# Copy package files
COPY package*.json ./

# Install dependencies
RUN npm install --only=production

# Copy application code
COPY server.js ./

# Create required directories
RUN mkdir -p /tmp/stl-uploads /tmp/thumbnails

# Create non-root user
RUN addgroup -g 1001 -S thumbnail && \
    adduser -S -D -H -u 1001 -s /sbin/nologin -G thumbnail thumbnail

# Set ownership
RUN chown -R thumbnail:thumbnail /app /tmp/stl-uploads /tmp/thumbnails

# Switch to non-root user
USER thumbnail

# Expose port
EXPOSE 3000

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=15s --retries=3 \
    CMD curl -f http://localhost:3000/health || exit 1

# Start the service
CMD ["node", "server.js"]