#!/bin/sh
set -e

export AWS_ENDPOINT_HOST=$(echo "$AWS_ENDPOINT" | sed 's|https\?://||')

# Token signing windows (consumed by nginx-secure-token-module). Defaulted so envsubst never
# produces an empty directive value when the node didn't set them.
export SECURE_TOKEN_EXPIRES_TIME="${SECURE_TOKEN_EXPIRES_TIME:-100d}"
export SECURE_TOKEN_QUERY_EXPIRES_TIME="${SECURE_TOKEN_QUERY_EXPIRES_TIME:-1h}"

# The query argument carrying the token. Must match what the API signs with; the default is the
# Akamai name the signer also defaults to, so a node that predates this variable keeps working.
export VOD_TOKEN_NAME="${VOD_TOKEN_NAME:-__hdnea__}"

# ---- Cache sizing ----
#
# The cache is sized to the filesystem it sits on, not configured: a proxy node's deploy hands
# this container a pool of disks that exist for nothing else. Three numbers come out of `df`:
#
#  - max_size:  everything but a reserve. nginx's cache manager evicts by LRU to stay under it.
#  - min_free:  the reserve itself. max_size only counts what the manager knows about — it is
#               blind to misses still streaming into temp files (use_temp_path=off puts those in
#               the cache dir) and, for hours after a restart of a big cache, to everything the
#               loader has not indexed yet. min_free is measured with statfs, so it holds in both
#               windows. Running the filesystem out of space is the one thing that must not
#               happen: a failed write truncates the viewer's segment, and everyone waiting on
#               the same miss behind proxy_cache_lock gets the same truncated bytes.
#  - keys_zone: ~8k keys per MB (nginx docs). A segment is a few MB, so a 10 TB pool holds
#               millions of them, and the stock 20m would force-expire entries long before the
#               disk was used. Sized at 2 MB per object on average; a cache of smaller objects
#               simply fills the zone first and evicts from there, which is still LRU.
#
# VOD_CACHE_MAX_SIZE set is the exception: the deploy sets it when the cache has no pool and is a
# docker volume on the OS disk, which the edge must not be allowed to fill. Sizes use `g`/`m`:
# nginx does not parse a `t` suffix.
CACHE_DIR=/var/cache/nginx/vod

# The deploy that mounted a pool into CACHE_DIR leaves a marker file at its root and says so
# with VOD_CACHE_EXPECT_POOL=1. The pool is mounted `nofail`, so a host can boot without it and
# the bind mount then hands this container the empty mount point directory on the OS disk — and
# sizing the cache to *that* filesystem would let the edge fill the OS disk. Missing marker with
# the flag set means exactly that: say so loudly and cap the cache at the fallback size instead.
# The marker is a hidden file, not a cache entry: nginx's cache loader only indexes files whose
# names are hex keys under the `levels` directories and ignores anything else in the root.
# The cap mirrors App\Services\ProxyCacheService::FALLBACK_MAX_SIZE, what a host with no pool
# at all gets.
POOL_MARKER="$CACHE_DIR/.nukevideo-pool"
POOL_FALLBACK_MAX_SIZE=10g
if [ "${VOD_CACHE_EXPECT_POOL:-}" = "1" ] && [ ! -e "$POOL_MARKER" ]; then
    echo "WARNING: cache pool NOT mounted: $POOL_MARKER is missing, so $CACHE_DIR is the OS disk. Capping the cache at $POOL_FALLBACK_MAX_SIZE; mount the pool and restart the edge." >&2
    export VOD_CACHE_MAX_SIZE="$POOL_FALLBACK_MAX_SIZE"
fi
TOTAL_KB=$(df -Pk "$CACHE_DIR" | awk 'NR==2 { print $2 }')
TOTAL_MB=$((TOTAL_KB / 1024))

RESERVE_MB=$((TOTAL_MB * 3 / 100))
[ "$RESERVE_MB" -lt 20480 ] && RESERVE_MB=20480

if [ -z "$VOD_CACHE_MAX_SIZE" ]; then
    if [ "$TOTAL_MB" -gt $((RESERVE_MB * 2)) ]; then
        export VOD_CACHE_MAX_SIZE="$((TOTAL_MB - RESERVE_MB))m"
    else
        # A filesystem too small for the reserve to make sense: cap at half and keep the rest.
        export VOD_CACHE_MAX_SIZE="$((TOTAL_MB / 2))m"
        RESERVE_MB=$((TOTAL_MB / 4))
    fi
fi
export VOD_CACHE_MIN_FREE="${VOD_CACHE_MIN_FREE:-${RESERVE_MB}m}"

KEYS_MB=$((TOTAL_MB / 2 / 8000))
[ "$KEYS_MB" -lt 20 ] && KEYS_MB=20
export VOD_CACHE_KEYS_ZONE="${VOD_CACHE_KEYS_ZONE:-${KEYS_MB}m}"

# Not "30 days since it was last played" by accident: inactive is the only thing besides
# max_size that deletes, and a cache that is meant to hold the long tail must not be emptied by
# the calendar. Eviction is the LRU's job.
export VOD_CACHE_INACTIVE="${VOD_CACHE_INACTIVE:-30d}"

echo "cache: $CACHE_DIR ${TOTAL_MB}MB total, max_size=$VOD_CACHE_MAX_SIZE min_free=$VOD_CACHE_MIN_FREE keys_zone=$VOD_CACHE_KEYS_ZONE inactive=$VOD_CACHE_INACTIVE"

# The node id only identifies the edge to the health probe; an edge run by hand has none.
export NODE_ID="${NODE_ID:-0}"

FILTER_VARS='$NODE_ID $AWS_ACCESS_KEY_ID $AWS_SECRET_ACCESS_KEY $AWS_DEFAULT_REGION $AWS_ENDPOINT $AWS_ENDPOINT_HOST $AWS_BUCKET $VOD_CACHE_MAX_SIZE $VOD_CACHE_MIN_FREE $VOD_CACHE_KEYS_ZONE $VOD_CACHE_INACTIVE $VOD_TOKEN_SECRET $SECURE_TOKEN_EXPIRES_TIME $SECURE_TOKEN_QUERY_EXPIRES_TIME $VOD_TOKEN_NAME'
envsubst "$FILTER_VARS" < /usr/local/nginx/conf/nginx.conf.template > /usr/local/nginx/conf/nginx.conf

/usr/local/nginx/sbin/nginx -t

exec /usr/local/nginx/sbin/nginx -g "daemon off;"
