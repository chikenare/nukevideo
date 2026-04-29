ARG PHP_FPM_IMAGE=serversideup/php:8.5-fpm-nginx
ARG PHP_FRANKEN_IMAGE=serversideup/php:8.5-frankenphp

FROM alpine:3.20 AS ffmpeg-builder

ARG TARGETARCH

RUN apk add --no-cache curl tar xz && \
    if [ "$TARGETARCH" = "arm64" ]; then \
    FFMPEG_ARCH="linuxarm64"; \
    else \
    FFMPEG_ARCH="linux64"; \
    fi && \
    FFMPEG_URL="https://github.com/BtbN/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-${FFMPEG_ARCH}-gpl.tar.xz" && \
    curl -fSL "$FFMPEG_URL" -o /tmp/ffmpeg.tar.xz && \
    tar -xJf /tmp/ffmpeg.tar.xz -C /tmp && \
    mv /tmp/ffmpeg-master-latest-*/bin/ffmpeg /usr/local/bin/ffmpeg && \
    mv /tmp/ffmpeg-master-latest-*/bin/ffprobe /usr/local/bin/ffprobe && \
    chmod +x /usr/local/bin/ffmpeg /usr/local/bin/ffprobe && \
    rm -rf /tmp/ffmpeg*

# --- PHP base with common user setup ---
FROM ${PHP_FPM_IMAGE} AS php-base

USER root

COPY --from=ffmpeg-builder /usr/local/bin/ffprobe /usr/local/bin/

ARG USER_ID=1000
ARG GROUP_ID=1000

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID



# --- API dev ---
FROM php-base AS api-dev

RUN apt-get update && apt-get install -y --no-install-recommends openssh-client && rm -rf /var/lib/apt/lists/*

USER www-data

# --- API build ---
FROM php-base AS api-build

ARG APP_VERSION=dev

USER root

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --prefer-dist \
    --no-interaction \
    --no-scripts

COPY --chown=www-data:www-data . .

ENV APP_VERSION=${APP_VERSION}

RUN mkdir -p bootstrap/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache/data \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

RUN php artisan package:discover

# --- Worker ---
FROM api-build AS worker

ENV AUTORUN_ENABLED=true
ENV AUTORUN_LARAVEL_MIGRATION=false
ENV AUTORUN_LARAVEL_STORAGE_LINK=false
ENV AUTORUN_LARAVEL_VIEW_CACHE=false

USER root

COPY --from=ffmpeg-builder /usr/local/bin/ffmpeg /usr/local/bin/

HEALTHCHECK --interval=5s --timeout=3s --start-period=10s --retries=3 \
    CMD ["healthcheck-queue"]

USER www-data

# --- API prod ---
FROM ${PHP_FRANKEN_IMAGE} AS api-prod

USER root

COPY --from=ffmpeg-builder /usr/local/bin/ffprobe /usr/local/bin/

ARG USER_ID=1000
ARG GROUP_ID=1000

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID

RUN apt-get update && apt-get install -y --no-install-recommends openssh-client && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=api-build --chown=www-data:www-data /var/www/html /var/www/html

COPY docker/entrypoint.d/99-laravel-autorun.sh /etc/entrypoint.d/99-laravel-autorun.sh

USER www-data


FROM alpine:3.20 AS proxy-builder

ENV NGINX_VERSION=1.27.4
ENV NGINX_VOD_MODULE_VERSION=1.33
ENV NGINX_AWS_AUTH_VERSION=1.1
ENV NGINX_SECURE_TOKEN_VERSION=1.5
ENV NGINX_AKAMAI_TOKEN_VALIDATE_VERSION=1.1

RUN apk add --no-cache \
    wget ca-certificates build-base zlib-dev openssl-dev \
    pcre-dev libxml2-dev libxslt-dev linux-headers libaio-dev

RUN wget https://nginx.org/download/nginx-${NGINX_VERSION}.tar.gz -O nginx.tar.gz && \
    tar zxf nginx.tar.gz && \
    wget https://github.com/kaltura/nginx-vod-module/archive/${NGINX_VOD_MODULE_VERSION}.tar.gz -O vod.tar.gz && \
    tar zxf vod.tar.gz && \
    wget https://github.com/kaltura/nginx-aws-auth-module/archive/${NGINX_AWS_AUTH_VERSION}.tar.gz -O aws.tar.gz && \
    tar zxf aws.tar.gz && \
    wget https://github.com/kaltura/nginx-secure-token-module/archive/${NGINX_SECURE_TOKEN_VERSION}.tar.gz -O nsm.tar.gz && \
    tar zxf nsm.tar.gz && \
    wget https://github.com/kaltura/nginx-akamai-token-validate-module/archive/${NGINX_AKAMAI_TOKEN_VALIDATE_VERSION}.tar.gz -O natvm.tar.gz && \
    tar zxf natvm.tar.gz

ARG TARGETARCH
RUN if [ "$TARGETARCH" = "amd64" ]; then CC_OPT="-O3 -mpopcnt"; else CC_OPT="-O3"; fi && \
    cd nginx-${NGINX_VERSION} && \
    ./configure \
    --prefix=/usr/local/nginx \
    --add-module=../nginx-vod-module-${NGINX_VOD_MODULE_VERSION} \
    --add-module=../nginx-aws-auth-module-${NGINX_AWS_AUTH_VERSION} \
    --add-module=../nginx-secure-token-module-${NGINX_SECURE_TOKEN_VERSION} \
    --add-module=../nginx-akamai-token-validate-module-${NGINX_AKAMAI_TOKEN_VALIDATE_VERSION} \
    --conf-path=/usr/local/nginx/conf/nginx.conf \
    --with-file-aio \
    --with-threads \
    --with-http_ssl_module \
    --with-http_secure_link_module \
    --with-http_realip_module \
    --with-cc-opt="$CC_OPT" && \
    make && make install

FROM alpine:3.20 AS proxy-prod

RUN apk add --no-cache \
    ca-certificates \
    openssl \
    pcre \
    zlib \
    libxml2 \
    libxslt \
    ffmpeg \
    gettext \
    libaio

COPY --from=proxy-builder /usr/local/nginx /usr/local/nginx
RUN mkdir -p /var/cache/nginx/vod

ENV VOD_SEGMENT_DURATION=10000
ENV VOD_METADATA_CACHE_SIZE=1024m
ENV VOD_RESPONSE_CACHE_SIZE=128m
ENV API_UPSTREAM_HOST=nukevideo-api:8080
ENV API_UPSTREAM_PROTO=http

ENV SECURE_TOKEN_EXPIRES_TIME=100d
ENV SECURE_TOKEN_QUERY_EXPIRES_TIME=1h

ENV VOD_CACHE_MAX_SIZE=10g
ENV VOD_CACHE_INACTIVE=15d

COPY vod/nginx/nginx.conf.template /usr/local/nginx/conf/nginx.conf.template
COPY vod/nginx/cloudflare-realip.conf /usr/local/nginx/conf/cloudflare-realip.conf
COPY vod/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]


# --- Node base ---
FROM node:24-alpine AS node-base

ENV PNPM_HOME="/pnpm"
ENV PATH="$PNPM_HOME:$PATH"
RUN corepack enable && \
    mkdir -p /pnpm && chown node:node /pnpm

WORKDIR /app
RUN chown node:node /app

FROM node-base AS docs

USER node

EXPOSE 5173

CMD ["pnpm", "run", "docs:dev"]

# --- Front dev ---
FROM node-base AS front-dev

USER node

EXPOSE 5173

CMD ["sh", "-c", "pnpm install && pnpm run dev"]

# --- Front build ---
FROM node-base AS front-build

COPY pnpm-lock.yaml package.json ./

RUN pnpm install --frozen-lockfile

COPY . .
RUN pnpm run build

# --- Front prod ---
FROM nginx:stable-alpine AS front-prod

COPY --from=front-build /app/dist /usr/share/nginx/html

CMD ["nginx", "-g", "daemon off;"]