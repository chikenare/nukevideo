FROM alpine:3.20 AS builder

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

FROM alpine:3.20

RUN apk add --no-cache \
    ca-certificates \
    openssl \
    pcre \
    zlib \
    libxml2 \
    libxslt \
    ffmpeg \
    gettext

COPY --from=builder /usr/local/nginx /usr/local/nginx

ENV VOD_SEGMENT_DURATION=10000
ENV VOD_METADATA_CACHE_SIZE=512m
ENV VOD_RESPONSE_CACHE_SIZE=128m
ENV VOD_MAPPING_CACHE_SIZE=5m
ENV API_UPSTREAM_HOST=nukevideo-api:8080

COPY nginx/nginx.conf.template /usr/local/nginx/conf/nginx.conf.template
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]