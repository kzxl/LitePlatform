# High-Performance Sovereign PHP Container with FrankenPHP
FROM dunglas/frankenphp:1-php8.2-alpine

# Set Caddy / FrankenPHP environment
ENV SERVER_NAME=":80"
ENV FRANKENPHP_CONFIG="worker ./public/index.php"

# Install production PHP extensions
RUN install-php-extensions \
    pdo_sqlite \
    pdo_mysql \
    pdo_pgsql \
    opcache

# Set application directory
WORKDIR /app

# Copy application sources
COPY . /app

# Configure production OpCache
RUN echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini && \
    echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini && \
    echo "opcache.interned_strings_buffer=16" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini && \
    echo "opcache.max_accelerated_files=10000" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini

# Expose standard HTTP and HTTPS ports
EXPOSE 80 443 443/udp

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
