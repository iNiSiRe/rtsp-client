FROM php:7.3

ENV REFRESHED_AT 2018-03-15

# install additional soft
RUN export DEBIAN_FRONTEND=noninteractive && \
    apt-get -qq update && \
    apt-get -y install zip unzip git zlib1g-dev libmemcached-dev libevent-dev make libssl-dev inetutils-ping

# install extensions
RUN docker-php-ext-install pdo_mysql

RUN pecl install memcached-3.1.3
RUN docker-php-ext-enable memcached

RUN docker-php-ext-install sockets
RUN docker-php-ext-enable sockets

RUN pecl install event
RUN docker-php-ext-enable event

# install composer
ENV COMPOSER_HOME=/tmp/.composer

RUN curl -XGET https://getcomposer.org/installer > composer-setup.php && \
    php composer-setup.php --install-dir=/bin --filename=composer && \
    rm composer-setup.php

ARG ENABLE_XDEBUG=0

RUN if [ "$ENABLE_XDEBUG" -eq 1 ]; then \
    pecl install xdebug-2.6.1 && \
    docker-php-ext-enable xdebug; \
fi

# OpenCV
RUN apt-get -y install wget build-essential cmake libgtk2.0-dev pkg-config libavcodec-dev libavformat-dev libswscale-dev libjpeg-dev libpng-dev

RUN wget https://github.com/opencv/opencv/archive/4.1.0.zip

RUN unzip 4.1.0.zip \
    && cd opencv-4.1.0 \
    && mkdir build \
    && cd build \
    && cmake -D OPENCV_GENERATE_PKGCONFIG=YES \
        -D CMAKE_BUILD_TYPE=Release \
        -D CMAKE_INSTALL_PREFIX=/usr/local .. \
    && make -j7 \
    && make install

RUN git clone https://github.com/php-opencv/php-opencv.git \
    && docker-php-ext-configure /php-opencv \
    && docker-php-ext-install /php-opencv

# Install GD
RUN apt-get update && apt-get install -y \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
    && docker-php-ext-install -j$(nproc) iconv \
    && docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/ \
    && docker-php-ext-install -j$(nproc) gd


RUN mkdir -p /var/www/html && \
    chown -R www-data:www-data /var/www/html && \
    chown -R www-data:www-data /tmp/.composer

ARG UID=1000
ARG GID=1000

RUN groupmod -g $GID www-data && \
    usermod -u $UID www-data

EXPOSE 8080
EXPOSE 8000

USER root

WORKDIR /var/www