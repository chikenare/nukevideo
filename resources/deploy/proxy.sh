# A proxy node: the cache pool, the edge container, and Traefik in front of it in production.

echo "=== Proxy image ==="
pull_image "$IMAGE"

# Where the edge caches: the pool's directory, or the fallback docker volume when the host has
# no pool — and only then is the cache capped, because a volume shares the OS disk. With a
# pool the entrypoint sizes the cache to it, and CACHE_EXPECT_POOL tells it a pool is what it
# was given, so a container restarted on a host that booted without the pool (`nofail`) caps
# itself instead of sizing the cache to the OS disk. All three are read by RUN_ARGS.
CACHE_MOUNT=""
CACHE_MAX_SIZE=""
CACHE_EXPECT_POOL=""
provision_cache_pool
if [ -z "$CACHE_MOUNT" ]; then
    CACHE_MOUNT=$CACHE_VOLUME
    CACHE_MAX_SIZE=$CACHE_FALLBACK_MAX_SIZE
else
    CACHE_EXPECT_POOL=1
fi

echo "=== Deploying proxy ==="
# Stop before removing, so nginx finishes the requests it is serving instead of cutting them.
# The image is already pulled, so the gap is nginx's startup: a second or two, which players
# absorb with their buffer and segment retries.
docker stop "$SERVICE_CONTAINER" 2>/dev/null || true
docker rm -f "$SERVICE_CONTAINER" 2>/dev/null || true
run_container "$RUN_ARGS"

# A proxy that does not answer /healthz fails the deploy — and with it a fleet deploy's chain,
# before the next proxy is touched. wget is busybox's, the image is alpine.
echo "Waiting for the edge to answer..."
n=0
until docker exec "$SERVICE_CONTAINER" wget -q -O /dev/null http://127.0.0.1/healthz 2>/dev/null; do
    n=$((n+1)); [ $n -ge 30 ] && { echo "Edge did not come up"; docker logs --tail 50 "$SERVICE_CONTAINER"; exit 1; }
    sleep 2
done
echo "Edge is up"

# Traefik fronts the edge unless something else already holds port 80 on this host — a reverse
# proxy of the operator's own, which then routes by the same labels. Ours is recognised by name
# and left alone when it already runs with exactly this configuration (the hash the API puts in
# its `nukevideo.config` label): replacing it drops TLS on the host for a few seconds, the
# longest gap of any proxy deploy.
echo "=== Traefik ==="
if docker ps --format '{{.Names}} {{.Ports}}' | grep -v '^nukevideo_traefik ' | grep -q ':80->'; then
    echo "Port 80 is held by another container — leaving the host's own reverse proxy in front"
elif [ "$(docker inspect -f '{{.State.Running}} {{index .Config.Labels "nukevideo.config"}}' nukevideo_traefik 2>/dev/null)" = "true $TRAEFIK_CONFIG" ]; then
    echo "Traefik already running this configuration — kept"
else
    pull_image "$TRAEFIK_IMAGE"
    docker rm -f nukevideo_traefik 2>/dev/null || true
    run_container "$TRAEFIK_RUN_ARGS"
    echo "Traefik deployed"
fi
