#!/bin/sh
set -e

export AWS_ENDPOINT_HOST=$(echo "$AWS_ENDPOINT" | sed 's|https\?://||')

if [ "$CDN_MODE" = "true" ]; then
    export VOD_PROXY_CACHE=off
else
    export VOD_PROXY_CACHE=vod_cache
fi

FILTER_VARS='$AWS_ACCESS_KEY_ID $AWS_SECRET_ACCESS_KEY $AWS_DEFAULT_REGION $AWS_ENDPOINT $AWS_ENDPOINT_HOST $AWS_BUCKET $VOD_CACHE_MAX_SIZE $VOD_CACHE_INACTIVE $VOD_PROXY_CACHE'
envsubst "$FILTER_VARS" < /usr/local/nginx/conf/nginx.conf.template > /usr/local/nginx/conf/nginx.conf

exec /usr/local/nginx/sbin/nginx -g "daemon off;"
