ARG FFMPEG_IMAGE=mwader/static-ffmpeg:8.0.1

FROM ${FFMPEG_IMAGE} AS ffmpeg-binaries

FROM serversideup/php:8.5-cli-alpine AS worker

USER root

COPY --from=ffmpeg-binaries /ffmpeg /usr/local/bin/
COPY --from=ffmpeg-binaries /ffprobe /usr/local/bin/

ARG USER_ID=1000
ARG GROUP_ID=1000

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID

USER www-data

CMD [ "php", "/var/www/html/artisan", "queue:work", "--queue=streams", "--timeout=3200"]