FROM php:8.3-apache

# pdo_mysql: baza; zip: eksport ZIP z okładkami; curl/json są w obrazie domyślnie.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install pdo_mysql zip \
    && a2enmod headers \
    && rm -rf /var/lib/apt/lists/*

# Aplikacja jest serwowana z /var/www/html (DocumentRoot obrazu php:apache).
COPY --chown=www-data:www-data . /var/www/html/

# uploads/ w obrazie ma tylko .htaccess; katalogi robocze tworzy entrypoint
# (wolumen montowany w tym miejscu przykrywa to, co jest w obrazie).
RUN chown -R www-data:www-data /var/www/html/uploads

COPY docker/entrypoint.sh /usr/local/bin/biblioteka-entrypoint
RUN chmod +x /usr/local/bin/biblioteka-entrypoint

ENTRYPOINT ["biblioteka-entrypoint"]
CMD ["apache2-foreground"]
