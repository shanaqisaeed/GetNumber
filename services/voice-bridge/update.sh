#!/usr/bin/env bash
# ==============================================================================
# GetNumber Voice Bridge - Automated Quick Updater
# Replaces binary with latest version, preserves secret & config, restarts service
# ==============================================================================

set -e

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
NC='\033[0m' # No Color

SERVICE_NAME="getnumber-voice-bridge"
INSTALL_DIR="/opt/getnumber-bridge"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo -e "${CYAN}==================================================================${NC}"
echo -e "${GREEN}🔄 GetNumber Voice Bridge Updater${NC}"
echo -e "${CYAN}==================================================================${NC}"

# 1. Root check
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}❌ Please run this script with sudo or as root:${NC}"
    echo "   sudo bash update.sh"
    exit 1
fi

CALLBACK_URL=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --callback-url|-c)
            CALLBACK_URL="$2"
            shift 2
            ;;
        --help)
            echo "Usage: sudo bash update.sh [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --callback-url, -c <url>  Update or set default Laravel webhook callback URL"
            echo "  --help                    Show this help message"
            exit 0
            ;;
        *)
            shift
            ;;
    esac
done

# 2. Check if installed
if [ ! -d "${INSTALL_DIR}" ]; then
    echo -e "${YELLOW}ℹ Service not found at ${INSTALL_DIR}. Running full installer instead...${NC}"
    exec bash "${SCRIPT_DIR}/install.sh"
    exit 0
fi

# 3. Stop service cleanly
echo -e "\n${BLUE}[1/4] Stopping current Voice Bridge service...${NC}"
systemctl stop "${SERVICE_NAME}" 2>/dev/null || true
pkill -9 -f "voice-bridge" 2>/dev/null || true
sleep 1

# 4. Locate binary source
echo -e "\n${BLUE}[2/4] Deploying updated binary...${NC}"
ARCH=$(uname -m)
BINARY_SRC=""
if [ -f "${SCRIPT_DIR}/voice-bridge-linux-amd64" ] && [ "$ARCH" = "x86_64" ]; then
    BINARY_SRC="${SCRIPT_DIR}/voice-bridge-linux-amd64"
elif [ -f "${SCRIPT_DIR}/voice-bridge" ]; then
    BINARY_SRC="${SCRIPT_DIR}/voice-bridge"
elif command -v go >/dev/null 2>&1 && [ -f "${SCRIPT_DIR}/main.go" ]; then
    echo -e "${YELLOW}⚡ Building updated binary from source...${NC}"
    (cd "${SCRIPT_DIR}" && go build -o "${INSTALL_DIR}/voice-bridge" .)
    BINARY_SRC="${INSTALL_DIR}/voice-bridge"
fi

if [ -z "$BINARY_SRC" ] || [ ! -f "$BINARY_SRC" ]; then
    echo -e "${RED}❌ Updated binary not found!${NC}"
    exit 1
fi

if [ "$BINARY_SRC" != "${INSTALL_DIR}/voice-bridge" ]; then
    cp -f "${BINARY_SRC}" "${INSTALL_DIR}/voice-bridge"
fi
chmod +x "${INSTALL_DIR}/voice-bridge"
echo -e "${GREEN}✓ Updated binary copied to ${INSTALL_DIR}/voice-bridge${NC}"

# 5. Update .env callback URL if requested
echo -e "\n${BLUE}[3/4] Checking configuration & secret...${NC}"
if [ -n "$CALLBACK_URL" ]; then
    if grep -q "^BRIDGE_CALLBACK_URL=" "${INSTALL_DIR}/.env" 2>/dev/null; then
        sed -i "s|^BRIDGE_CALLBACK_URL=.*|BRIDGE_CALLBACK_URL=${CALLBACK_URL}|" "${INSTALL_DIR}/.env"
    else
        echo "BRIDGE_CALLBACK_URL=${CALLBACK_URL}" >> "${INSTALL_DIR}/.env"
    fi
    echo -e "${GREEN}✓ Updated BRIDGE_CALLBACK_URL in .env${NC}"
fi

# 6. Restart systemd service
echo -e "\n${BLUE}[4/4] Restarting systemd service...${NC}"
systemctl daemon-reload
systemctl restart "${SERVICE_NAME}"
sleep 2

if systemctl is-active --quiet "${SERVICE_NAME}"; then
    echo -e "${GREEN}✓ Voice Bridge is ACTIVE and RUNNING!${NC}"
else
    echo -e "${RED}❌ Service failed to start. Journal log:${NC}"
    journalctl -u "${SERVICE_NAME}" -n 25 --no-pager
    exit 1
fi

SECRET=""
if [ -f "${INSTALL_DIR}/.secret" ]; then
    SECRET=$(cat "${INSTALL_DIR}/.secret" | tr -d '\r\n ')
fi

API_PORT=$(grep "^BRIDGE_API_PORT=" "${INSTALL_DIR}/.env" 2>/dev/null | cut -d'=' -f2 | tr -d '\r\n ')
API_PORT="${API_PORT:-49000}"
WS_PORT=$(grep "^BRIDGE_WS_PORT=" "${INSTALL_DIR}/.env" 2>/dev/null | cut -d'=' -f2 | tr -d '\r\n ')
WS_PORT="${WS_PORT:-49001}"
SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
SERVER_IP="${SERVER_IP:-YOUR_SERVER_IP}"

echo -e "\n${CYAN}==================================================================${NC}"
echo -e "${GREEN}🎉 GETNUMBER VOICE BRIDGE UPDATED SUCCESSFULLY!${NC}"
echo -e "${CYAN}==================================================================${NC}"
echo -e "📡 REST API:          ${YELLOW}http://${SERVER_IP}:${API_PORT}${NC}"
echo -e "⚡ WebSocket:         ${YELLOW}ws://${SERVER_IP}:${WS_PORT}/ws/signaling${NC}"
echo -e "🔑 Secret Token:      ${GREEN}${SECRET}${NC}"
echo -e "💾 Configuration:     ${BLUE}${INSTALL_DIR}/.env${NC}"
echo -e "------------------------------------------------------------------"
echo -e "• Health check:       ${YELLOW}curl -s http://localhost:${API_PORT}/health${NC}"
echo -e "• Live logs:          ${YELLOW}journalctl -u ${SERVICE_NAME} -f${NC}"
echo -e "${CYAN}==================================================================${NC}\n"
