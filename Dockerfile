# Use PHP CLI for API-only app
FROM php:8.4-cli

# Install PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Install curl and other dependencies
RUN apt-get update && apt-get install -y \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Copy your app
COPY . /app
WORKDIR /app

# Create Railway-compatible entrypoint
RUN echo '#!/bin/sh' > /entrypoint.sh && \
    echo 'exec php -S 0.0.0.0:${PORT:-8080} -t .' >> /entrypoint.sh && \
    chmod +x /entrypoint.sh

# Use the entrypoint
ENTRYPOINT ["/entrypoint.sh"]