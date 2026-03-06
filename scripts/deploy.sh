#!/bin/bash
set -euo pipefail

# NukeVideo Deploy — Docker Swarm + Traefik HTTPS

STACK_NAME="nukevideo"
NETWORK="traefik-public"
DEPLOY_DIR="/opt/nukevideo"

log()  { echo "▸ $*"; }
ok()   { echo "✅ $*"; }
fail() { echo "❌ $*" >&2; exit 1; }

# ── Cargar .env ──
ENV_FILE="${DEPLOY_DIR}/.env"
[[ -f "$ENV_FILE" ]] || fail ".env no encontrado en ${ENV_FILE}"
set -a
source "$ENV_FILE"
set +a
ok ".env cargado desde ${ENV_FILE}"

REPLICAS="${REPLICAS:-2}"
APP_ENV="${APP_ENV:-local}"

# ── Validaciones ──
command -v docker >/dev/null 2>&1 || fail "Docker no instalado"

[[ "${NODE_TYPE:-}" =~ ^(proxy|worker)$ ]] || fail "NODE_TYPE inválido: '${NODE_TYPE:-}' (proxy|worker)"
[[ -n "${DOMAIN:-}" ]]     || fail "DOMAIN requerido"

mkdir -p "$DEPLOY_DIR"

IMAGE="chikenare/nukevideo-${NODE_TYPE}:latest"

log "🎬 Deploy ${NODE_TYPE} → ${DOMAIN}"

# ── Swarm ──
if ! docker info --format '{{.Swarm.LocalNodeState}}' 2>/dev/null | grep -q "active"; then
    log "🐝 Inicializando Swarm..."
    docker swarm init 2>/dev/null || true
fi
ok "Swarm activo"

# ── Red overlay ──
docker network create --driver overlay --attachable "$NETWORK" 2>/dev/null || true
ok "Red ${NETWORK}"

# ── Pull ──
log "🐳 Pulling ${IMAGE}..."
docker pull "$IMAGE"
ok "Imagen lista"

# ── Compose ──
COMPOSE="${DEPLOY_DIR}/docker-compose.yml"

# Traefik base
if [[ "$APP_ENV" == "production" ]]; then
    [[ -n "${ACME_EMAIL:-}" ]] || fail "ACME_EMAIL requerido en production"

cat > "$COMPOSE" <<YAML
services:
  traefik:
    image: traefik:v3.6
    command:
      - "--providers.swarm=true"
      - "--providers.swarm.exposedByDefault=false"
      - "--providers.swarm.network=${NETWORK}"
      - "--entrypoints.web.address=:80"
      - "--entrypoints.websecure.address=:443"
      - "--entrypoints.web.http.redirections.entrypoint.to=websecure"
      - "--entrypoints.web.http.redirections.entrypoint.scheme=https"
      - "--certificatesresolvers.le.acme.email=${ACME_EMAIL}"
      - "--certificatesresolvers.le.acme.storage=/certs/acme.json"
      - "--certificatesresolvers.le.acme.httpchallenge.entrypoint=web"
    ports:
      - target: 80
        published: 80
        mode: host
      - target: 443
        published: 443
        mode: host
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - traefik-certs:/certs
    networks:
      - ${NETWORK}
    deploy:
      placement:
        constraints: [node.role == manager]

YAML
else
cat > "$COMPOSE" <<YAML
services:
  traefik:
    image: traefik:v3.6
    command:
      - "--providers.swarm=true"
      - "--providers.swarm.exposedByDefault=false"
      - "--providers.swarm.network=${NETWORK}"
      - "--entrypoints.web.address=:80"
    ports:
      - target: 80
        published: 80
        mode: host
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
    networks:
      - ${NETWORK}
    deploy:
      placement:
        constraints: [node.role == manager]

YAML
fi

# Servicio
if [[ "$NODE_TYPE" == "proxy" ]]; then
cat >> "$COMPOSE" <<YAML
  proxy:
    image: ${IMAGE}
    env_file: ${DEPLOY_DIR}/.env
    networks:
      - ${NETWORK}
    deploy:
      replicas: ${REPLICAS}
      update_config:
        parallelism: 1
        delay: 10s
        order: start-first
        failure_action: rollback
      rollback_config:
        parallelism: 1
        order: stop-first
      labels:
        - "traefik.enable=true"
        - "traefik.http.routers.proxy.rule=Host(\`${DOMAIN}\`)"
YAML

if [[ "$APP_ENV" == "production" ]]; then
cat >> "$COMPOSE" <<YAML
        - "traefik.http.routers.proxy.entrypoints=websecure"
        - "traefik.http.routers.proxy.tls.certresolver=le"
YAML
else
cat >> "$COMPOSE" <<YAML
        - "traefik.http.routers.proxy.entrypoints=web"
YAML
fi

cat >> "$COMPOSE" <<YAML
        - "traefik.http.services.proxy.loadbalancer.server.port=80"

YAML
else
cat >> "$COMPOSE" <<YAML
  worker:
    image: ${IMAGE}
    env_file: ${DEPLOY_DIR}/.env
    networks:
      - ${NETWORK}
    deploy:
      replicas: ${REPLICAS}
      update_config:
        parallelism: 1
        delay: 10s
        order: start-first
        failure_action: rollback
      rollback_config:
        parallelism: 1
        order: stop-first

YAML
fi

if [[ "$APP_ENV" == "production" ]]; then
cat >> "$COMPOSE" <<YAML
volumes:
  traefik-certs:

YAML
fi

cat >> "$COMPOSE" <<YAML
networks:
  ${NETWORK}:
    external: true
YAML

ok "Compose generado en ${COMPOSE}"

# ── Deploy ──
log "🚀 Desplegando stack..."
docker stack deploy -c "$COMPOSE" --with-registry-auth "$STACK_NAME" --detach=false 2>&1
ok "Stack desplegado"

# ── Verificar convergencia ──
log "⏳ Esperando réplicas..."
SERVICE="${STACK_NAME}_${NODE_TYPE}"
for i in $(seq 1 30); do
    REPLICAS_STATUS=$(docker service ls --filter "name=${SERVICE}" --format "{{.Replicas}}" 2>/dev/null || echo "0/0")
    CURRENT="${REPLICAS_STATUS%%/*}"
    TARGET="${REPLICAS_STATUS##*/}"
    if [[ "$CURRENT" == "$TARGET" && "$CURRENT" != "0" ]]; then
        ok "${SERVICE} listo (${REPLICAS_STATUS})"
        break
    fi
    sleep 3
done

echo ""
docker stack services "$STACK_NAME" --format "table {{.Name}}\t{{.Replicas}}\t{{.Image}}"
echo ""
if [[ "$APP_ENV" == "production" ]]; then
    ok "🎉 Deploy completado — https://${DOMAIN}"
else
    ok "🎉 Deploy completado — http://${DOMAIN}"
fi
