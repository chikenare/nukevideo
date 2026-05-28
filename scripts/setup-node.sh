#!/bin/bash
set -e

HAS_GPU=false
WORKDIR=""

for arg in "$@"; do
    case $arg in
        --gpu) HAS_GPU=true ;;
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

setup_gpu() {
    echo "=== Checking GPU drivers ==="
    if ! command -v nvidia-smi &>/dev/null; then
        echo "ERROR: nvidia-smi not found. Install NVIDIA GPU drivers on the node first."
        exit 1
    fi
    nvidia-smi --query-gpu=name,driver_version --format=csv,noheader

    echo "=== Installing NVIDIA Container Toolkit ==="
    if dpkg -l 2>/dev/null | grep -q nvidia-container-toolkit; then
        echo "NVIDIA Container Toolkit already installed"
    else
        curl -fsSL https://nvidia.github.io/libnvidia-container/gpgkey | \
            gpg --dearmor -o /usr/share/keyrings/nvidia-container-toolkit-keyring.gpg
        curl -s -L https://nvidia.github.io/libnvidia-container/stable/deb/nvidia-container-toolkit.list | \
            sed 's#deb https://#deb [signed-by=/usr/share/keyrings/nvidia-container-toolkit-keyring.gpg] https://#g' | \
            tee /etc/apt/sources.list.d/nvidia-container-toolkit.list
        apt-get update
        apt-get install -y nvidia-container-toolkit
        nvidia-ctk runtime configure --runtime=docker
        systemctl restart docker
        echo "NVIDIA Container Toolkit installed"
    fi
}

# Run
install_docker
create_network
create_volumes
setup_workdir

if [ "$HAS_GPU" = true ]; then
    setup_gpu
fi

echo "=== Setup complete ==="
