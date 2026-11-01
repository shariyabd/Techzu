FROM php:8.3-fpm-bookworm AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions bcmath gd intl mbstring opcache pcntl pdo_mysql zip

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-techzu.ini

WORKDIR /var/www/html

FROM base AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:24-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules

RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund || npm install --no-audit --no-fund

COPY . .

ARG VITE_APP_NAME="Techzu Support"
ARG VITE_REVERB_APP_KEY=techzu-local-key
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8080
ARG VITE_REVERB_SCHEME=http

ENV VITE_APP_NAME=$VITE_APP_NAME \
    VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY \
    VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT \
    VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME

RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/testing storage/framework/views \
        storage/logs bootstrap/cache \
 && composer dump-autoload --optimize --no-interaction \
 && npm run build \
 && rm -rf node_modules bootstrap/cache/*.php storage/logs/*.log

FROM base AS app

ENV XDG_CONFIG_HOME=/tmp/.config

COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/techzu-entrypoint

RUN sed -i 's/\r$//' /usr/local/bin/techzu-entrypoint \
 && chmod 755 /usr/local/bin/techzu-entrypoint \
 && chmod 755 /var/www/html

USER www-data

ENTRYPOINT ["techzu-entrypoint"]
CMD ["php-fpm"]

FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=build /var/www/html/public /var/www/html/public
