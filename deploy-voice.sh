#!/usr/bin/env bash
set -euo pipefail

PM_DIR="/var/www/pm"
APP_USER="pmadmin"
VOICE_SSL_DIR="/etc/pm/voice/ssl"
JANUS_HTTP_PORT=8088
JANUS_WS_PORT=8188
JANUS_ADMIN_PORT=7088
JANUS_RTP_RANGE="20000-40000"

log()  { echo -e "\033[1;34m[INFO]\033[0m $*"; }
warn() { echo -e "\033[1;33m[WARN]\033[0m $*"; }
err()  { echo -e "\033[1;31m[ERROR]\033[0m $*"; }

[ "$(id -u)" -eq 0 ] || { err "请用 root 运行"; exit 1; }
[ -d "$PM_DIR" ] || { err "$PM_DIR 不存在"; exit 1; }

SERVER_IP=$(hostname -I | awk '{print $1}')
log "========== PM 电话会议部署开始（IP: $SERVER_IP）=========="

log "[1/8] 自签名 SSL..."
mkdir -p "$VOICE_SSL_DIR"
if [ ! -f "$VOICE_SSL_DIR/server.crt" ]; then
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout "$VOICE_SSL_DIR/server.key" \
        -out "$VOICE_SSL_DIR/server.crt" \
        -subj "/CN=$SERVER_IP" \
        -addext "subjectAltName=IP:$SERVER_IP,DNS:localhost" >/dev/null 2>&1
    chown -R "$APP_USER:$APP_USER" "$VOICE_SSL_DIR"
fi

log "[2/8] 启动 Janus 容器..."
if ! docker ps --format '{{.Names}}' | grep -q '^pm-janus$'; then
    docker run -d \
        --name pm-janus \
        --restart=always \
        -p ${JANUS_HTTP_PORT}:8088 \
        -p ${JANUS_WS_PORT}:8188 \
        -p ${JANUS_ADMIN_PORT}:7088 \
        -p ${JANUS_RTP_RANGE}:${JANUS_RTP_RANGE}/udp \
        meetecho/janus-gateway:latest >/dev/null
    log "Janus 已启动"
else
    log "Janus 已存在，跳过"
fi

log "[3/8] 启动 coturn 容器..."
TURN_SECRET=$(grep -E '^TURN_SECRET=' "$PM_DIR/.env" | cut -d= -f2-)
[ -z "$TURN_SECRET" ] && TURN_SECRET=$(openssl rand -hex 16) && echo "TURN_SECRET=$TURN_SECRET" >> "$PM_DIR/.env"

if ! docker ps --format '{{.Names}}' | grep -q '^pm-coturn$'; then
    docker run -d \
        --name pm-coturn \
        --restart=always \
        --network=host \
        coturn/coturn:latest \
        -n --listening-port=3478 --fingerprint --lt-cred-mech \
        --realm=pm-voice --user=pm:${TURN_SECRET} \
        --external-ip=${SERVER_IP} >/dev/null
    log "coturn 已启动"
else
    log "coturn 已存在，跳过"
fi

log "[4/8] Apache 反代 /janus-ws..."
if ! grep -q "janus-ws" /etc/httpd/conf.d/pm.conf 2>/dev/null; then
    cat >> /etc/httpd/conf.d/pm.conf <<EOF

ProxyPass "/janus-ws" "ws://127.0.0.1:${JANUS_WS_PORT}"
ProxyPassReverse "/janus-ws" "ws://127.0.0.1:${JANUS_WS_PORT}"
ProxyPreserveHost On
EOF
    systemctl reload httpd
fi

log "[5/8] pm-signal systemd unit..."
cp "$PM_DIR/server/pm-signal.service" /etc/systemd/system/pm-signal.service
grep -q '^VOICE_SIGNAL_WS=' "$PM_DIR/.env" || cat >> "$PM_DIR/.env" <<EOF
VOICE_SIGNAL_WS=wss://${SERVER_IP}:3001
VOICE_JANUS_WS=ws://127.0.0.1:8188
VOICE_JANUS_ADMIN=http://127.0.0.1:7088
VOICE_STUN=stun:${SERVER_IP}:3478
VOICE_PM_INTERNAL_URL=http://127.0.0.1
EOF
systemctl daemon-reload
systemctl enable --now pm-signal || warn "pm-signal 启动失败"

log "[6/8] 防火墙..."
firewall-cmd --permanent --add-port=3001/tcp >/dev/null 2>&1 || true
firewall-cmd --permanent --add-port=3478/udp >/dev/null 2>&1 || true
firewall-cmd --permanent --add-port=3478/tcp >/dev/null 2>&1 || true
firewall-cmd --permanent --add-port=${JANUS_RTP_RANGE}/udp >/dev/null 2>&1 || true
firewall-cmd --reload >/dev/null 2>&1 || true

log "[7/8] janus.js..."
mkdir -p "$PM_DIR/public/vendor/janus"
if [ ! -f "$PM_DIR/public/vendor/janus/janus.js" ]; then
    docker cp pm-janus:/opt/janus/share/janus/demos/janus.js "$PM_DIR/public/vendor/janus/janus.js" 2>/dev/null \
        || warn "janus.js 拷贝失败"
fi
chown -R "$APP_USER:$APP_USER" "$PM_DIR/public/vendor/"

log "[8/8] migrate..."
cd "$PM_DIR"
sudo -u "$APP_USER" php artisan migrate --force

log "========== 健康检查 =========="
curl -s http://127.0.0.1:${JANUS_ADMIN_PORT}/info >/dev/null && log "Janus admin OK" || warn "Janus admin 未响应"
ss -lunup | grep -q ':3478' && log "coturn 3478 OK" || warn "coturn 未监听"
ss -ltnp | grep -q ':3001' && log "pm-signal 3001 OK" || warn "pm-signal 未监听"
echo ""
echo "========== 完成：https://${SERVER_IP}/project/<ID>/voice =========="