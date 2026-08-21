FROM php:8.2-apache

LABEL maintainer="andersonadansantos"
LABEL description="Economic Card - Landing Page"

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
