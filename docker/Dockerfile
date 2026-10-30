FROM php:8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install mysqli zip && docker-php-ext-enable mysqli zip \
    && a2enmod rewrite headers \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf
RUN printf "upload_max_filesize=20M\npost_max_size=25M\nmemory_limit=256M\nmax_execution_time=300\ndate.timezone=Asia/Karachi\n" > /usr/local/etc/php/conf.d/app.ini
