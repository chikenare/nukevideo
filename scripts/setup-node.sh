#!/bin/bash
set -e

WORKDIR=""

for arg in "$@"; do
    case $arg in
        --workdir=*) WORKDIR="${arg#*=}" ;;
    esac
done

install_docker() {
    echo "=== Installing Docker ==="
    if ! command -v docker &>/dev/null; then
        curl -fsSL https://get.docker.com | sh
        systemctl enable --now docker
        echo "Docker installed successfully"
    else
        systemctl enable --now docker 2>/dev/null || true
        echo "Docker already installed: $(docker --version)"
    fi
}

create_network() {
    echo "=== Creating Docker network ==="
    docker network create nukevideo_default 2>/dev/null && echo "Network created" || echo "Network already exists"
}

create_volumes() {
    echo "=== Creating Docker volumes ==="
    docker volume create nukevideo_tmp 2>/dev/null && echo "Volume created" || echo "Volume already exists"
}

setup_workdir() {
    if [ -n "$WORKDIR" ]; then
        echo "=== Setting up working directory ==="
        mkdir -p "${WORKDIR}/config" "${WORKDIR}/data" "${WORKDIR}/certs"
        echo "Workdir ready: ${WORKDIR}"
    fi
}

# Run
install_docker
create_network
create_volumes
setup_workdir

echo "=== Setup complete ==="
