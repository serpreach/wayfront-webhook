FROM php:8.2-apache
RUN docker-php-ext-install openssl
COPY index.php /var/www/html/index.php
RUN chown -R www-data:www-data /var/www/html
