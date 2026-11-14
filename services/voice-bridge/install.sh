#!/usr/bin/env bash
# ==============================================================================
# GetNumber Voice Bridge - Ubuntu / Debian Automated Installer
# Installs and runs high-port Voice Bridge & WebSocket signaling daemon
# ==============================================================================

set -e

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${CYAN}==================================================================${NC}"
echo -e "${GREEN}🚀 GetNumber Voice Bridge Installer for Ubuntu / Debian${NC}"
echo -e "${CYAN}==================================================================${NC}"

# 1. Root check
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}❌ Please run this script as root or with sudo:${NC}"
    echo "   sudo bash install.sh"
    exit 1
fi

INSTALL_DIR="/opt/getnumber-bridge"
SERVICE_NAME="getnumber-voice-bridge"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo -e "\n${BLUE}[1/5] Preparing installation directory...${NC}"
mkdir -p "${INSTALL_DIR}"
chmod 755 "${INSTALL_DIR}"

echo -e "${BLUE}[2/5] Installing Voice Bridge binary...${NC}"
# Determine architecture
ARCH=$(uname -m)
BINARY_SRC=""

if [ -f "${SCRIPT_DIR}/voice-bridge-linux-amd64" ] && [ "$ARCH" = "x86_64" ]; then
    BINARY_SRC="${SCRIPT_DIR}/voice-bridge-linux-amd64"
elif [ -f "${SCRIPT_DIR}/voice-bridge" ]; then
    BINARY_SRC="${SCRIPT_DIR}/voice-bridge"
elif command -v go >/dev/null 2>&1 && [ -f "${SCRIPT_DIR}/main.go" ]; then
    echo -e "${YELLOW}⚡ Pre-built binary not found, compiling from Go source...${NC}"
    (cd "${SCRIPT_DIR}" && go build -o "${INSTALL_DIR}/voice-bridge" .)
    BINARY_SRC="${INSTALL_DIR}/voice-bridge"
fi

if [ -z "$BINARY_SRC" ] || [ ! -f "$BINARY_SRC" ]; then
    echo -e "${RED}❌ Voice bridge binary could not be found or built!${NC}"
    echo "Make sure 'voice-bridge-linux-amd64' exists in ${SCRIPT_DIR} or install Go to compile."
    exit 1
fi

if [ "$BINARY_SRC" != "${INSTALL_DIR}/voice-bridge" ]; then
    cp -f "${BINARY_SRC}" "${INSTALL_DIR}/voice-bridge"
fi
chmod +x "${INSTALL_DIR}/voice-bridge"

echo -e "${GREEN}✓ Binary installed at ${INSTALL_DIR}/voice-bridge${NC}"

echo -e "\n${BLUE}[3/5] Setting up systemd service...${NC}"
cat > "${SERVICE_FILE}" << 'EOF'
[Unit]
Description=GetNumber Voice Bridge & Audio Signaling Daemon
After=network.target network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
WorkingDirectory=/opt/getnumber-bridge
ExecStart=/opt/getnumber-bridge/voice-bridge -host=0.0.0.0 -api-port=49000 -ws-port=49001
Restart=always
RestartSec=3s
LimitNOFILE=65535
StandardOutput=journal
StandardError=journal
SyslogIdentifier=getnumber-voice-bridge

# Optional environment file
EnvironmentFile=-/opt/getnumber-bridge/.env

[Install]
WantedBy=multi-user.target
EOF

chmod 644 "${SERVICE_FILE}"
systemctl daemon-reload
systemctl enable "${SERVICE_NAME}"

echo -e "${GREEN}✓ Systemd service configured and enabled (${SERVICE_NAME})${NC}"

echo -e "\n${BLUE}[4/5] Starting service and generating security secret...${NC}"
systemctl restart "${SERVICE_NAME}"
sleep 2

# Verify service is running
if systemctl is-active --quiet "${SERVICE_NAME}"; then
    echo -e "${GREEN}✓ Voice Bridge is ACTIVE and RUNNING!${NC}"
else
    echo -e "${RED}❌ Service failed to start. Journal log:${NC}"
    journalctl -u "${SERVICE_NAME}" -n 20 --no-pager
    exit 1
fi

echo -e "\n${BLUE}[5/5] Extracting Bridge Secret & Connection Information...${NC}"
SECRET_FILE="${INSTALL_DIR}/.secret"
SECRET=""

if [ -f "$SECRET_FILE" ]; then
    SECRET=$(cat "$SECRET_FILE" | tr -d '\r\n ')
fi

if [ -z "$SECRET" ]; then
    # Read from journal if not yet written
    SECRET=$(journalctl -u "${SERVICE_NAME}" -n 30 --no-pager | grep "BRIDGE SECRET TOKEN:" | tail -n1 | awk '{print $NF}' | tr -d '\r\n ')
fi

# Detect server public / private IP
SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
if [ -z "$SERVER_IP" ]; then
    SERVER_IP="YOUR_SERVER_IP"
fi

echo -e "\n${CYAN}==================================================================${NC}"
echo -e "${GREEN}🎉 GETNUMBER VOICE BRIDGE INSTALLED SUCCESSFULLY!${NC}"
echo -e "${CYAN}==================================================================${NC}"
echo -e "📡 REST API Endpoint:    ${YELLOW}http://${SERVER_IP}:49000${NC}"
echo -e "⚡ WebSocket Signaling:  ${YELLOW}ws://${SERVER_IP}:49001/ws/signaling${NC}"
echo -e "------------------------------------------------------------------"
echo -e "🔑 ${YELLOW}BRIDGE SECRET TOKEN:${NC} ${GREEN}${SECRET}${NC}"
echo -e "------------------------------------------------------------------"
echo -e "\n${CYAN}👉 PLEASE CONFIGURE YOUR LARAVEL ADMIN PANEL:${NC}"
echo -e "1. Go to: ${BLUE}Admin Panel -> Settings -> Voice Bridge (بریج صوتی)${NC}"
echo -e "2. Enter Host:        ${YELLOW}${SERVER_IP}${NC}"
echo -e "3. Enter API Port:    ${YELLOW}49000${NC}"
echo -e "4. Enter WS Port:     ${YELLOW}49001${NC}"
echo -e "5. Enter Secret:      ${GREEN}${SECRET}${NC}"
echo -e "6. Click ${GREEN}'تست اتصال زنده'${NC} to confirm connection and authentication!"
echo -e "7. Click ${GREEN}'ذخیره تنظیمات سرور بریج صوتی'${NC}"
echo -e "\n${CYAN}🛠️ Useful Commands on this Ubuntu Server:${NC}"
echo -e "• Check status:     ${YELLOW}systemctl status ${SERVICE_NAME}${NC}"
echo -e "• View live logs:   ${YELLOW}journalctl -u ${SERVICE_NAME} -f${NC}"
echo -e "• Restart bridge:   ${YELLOW}systemctl restart ${SERVICE_NAME}${NC}"
echo -e "• Test API locally: ${YELLOW}curl -H \"X-Bridge-Secret: ${SECRET}\" http://localhost:49000/health${NC}"
echo -e "${CYAN}==================================================================${NC}\n"

