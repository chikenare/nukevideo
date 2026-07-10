#!/bin/sh
set -e

export AWS_ENDPOINT_HOST=$(echo "$AWS_ENDPOINT" | sed 's|https\?://||')

# Token signing windows (consumed by nginx-secure-token-module). Defaulted so envsubst never
# produces an empty directive value when the node didn't set them.
export SECURE_TOKEN_EXPIRES_TIME="${SECURE_TOKEN_EXPIRES_TIME:-100d}"
export SECURE_TOKEN_QUERY_EXPIRES_TIME="${SECURE_TOKEN_QUERY_EXPIRES_TIME:-1h}"

FILTER_VARS='$AWS_ACCESS_KEY_ID $AWS_SECRET_ACCESS_KEY $AWS_DEFAULT_REGION $AWS_ENDPOINT $AWS_ENDPOINT_HOST $AWS_BUCKET $VOD_CACHE_MAX_SIZE $VOD_CACHE_INACTIVE $VOD_TOKEN_SECRET $SECURE_TOKEN_EXPIRES_TIME $SECURE_TOKEN_QUERY_EXPIRES_TIME'
envsubst "$FILTER_VARS" < /usr/local/nginx/conf/nginx.conf.template > /usr/local/nginx/conf/nginx.conf

exec /usr/local/nginx/sbin/nginx -g "daemon off;"
