# Start from a base image that includes NGINX and PHP-FPM
# This image is widely used for running standard PHP applications.
FROM php:8.2-fpm-alpine

# Install PostgreSQL PHP extension (pdo_pgsql)
# This is necessary because your application connects to a PostgreSQL database.
RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Set the working directory to /var/www/html (standard web root)
WORKDIR /var/www/html

# Copy all your application files from your GitHub repo into the container
COPY . /var/www/html

# Expose the standard port for PHP-FPM
EXPOSE 9000

# Command to run PHP-FPM when the container starts
CMD ["php-fpm"]
