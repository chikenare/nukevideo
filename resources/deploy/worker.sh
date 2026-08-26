# A worker node: host-side GPU prep, the Horizon container, and the chunk store if this node
# is the fleet's storage server.

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

if [ -n "$STORAGE_RUN_ARGS" ]; then
    echo "=== Deploying chunk store ==="
    docker rm -f "$STORAGE_CONTAINER" 2>/dev/null || true
    run_container "$STORAGE_RUN_ARGS"
    echo "Waiting for chunk store..."
    n=0
    until docker run --rm --network host -e "$STORAGE_MC_HOST" --entrypoint sh minio/mc -c "$STORAGE_MC_CMD"; do
        n=$((n+1)); [ $n -ge 30 ] && echo "Chunk store failed to start" && exit 1; sleep 2
    done
    echo "Chunk store ready"
fi
