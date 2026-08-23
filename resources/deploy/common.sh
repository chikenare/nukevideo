# Shared by every node deploy. Runs on the node over SSH, after the header the API prepends:
# one assignment per variable, nothing else — the PHP side composes, the shell side acts.
set -e

SUDO=""
[ "$(id -u)" -ne 0 ] && SUDO="sudo"

# Seconds the old worker gets to finish in-flight jobs before it is killed. The default covers
# one full chunk pass, and is 0 in development and staging, where the wait buys nothing; docker
# stop returns the moment Horizon exits, so an idle worker drains in seconds either way.
# Override per run in both directions (killed jobs sit reserved in Redis for ~31 min before
# redelivery):
#   curl ... | bash -s -- --no-drain
#   curl ... | bash -s -- --drain=60
for arg in "$@"; do
    case "$arg" in
        --no-drain) DRAIN=0 ;;
        --drain=*) DRAIN="${arg#--drain=}" ;;
    esac
done

pull_image() {
    docker pull "$1" 2>/dev/null || docker image inspect "$1" &>/dev/null \
        || { echo "Image $1 not found locally or in registry"; exit 1; }
}

# Production pulls the released tag. Development builds it, because there is nothing published
# to pull and the point of deploying a development node is to run the code as it is right now.
#
# Which of the two happens is decided here, on the node, because this is where the answer is:
# the build context is the compose project's directory, read back from the label docker wrote
# on the containers running the panel. A node that is the development machine has the working
# copy and builds; an external test node does not, and pulls what the last build pushed. The
# push only happens with a registry configured (PUSH_IMAGE), so a development build is never
# pushed to the place releases are published.
#
#   $1 image, $2 build target — empty target means "pull, never build"
ensure_image() {
    local image="$1" target="$2"

    if [ -z "$target" ]; then
        pull_image "$image"
        return
    fi

    SOURCE_DIR=$(docker ps -a --filter label=com.docker.compose.service=nukevideo-api \
        --format '{{.Label "com.docker.compose.project.working_dir"}}' | head -n1)
    if [ -n "$SOURCE_DIR" ]; then
        echo "Building $image (target $target) from $SOURCE_DIR"
        docker build --target "$target" -t "$image" "$SOURCE_DIR"
        if [ -n "$PUSH_IMAGE" ]; then
            docker push "$image"
        else
            echo "No DOCKER_REGISTRY set — $image stays on this host"
        fi
    else
        echo "No working copy on this host — using $image as last published"
        pull_image "$image"
    fi
}

# `docker run -d` over an argument string the API built and quoted. `eval` because the string
# carries quoted values (`-e 'APP_KEY=...'`) and, for the proxy, expansions resolved here
# (`$CACHE_MOUNT`); a bare `$ARGS` would split on spaces and hand docker the quotes.
run_container() {
    eval "docker run -d $1"
}

echo "=== Installing Docker ==="
if ! command -v docker &>/dev/null; then
    curl -fsSL https://get.docker.com | $SUDO sh
    $SUDO systemctl enable --now docker
    echo "Docker installed"
else
    $SUDO systemctl enable --now docker 2>/dev/null || true
    echo "Docker already installed: $(docker --version)"
fi
$SUDO usermod -aG docker "$(id -un)" 2>/dev/null || true

echo "=== Network & workdir ==="
# No sudo: every docker call here runs as the deploy user, who is in the docker group. With
# sudo, a host that asks for a password failed here silently — the error was discarded,
# "already exists" was printed, and `docker run` then found no network.
docker network inspect nukevideo_default &>/dev/null && echo "Network already exists" \
    || { docker network create nukevideo_default >/dev/null && echo "Network created"; }
mkdir -p "$WORKDIR/config" "$WORKDIR/data" "$WORKDIR/certs"
