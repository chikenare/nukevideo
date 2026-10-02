# A worker node: host-side GPU prep, the Horizon container, and the chunk store if the fleet's
# chunk store address is this node's own.

# Intel only needs the render group's GID (the container user joins it to open /dev/dri).
# NVIDIA needs the container toolkit so `--gpus all` works; the kernel driver itself must
# already be on the host.
case "$NODE_ACCEL" in
    intel)
        echo "=== Intel GPU ==="
        [ -e /dev/dri/renderD128 ] || { echo "No /dev/dri render node found — is the GPU driver loaded?"; exit 1; }
        RENDER_GID=$(getent group render | cut -d: -f3)
        echo "Render node present, render GID: ${RENDER_GID:-44 (fallback)}"
        ;;
    nvidia)
        echo "=== NVIDIA GPU ==="
        command -v nvidia-smi &>/dev/null || { echo "nvidia-smi not found — install the NVIDIA driver first"; exit 1; }
        nvidia-smi --query-gpu=name --format=csv,noheader
        if ! command -v nvidia-ctk &>/dev/null; then
            echo "Installing NVIDIA container toolkit"
            curl -fsSL https://nvidia.github.io/libnvidia-container/gpgkey | $SUDO gpg --dearmor --yes -o /usr/share/keyrings/nvidia-container-toolkit-keyring.gpg
            curl -fsSL https://nvidia.github.io/libnvidia-container/stable/deb/nvidia-container-toolkit.list \
                | sed 's#deb https://#deb [signed-by=/usr/share/keyrings/nvidia-container-toolkit-keyring.gpg] https://#g' \
                | $SUDO tee /etc/apt/sources.list.d/nvidia-container-toolkit.list > /dev/null
            $SUDO apt-get update -qq && $SUDO apt-get install -y -qq nvidia-container-toolkit
            $SUDO nvidia-ctk runtime configure --runtime=docker
            $SUDO systemctl restart docker
        fi
        ;;
esac

echo "=== Worker image ==="
pull_image "$IMAGE"

echo "=== Deploying worker ==="
# Drain before replacing: give Horizon time to finish in-flight encodes, or they sit reserved
# in Redis for ~31 minutes with the new container idle. Returns as soon as Horizon exits — the
# cap only bites with a job mid-flight. `-t`, not `--time`/`--timeout`: the long forms flipped
# between docker CLI generations, the short one never did.
if [ "$DRAIN" -gt 0 ] && docker inspect "$SERVICE_CONTAINER" &>/dev/null; then
    echo "Draining running jobs (up to ${DRAIN}s)..."
    docker stop -t "$DRAIN" "$SERVICE_CONTAINER" || true
fi
docker rm -f "$SERVICE_CONTAINER" 2>/dev/null || true
run_container "$RUN_ARGS"

# The chunk store runs on the worker whose own address the fleet's chunk store address is.
# Compared here, against this host's interfaces, because the panel often reaches the node over
# another address than the private one the workers use. Every worker resolves it: one that
# cannot would fail on its first chunk instead of here.
echo "=== Chunk store ==="
CHUNK_STORE_IP=$(getent ahostsv4 "$CHUNK_STORE_HOST" | awk 'NR==1 { print $1 }')
if [ -z "$CHUNK_STORE_IP" ]; then
    echo "The chunk store address $CHUNK_STORE_HOST does not resolve on this node"; exit 1
fi

if ! hostname -I | tr ' ' '\n' | grep -qxF "$CHUNK_STORE_IP"; then
    echo "Chunk store is at $CHUNK_STORE_HOST ($CHUNK_STORE_IP:$CHUNK_STORE_PORT), not on this node"
    # Something has to answer there, or every chunk this worker takes fails. Waited for, not
    # checked once: in a fleet deploy the worker that runs the store may still be pulling.
    n=0
    until timeout 2 bash -c "</dev/tcp/$CHUNK_STORE_IP/$CHUNK_STORE_PORT" 2>/dev/null; do
        n=$((n+1))
        if [ $n -ge 60 ]; then
            echo "No node answers on $CHUNK_STORE_HOST:$CHUNK_STORE_PORT. Deploy the worker whose address that is, or fix the address under Nodes -> Environment."
            exit 1
        fi
        sleep 2
    done
    echo "Chunk store reachable"
# Kept when it already runs exactly this (the hash the API puts in its label, plus the IP): it
# holds the fleet's in-flight transfers, and recreating it for nothing cut them mid deploy.
elif [ "$(docker inspect -f '{{.State.Running}} {{index .Config.Labels "nukevideo.config"}}' "$STORAGE_CONTAINER" 2>/dev/null)" = "true $STORAGE_CONFIG-$CHUNK_STORE_IP" ]; then
    echo "Chunk store already running this configuration on $CHUNK_STORE_IP:$CHUNK_STORE_PORT — kept"
# Two workers on one host would both claim it, and the second store could not bind the port.
elif docker ps --format '{{.Names}} {{.Ports}}' | grep -v "^$STORAGE_CONTAINER " | grep -qF "$CHUNK_STORE_IP:$CHUNK_STORE_PORT->"; then
    echo "Chunk store already served on $CHUNK_STORE_IP:$CHUNK_STORE_PORT by another container on this host"
else
    echo "=== Deploying chunk store on $CHUNK_STORE_IP:$CHUNK_STORE_PORT ==="
    pull_image "$STORAGE_IMAGE"
    docker rm -f "$STORAGE_CONTAINER" 2>/dev/null || true
    run_container "$STORAGE_RUN_ARGS"
    echo "Waiting for chunk store..."
    n=0
    until docker run --rm --network host -e "CHUNK_STORE_IP=$CHUNK_STORE_IP" -e "$STORAGE_ACCESS_KEY" -e "$STORAGE_SECRET_KEY" --entrypoint sh "$IMAGE" -c "$STORAGE_BUCKET_CMD"; do
        n=$((n+1)); [ $n -ge 30 ] && echo "Chunk store failed to start" && exit 1; sleep 2
    done
    echo "Chunk store ready"
fi
