FROM serversideup/php:8.5-fpm-nginx-alpine

ENV PHP_MEMORY_LIMIT=-1

USER root
RUN apk add ffmpeg openssh --no-cache

USER www-data
