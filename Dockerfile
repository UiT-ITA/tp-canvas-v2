FROM php:7.4-cli

# Ensure PDO has the driver we need
RUN docker-php-ext-install pdo_mysql sockets

# Use the default production configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Override with custom settings
COPY tp-canvas-php.ini $PHP_INI_DIR/conf.d/

# Install composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Set up app
COPY app/ /app
WORKDIR /app

# Ensure composer packages are in place
RUN composer install -d /app

# Run
# ENTRYPOINT [ "php", "test.php" ]
ENTRYPOINT [ "php", "tp-canvas-v2.php" ]
