#!/bin/sh
set -e

REPO_URL="https://github.com/chikenare/nukevideo.git"
WORKDIR="/var/www/html/nukevideo"

# --- Check dependencies ---
for cmd in docker git; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "Error: $cmd is not installed." >&2
        exit 1
    fi
done

# --- Validate NODE_TYPE ---
NODE_TYPE="${NODE_TYPE:-}"
if [ -z "$NODE_TYPE" ]; then
    echo "Error: NODE_TYPE env variable is required (proxy or worker)." >&2
    exit 1
fi

case "$NODE_TYPE" in
    proxy)  SERVICE="nukevideo-cdn" ;;
    worker) SERVICE="nukevideo-worker" ;;
    *)
        echo "Error: NODE_TYPE must be 'proxy' or 'worker', got '$NODE_TYPE'." >&2
        exit 1
        ;;
esac

# --- Setup workdir ---
mkdir -p "$WORKDIR"

# --- Clone or pull repo ---
if [ -d "$WORKDIR/.git" ]; then
    echo "Repo exists, pulling latest changes..."
    git -C "$WORKDIR" pull
else
    echo "Cloning repository..."
    git clone "$REPO_URL" "$WORKDIR"
fi

cd "$WORKDIR"

if [ ! -f ".env" ]; then
    echo "File .env does not exist."
    exit 1
fi

echo "Building and starting service: $SERVICE"
docker compose -f docker-compose.yml build "$SERVICE"
docker compose -f docker-compose.yml up -d "$SERVICE"

echo "Deploy complete: $SERVICE is running."
