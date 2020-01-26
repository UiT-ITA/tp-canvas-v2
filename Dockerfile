FROM php:7.4-cli
COPY app/ /app
WORKDIR /app
ENTRYPOINT [ "php", "tp-canvas-v2.php" ]
