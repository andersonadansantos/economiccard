FROM php:8.2-apache

LABEL maintainer="andersonadansantos"
LABEL description="Economic Card - Landing Page"

# Habilita mod_rewrite (necessario para o .htaccess do app) e AllowOverride
RUN a2enmod rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]

