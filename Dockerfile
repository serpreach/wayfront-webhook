FROM php:8.2-apache
COPY index.php /var/www/html/index.php
COPY webhook.php /var/www/html/webhook.php
COPY send_email.php /var/www/html/send_email.php
RUN chown -R www-data:www-data /var/www/html
