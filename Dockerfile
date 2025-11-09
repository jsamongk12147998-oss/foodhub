# 1. Use a standard PHP-FPM image with a recent version
FROM php:8.2-fpm-alpine

# 2. Install necessary system dependencies and the PostgreSQL PHP extension
# The 'pdo_pgsql' extension is mandatory to connect to your Render database.
RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo pdo_pgsql

# 3. Set the working directory (where your application code will live)
WORKDIR /var/www/html

# 4. Copy all your application files from GitHub into the container
COPY . /var/www/html

# 5. Composer is not run here, as the Render platform handles that 
# in the 'Build Command' step, allowing better integration with the build cache.

# 6. Expose the standard port for PHP-FPM (Render handles Nginx/Apache proxying)
EXPOSE 9000

# 7. Start the PHP-FPM service when the container launches
CMD ["php-fpm"]
