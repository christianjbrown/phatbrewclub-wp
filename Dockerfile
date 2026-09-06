# WordPress for Cloud Run.
#
# php:8.4-apache rather than the official `wordpress:` image, because that image's
# entrypoint copies core into the webroot at container start and writes a
# wp-config.php if it does not find one. Both assume a persistent volume that
# Cloud Run does not have: the copy is dead weight on every cold start, and the
# generated config would quietly disagree with the one in this repo. Core comes
# from Composer instead, pinned in composer.lock.
#
# mod_php rather than php-fpm behind nginx: two processes need a supervisor in a
# container that receives one signal, and there is nothing to gain here because
# the media is served straight from a bucket and never touches this container.
FROM php:8.4-apache-bookworm AS base

# mysqli is not in the base image. gd is built with WebP and JPEG because the
# derivative ladder is WebP, and without the WebP flag the sub-sizes silently
# come out as the input format and the whole transfer-size comparison measures
# this Dockerfile rather than the CMS.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev libzip-dev unzip; \
    docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype; \
    docker-php-ext-install -j"$(nproc)" mysqli gd zip exif opcache; \
    a2enmod rewrite headers; \
    rm -rf /var/lib/apt/lists/*

COPY docker/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/uploads.ini /usr/local/etc/php/conf.d/zz-uploads.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/ports.conf /etc/apache2/ports.conf

FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
# --no-dev: the style and analysis tooling has no business in a runtime image.
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts

FROM base AS runtime

WORKDIR /var/www/html

COPY --from=vendor /build/vendor /var/www/html/vendor
COPY --from=vendor /build/public/wp /var/www/html/public/wp
COPY public/index.php public/wp-config.php /var/www/html/public/
COPY public/wp-content /var/www/html/public/wp-content
COPY src /var/www/html/src
COPY docker/wp-cli.yml /var/www/wp-cli.yml

# Fails the build if a mirror or a dependency has altered a core file. Cheap,
# and the alternative is finding out from the site.
RUN php vendor/bin/wp core verify-checksums --path=public/wp --allow-root

# The uploads directory is a Cloud Storage bucket mounted at runtime. It exists
# here only so the path resolves when nothing is mounted, which is what happens
# in local development and in CI.
RUN mkdir -p public/wp-content/uploads && chown -R www-data:www-data public/wp-content

ENV WP_DEBUG=false

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/wp/wp-includes/version.php") === false ? 1 : 0);'

CMD ["apache2-foreground"]
