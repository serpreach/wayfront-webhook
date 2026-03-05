FROM php:8.2-apache
COPY index.php /var/www/html/index.php
COPY webhook.php /var/www/html/webhook.php
RUN chown -R www-data:www-data /var/www/html
