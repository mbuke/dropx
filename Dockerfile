# Use PHP + Apache base image
FROM php:8.4-apache

# Install PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache rewrite module
RUN a2enmod rewrite

# Copy your app
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Set working directory
WORKDIR /var/www/html

# Expose dynamic port for Railway
CMD php -S 0.0.0.0:$PORT -t .

