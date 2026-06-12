FROM php:8.2-apache

RUN a2enmod rewrite headers

COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads/leases \
             /var/www/html/uploads/deposits \
             /var/www/html/uploads/qrcodes \
             /var/www/html/uploads/properties \
             /var/www/html/sessions \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/uploads \
    && chmod -R 755 /var/www/html/sessions

RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

EXPOSE 80
