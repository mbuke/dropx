# Use PHP CLI for API-only app
FROM php:8.4-cli

# Install PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql

COPY . /app
WORKDIR /app 

RUN echo '#!/bin/sh' > /docker-entrypoint.sh && \
    echo 'exec php -S 0.0.0.0:${PORT:-8080} -t .' >> /docker-entrypoint.sh && \
    chmod +x /docker-entrypoint.sh

# Use the entrypoint (note the exact path)
ENTRYPOINT ["/docker-entrypoint.sh"]