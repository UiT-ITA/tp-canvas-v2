FROM php:7.4-cli
COPY app/ /app
WORKDIR /app
CMD [ "php", "./test.php" ]
