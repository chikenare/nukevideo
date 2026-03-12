ARG FFMPEG_IMAGE=mwader/static-ffmpeg:8.0.1
ARG PHP_CLI_IMAGE=serversideup/php:8.5-cli-alpine
ARG PHP_FPM_IMAGE=serversideup/php:8.5-fpm-nginx-alpine

FROM ${FFMPEG_IMAGE} AS ffmpeg-binaries

# --- PHP base with common user setup ---
FROM ${PHP_FPM_IMAGE} AS php-base

USER root

ARG USER_ID=1000
ARG GROUP_ID=1000

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID && \
    apk add --no-cache openssh-client


# --- API dev ---
FROM php-base AS api-dev

USER www-data

# --- API build ---
FROM php-base AS api-build

USER root

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction

COPY --chown=www-data:www-data . .

# --- Worker ---
FROM api-build AS worker

USER root

COPY --from=ffmpeg-binaries /ffmpeg /usr/local/bin/
COPY --from=ffmpeg-binaries /ffprobe /usr/local/bin/

COPY --chown=www-data:www-data . .

USER www-data

CMD ["php", "/var/www/html/artisan", "queue:work", "--queue=streams", "--timeout=3200"]

# --- API prod ---
FROM api-build AS api-prod

USER www-data


FROM alpine:3.20 AS proxy-builder

ENV NGINX_VERSION=1.27.4
ENV NGINX_VOD_MODULE_VERSION=1.33
ENV NGINX_AWS_AUTH_VERSION=1.1
ENV NGINX_SECURE_TOKEN_VERSION=1.5
ENV NGINX_AKAMAI_TOKEN_VALIDATE_VERSION=1.1

RUN apk add --no-cache \
    wget ca-certificates build-base zlib-dev openssl-dev \
    pcre-dev libxml2-dev libxslt-dev linux-headers

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

RUN cd nginx-${NGINX_VERSION} && \
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
    --with-cc-opt="-O3" && \
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
    gettext

COPY --from=proxy-builder /usr/local/nginx /usr/local/nginx

ENV VOD_SEGMENT_DURATION=10000
ENV VOD_METADATA_CACHE_SIZE=512m
ENV VOD_RESPONSE_CACHE_SIZE=128m
ENV VOD_MAPPING_CACHE_SIZE=5m
ENV API_UPSTREAM_HOST=nukevideo-api:8080
ENV API_UPSTREAM_PROTO=http

ENV SECURE_TOKEN_EXPIRES_TIME=100d
ENV SECURE_TOKEN_QUERY_EXPIRES_TIME=1h

COPY vod/nginx/nginx.conf.template /usr/local/nginx/conf/nginx.conf.template
COPY vod/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]


# --- Node base ---
FROM node:24-alpine AS node-base

ENV PNPM_HOME="/pnpm"
ENV PATH="$PNPM_HOME:$PATH"
RUN corepack enable

WORKDIR /app

# --- Front dev ---
FROM node-base AS front-dev

EXPOSE 5173

CMD ["pnpm", "run", "dev"]

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