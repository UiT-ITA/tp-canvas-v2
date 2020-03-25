FROM php:7.4-cli

# Packages
RUN apt-get update && apt-get install -y \
        zip

# Ensure PDO has the driver we need
RUN docker-php-ext-install pdo_mysql sockets

# Use the default production configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Override with custom settings
COPY tp-canvas-php.ini $PHP_INI_DIR/conf.d/

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set up app
COPY app/ /app
WORKDIR /app

# Ensure composer packages are in place
ARG GITHUBOAUTH
RUN composer config -g github-oauth.github.com ${GITHUBOAUTH}
RUN composer install -d /app

# Run
# ENTRYPOINT [ "php", "test.php" ]
ENTRYPOINT [ "php", "tp-canvas-v2.php" ]
