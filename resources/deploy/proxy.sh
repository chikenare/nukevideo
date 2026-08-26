# A proxy node: the cache pool, the edge container, and Traefik in front of it in production.

echo "=== Proxy image ==="
pull_image "$IMAGE"

# Where the edge caches: the pool's directory, or the fallback docker volume when the host has
# no pool — and only then is the cache capped, because a volume shares the OS disk. With a
# pool the entrypoint sizes the cache to it. Both variables are read by RUN_ARGS.
CACHE_MOUNT=""
CACHE_MAX_SIZE=""
provision_cache_pool
if [ -z "$CACHE_MOUNT" ]; then
    CACHE_MOUNT=$CACHE_VOLUME
    CACHE_MAX_SIZE=$CACHE_FALLBACK_MAX_SIZE
fi

echo "=== Deploying proxy ==="
docker rm -f "$SERVICE_CONTAINER" 2>/dev/null || true
run_container "$RUN_ARGS"

# Traefik fronts the edge unless something else already holds port 80 on this host — a reverse
# proxy of the operator's own, which then routes by the same labels. Ours is recognised by name
# and replaced; anything else is left in charge.
echo "=== Traefik ==="
if docker ps --format '{{.Names}} {{.Ports}}' | grep -v '^nukevideo_traefik ' | grep -q ':80->'; then
    echo "Port 80 is held by another container — leaving the host's own reverse proxy in front"
else
    docker rm -f nukevideo_traefik 2>/dev/null || true
    run_container "$TRAEFIK_RUN_ARGS"
    echo "Traefik deployed"
fi
