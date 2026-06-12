FROM php:8.3-apache

# PDO MySQL extension
RUN docker-php-ext-install pdo_mysql

# Enable URL rewriting for the single front controller
RUN a2enmod rewrite

# Custom virtual host: document root -> public/, allow .htaccess overrides
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Copy the application (in dev this is also bind-mounted by docker-compose)
COPY . /var/www/html

# Apache must be able to write uploaded images
RUN chown -R www-data:www-data /var/www/html/public/assets/uploads

EXPOSE 80
