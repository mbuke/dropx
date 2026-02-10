# Use PHP CLI for API-only app
FROM php:8.4-cli

# Install PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Copy your app
COPY . /app
WORKDIR /app

# Expose port 8080
EXPOSE 8080

# Start PHP built-in server on port 8080
CMD php -S 0.0.0.0:8080 -t .
