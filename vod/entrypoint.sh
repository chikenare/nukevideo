#!/bin/sh
set -e

if [ "$APP_ENV" = "local" ]; then
    export API_UPSTREAM="http://${API_UPSTREAM_HOST}"
else
    export API_UPSTREAM="https://${API_UPSTREAM_HOST}"
fi

export AWS_ENDPOINT_HOST=$(echo "$AWS_ENDPOINT" | sed 's|https\?://||')

if [ "$CDN_MODE" = "true" ]; then
    export VOD_PROXY_CACHE=off
else
    export VOD_PROXY_CACHE=vod_cache
fi

FILTER_VARS='$VOD_SEGMENT_DURATION $VOD_METADATA_CACHE_SIZE $VOD_RESPONSE_CACHE_SIZE $VOD_BASE_URL $API_UPSTREAM $API_UPSTREAM_HOST $AWS_ACCESS_KEY_ID $AWS_SECRET_ACCESS_KEY $AWS_DEFAULT_REGION $AWS_ENDPOINT $AWS_ENDPOINT_HOST $AWS_BUCKET $VOD_TOKEN_SECRET $SECURE_TOKEN_EXPIRES_TIME $SECURE_TOKEN_QUERY_EXPIRES_TIME $VOD_CACHE_MAX_SIZE $VOD_CACHE_INACTIVE $VOD_PROXY_CACHE'
envsubst "$FILTER_VARS" < /usr/local/nginx/conf/nginx.conf.template > /usr/local/nginx/conf/nginx.conf

exec /usr/local/nginx/sbin/nginx -g "daemon off;"
