# Use PHP CLI for API-only app
FROM php:8.4-cli

# Install PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Copy your app
COPY . /app
WORKDIR /app

# Expose port (Railway will use $PORT env var)
EXPOSE 8080

# Use ENTRYPOINT instead of CMD for Railway compatibility
ENTRYPOINT ["php", "-S", "0.0.0.0:${PORT:-8080}", "-t", "."]