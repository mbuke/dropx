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

# Configure Apache to use Railway port dynamically
RUN sed -i "s/80/${PORT}/g" /etc/apache2/ports.conf /etc/apache2/sites-enabled/000-default.conf

# Apache will start automatically; no CMD needed
