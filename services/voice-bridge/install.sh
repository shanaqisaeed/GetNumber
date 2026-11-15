#!/usr/bin/env bash
# ==============================================================================
# GetNumber Voice Bridge - Ubuntu / Debian Automated Installer
# Installs and runs high-port Voice Bridge & WebSocket signaling daemon
# Supports custom ports via:
# 1. Interactive prompts (press Enter for defaults)
# 2. Command-line flags (--api-port / --ws-port)
# 3. Environment variables (API_PORT / WS_PORT)
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

# 2. Default Configuration
API_PORT="${API_PORT:-49000}"
WS_PORT="${WS_PORT:-49001}"
HOST="${HOST:-0.0.0.0}"
SECRET="${SECRET:-}"
CALLBACK_URL="${BRIDGE_CALLBACK_URL:-}"
CLEAN_INSTALL=false
NON_INTERACTIVE=false

# 3. Parse command line flags
while [[ $# -gt 0 ]]; do
    case "$1" in
        --api-port|-a)
            API_PORT="$2"
            shift 2
            ;;
        --ws-port|-w)
            WS_PORT="$2"
            shift 2
            ;;
        --host|-h)
            HOST="$2"
            shift 2
            ;;
        --secret|-s)
            SECRET="$2"
            shift 2
            ;;
        --callback-url|-c)
            CALLBACK_URL="$2"
            shift 2
            ;;
        --clean|--reinstall)
            CLEAN_INSTALL=true
            shift
            ;;
        --yes|-y|--non-interactive)
            NON_INTERACTIVE=true
            shift
            ;;
        --help)
            echo "Usage: sudo bash install.sh [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --api-port,     -a <port>  Set REST API port (default: 49000)"
            echo "  --ws-port,      -w <port>  Set WebSocket port (default: 49001)"
            echo "  --host,         -h <ip>    Set bind host (default: 0.0.0.0)"
            echo "  --secret,       -s <token> Set custom secret token (default: auto-generate)"
            echo "  --callback-url, -c <url>   Set default Laravel webhook callback URL"
            echo "  --clean,        --reinstall Completely wipe old service and files first"
            echo "  --yes,          -y         Non-interactive mode (use defaults or passed args)"
            echo "  --help                     Show this help message"
            echo ""
            echo "Examples:"
            echo "  sudo bash install.sh"
            echo "  sudo bash install.sh --clean"
            echo "  sudo bash install.sh --api-port 49000 --ws-port 49001 --clean"
            echo "  sudo bash install.sh --callback-url https://your-domain.com/api/voice/bridge-event"
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            echo "Use --help for available options."
            exit 1
            ;;
    esac
done

# 4. Interactive prompt if in terminal and not non-interactive
if [ "$NON_INTERACTIVE" = false ] && [ -t 0 ]; then
    echo -e "\n${YELLOW}⚙️  Voice Bridge Configuration (Press Enter to keep default):${NC}"
    read -p "🔹 Enter REST API port [default: ${API_PORT}]: " input_api
    API_PORT="${input_api:-$API_PORT}"

    read -p "🔹 Enter WebSocket port [default: ${WS_PORT}]: " input_ws
    WS_PORT="${input_ws:-$WS_PORT}"

    read -p "🔹 Enter Bind Host [default: ${HOST}]: " input_host
    HOST="${input_host:-$HOST}"

    read -p "🔹 Enter Custom Secret (leave blank to auto-generate): " input_secret
    if [ -n "$input_secret" ]; then
        SECRET="$input_secret"
    fi
fi

# 5. Validate Port Numbers (allow any port from 1 to 65535, including privileged ports like 25, 80, 443)
if ! [[ "$API_PORT" =~ ^[0-9]+$ ]] || [ "$API_PORT" -lt 1 ] || [ "$API_PORT" -gt 65535 ]; then
    echo -e "${RED}❌ Invalid API Port: ${API_PORT}. Must be an integer between 1 and 65535.${NC}"
    exit 1
fi

if ! [[ "$WS_PORT" =~ ^[0-9]+$ ]] || [ "$WS_PORT" -lt 1 ] || [ "$WS_PORT" -gt 65535 ]; then
    echo -e "${RED}❌ Invalid WebSocket Port: ${WS_PORT}. Must be an integer between 1 and 65535.${NC}"
    exit 1
fi

if [ "$API_PORT" -eq "$WS_PORT" ]; then
    echo -e "${RED}❌ API port and WebSocket port cannot be the same!${NC}"
    exit 1
fi

INSTALL_DIR="/opt/getnumber-bridge"
SERVICE_NAME="getnumber-voice-bridge"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# If clean reinstall requested, clean up first
if [ "$CLEAN_INSTALL" = true ]; then
    echo -e "\n${YELLOW}🧹 Clean reinstall requested. Stopping and wiping old service...${NC}"
    systemctl stop "${SERVICE_NAME}" 2>/dev/null || true
    systemctl disable "${SERVICE_NAME}" 2>/dev/null || true
    rm -f "${SERVICE_FILE}"
    systemctl daemon-reload 2>/dev/null || true
    pkill -9 -f "voice-bridge" 2>/dev/null || true
fi

echo -e "\n${BLUE}[1/5] Preparing installation directory...${NC}"
mkdir -p "${INSTALL_DIR}"
chmod 755 "${INSTALL_DIR}"

# If secret is empty, check if one already exists in .secret to preserve it across reinstalls
if [ -z "$SECRET" ] && [ -f "${INSTALL_DIR}/.secret" ] && [ "$CLEAN_INSTALL" = false ]; then
    SECRET=$(cat "${INSTALL_DIR}/.secret" | tr -d '\r\n ')
    echo -e "${GREEN}✓ Reusing existing bridge secret: ${SECRET}${NC}"
fi

# If custom secret was provided, pre-create the secret file
if [ -n "$SECRET" ]; then
    echo "$SECRET" > "${INSTALL_DIR}/.secret"
    chmod 600 "${INSTALL_DIR}/.secret"
fi

# Write environment file with configured ports and callback URL
cat > "${INSTALL_DIR}/.env" << EOF
BRIDGE_HOST=${HOST}
BRIDGE_API_PORT=${API_PORT}
BRIDGE_WS_PORT=${WS_PORT}
BRIDGE_CALLBACK_URL=${CALLBACK_URL}
EOF
chmod 600 "${INSTALL_DIR}/.env"

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

echo -e "\n${BLUE}[3/5] Setting up systemd service with custom ports...${NC}"
cat > "${SERVICE_FILE}" << EOF
[Unit]
Description=GetNumber Voice Bridge & Audio Signaling Daemon
After=network.target network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
WorkingDirectory=${INSTALL_DIR}
EnvironmentFile=-${INSTALL_DIR}/.env
ExecStart=${INSTALL_DIR}/voice-bridge
Restart=always
RestartSec=3s
LimitNOFILE=65535
StandardOutput=journal
StandardError=journal
SyslogIdentifier=${SERVICE_NAME}

[Install]
WantedBy=multi-user.target
EOF

chmod 644 "${SERVICE_FILE}"
systemctl daemon-reload
systemctl enable "${SERVICE_NAME}"

echo -e "${GREEN}✓ Systemd service configured and enabled (${SERVICE_NAME})${NC}"

echo -e "\n${BLUE}[4/5] Starting service and initializing security secret...${NC}"
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
echo -e "📡 REST API Endpoint:    ${YELLOW}http://${SERVER_IP}:${API_PORT}${NC}"
echo -e "⚡ WebSocket Signaling:  ${YELLOW}ws://${SERVER_IP}:${WS_PORT}/ws/signaling${NC}"
echo -e "------------------------------------------------------------------"
echo -e "🔑 ${YELLOW}BRIDGE SECRET TOKEN:${NC} ${GREEN}${SECRET}${NC}"
echo -e "💾 Configuration File:   ${BLUE}${INSTALL_DIR}/.env${NC}"
echo -e "------------------------------------------------------------------"
echo -e "\n${CYAN}👉 PLEASE CONFIGURE YOUR LARAVEL ADMIN PANEL:${NC}"
echo -e "1. Go to: ${BLUE}Admin Panel -> Settings -> Voice Bridge${NC}"
echo -e "2. Enter Host:        ${YELLOW}${SERVER_IP}${NC}"
echo -e "3. Enter API Port:    ${YELLOW}${API_PORT}${NC}"
echo -e "4. Enter WS Port:     ${YELLOW}${WS_PORT}${NC}"
echo -e "5. Enter Secret:      ${GREEN}${SECRET}${NC}"
echo -e "6. Click ${GREEN}'Test Bridge Server Connection'${NC} to confirm connection and authentication!"
echo -e "7. Click ${GREEN}'Save Audio Bridge Server Settings'${NC}"
echo -e "\n${CYAN}🛠️ Useful Commands on this Ubuntu Server:${NC}"
echo -e "• Check status:     ${YELLOW}systemctl status ${SERVICE_NAME}${NC}"
echo -e "• View live logs:   ${YELLOW}journalctl -u ${SERVICE_NAME} -f${NC}"
echo -e "• Restart bridge:   ${YELLOW}systemctl restart ${SERVICE_NAME}${NC}"
echo -e "• Test API locally: ${YELLOW}curl -H \"X-Bridge-Secret: ${SECRET}\" http://localhost:${API_PORT}/health${NC}"
echo -e "${CYAN}==================================================================${NC}\n"
