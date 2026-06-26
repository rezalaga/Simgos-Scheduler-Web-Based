FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql

RUN a2enmod rewrite

RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    && docker-php-ext-install curl

RUN echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/memory.ini
RUN echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/time.ini
