# Shared by every node deploy. Runs on the node over SSH, after the header the API prepends:
# one assignment per variable, nothing else — the PHP side composes, the shell side acts.
set -e

# Root, or a user whose sudo asks no questions. The API runs this over SSH with no terminal
# (BatchMode), so a sudo that wants a password cannot get one: it fails, and every `|| true`
# below used to turn that into a deploy that "succeeded" with Docker never enabled and the user
# never in the docker group. Checked once, up front, with the reason spelled out.
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    SUDO="sudo"
    if ! sudo -n true 2>/dev/null; then
        echo "ERROR: $(id -un) cannot sudo without a password, and this deploy has no terminal to type one in." >&2
        echo "Deploy as root, or grant passwordless sudo: echo '$(id -un) ALL=(ALL) NOPASSWD: ALL' > /etc/sudoers.d/nukevideo" >&2
        exit 1
    fi
fi

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

# Group membership is granted at login, so on a host where the line above just added it this
# very session still cannot open the docker socket, and the first deploy of a fresh node died at
# the network step below. Route docker through sudo for the rest of this run when that is the
# case — sudo is known to be passwordless by now — and the next deploy will not need it.
if [ -n "$SUDO" ] && ! docker info &>/dev/null; then
    echo "docker group not active in this session yet — using sudo for docker this run"
    docker() { sudo docker "$@"; }
fi

echo "=== Network & workdir ==="
docker network inspect nukevideo_default &>/dev/null && echo "Network already exists" \
    || { docker network create nukevideo_default >/dev/null && echo "Network created"; }
mkdir -p "$WORKDIR/config" "$WORKDIR/data" "$WORKDIR/certs"
