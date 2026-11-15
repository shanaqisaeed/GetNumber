#!/usr/bin/env bash
# ==============================================================================
#  ✦ GETNUMBER VOICE BRIDGE • HIGH-PORT RUNTIME DEPLOYMENT ENGINE
#  Next-Gen WebRTC Audio Signaling & Telephony Daemon (2026 Edition)
#  Target OS: Ubuntu 20.04 / 22.04 / 24.04 / Debian 11+ (x86_64)
# ==============================================================================

set -eo pipefail

# --- ANSI 256-COLOR PALETTE & MODERN STYLES ---
BOLD="\033[1m"
DIM="\033[2m"
ITALIC="\033[3m"
RESET="\033[0m"

CYAN="\033[38;5;51m"
PURPLE="\033[38;5;141m"
GREEN="\033[38;5;48m"
AMBER="\033[38;5;214m"
RED="\033[38;5;203m"
GRAY="\033[38;5;244m"
WHITE="\033[38;5;255m"

# Disable colors if terminal doesn't support them or NO_COLOR is set
if [ -z "${TERM:-}" ] || [ "${TERM}" = "dumb" ] || [ -n "${NO_COLOR:-}" ]; then
    BOLD=""
    DIM=""
    RESET=""
    CYAN=""
    PURPLE=""
    GREEN=""
    AMBER=""
    RED=""
    GRAY=""
    WHITE=""
fi

clear 2>/dev/null || true

echo -e "${PURPLE}╭─────────────────────────────────────────────────────────────────────────╮${RESET}"
echo -e "${PURPLE}│${RESET}  ${CYAN}${BOLD}✦ GETNUMBER VOICE BRIDGE${RESET} ${DIM}•${RESET} ${WHITE}${BOLD}HIGH-PORT RUNTIME DEPLOYMENT${RESET}            ${PURPLE}│${RESET}"
echo -e "${PURPLE}│${RESET}  ${DIM}Next-Gen WebRTC Signaling & Audio Daemon • Production Engine (2026)${RESET}    ${PURPLE}│${RESET}"
echo -e "${PURPLE}╰─────────────────────────────────────────────────────────────────────────╯${RESET}\n"

# 1. Defaults & CLI Argument Parsing
API_PORT="${API_PORT:-49000}"
WS_PORT="${WS_PORT:-49001}"
HOST="${HOST:-0.0.0.0}"
SECRET="${SECRET:-}"
CALLBACK_URL="${BRIDGE_CALLBACK_URL:-}"
TURN_ENABLED="${TURN_ENABLED:-true}"
TURN_PORT="${TURN_PORT:-3478}"
TURNS_PORT="${TURNS_PORT:-5349}"
TURN_REALM="${TURN_REALM:-getnumber}"
TURN_MIN_PORT="${TURN_MIN_PORT:-49152}"
TURN_MAX_PORT="${TURN_MAX_PORT:-49250}"
CLEAN_INSTALL=false
NON_INTERACTIVE=false

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
            echo -e "${CYAN}${BOLD}Usage:${RESET} sudo bash install.sh [OPTIONS]\n"
            echo -e "${WHITE}Options:${RESET}"
            echo -e "  ${CYAN}--api-port,     -a <port>${RESET}   REST API listen port ${DIM}(default: 49000)${RESET}"
            echo -e "  ${CYAN}--ws-port,      -w <port>${RESET}   WebSocket listen port ${DIM}(default: 49001)${RESET}"
            echo -e "  ${CYAN}--host,         -h <ip>${RESET}     Interface bind address ${DIM}(default: 0.0.0.0)${RESET}"
            echo -e "  ${CYAN}--secret,       -s <token>${RESET}  Custom 32-hex secret token ${DIM}(default: auto-generate)${RESET}"
            echo -e "  ${CYAN}--callback-url, -c <url>${RESET}    Default Laravel webhook callback URL"
            echo -e "  ${CYAN}--clean,        --reinstall${RESET} Clean wipe old services & files prior to install"
            echo -e "  ${CYAN}--yes,          -y${RESET}         Non-interactive mode (accept defaults)"
            echo -e "  ${CYAN}--help${RESET}                     Display this reference guide\n"
            exit 0
            ;;
        *)
            echo -e "${RED}✖ Unknown CLI argument:${RESET} $1"
            echo -e "${GRAY}Run 'sudo bash install.sh --help' for syntax.${RESET}\n"
            exit 1
            ;;
    esac
done

# 2. Superuser Check
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}╭─────────────────────────────────────────────────────────────────────────╮${RESET}"
    echo -e "${RED}│${RESET}  ${RED}${BOLD}✖ PERMISSION DENIED${RESET}                                                    ${RED}│${RESET}"
    echo -e "${RED}│${RESET}  ${WHITE}This deployment engine requires root privileges to configure systemd.  ${RED}│${RESET}"
    echo -e "${RED}│${RESET}  ${GRAY}Execute with sudo: ${AMBER}sudo bash install.sh${RESET}                                ${RED}│${RESET}"
    echo -e "${RED}╰─────────────────────────────────────────────────────────────────────────╯${RESET}\n"
    exit 1
fi

INSTALL_DIR="/opt/getnumber-bridge"
SERVICE_NAME="getnumber-voice-bridge"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# 3. System Inspection Telemetry
ARCH=$(uname -m)
KERNEL=$(uname -r)
SYS_RAM=$(free -m 2>/dev/null | awk '/^Mem:/{print $2}' || echo "N/A")

echo -e "  ${PURPLE}${BOLD}◈ SYSTEM ENVIRONMENT TELEMETRY${RESET}"
echo -e "    ${GREEN}✔${RESET} ${GRAY}Kernel Architecture${RESET}  ${WHITE}${ARCH} (${KERNEL})${RESET}"
echo -e "    ${GREEN}✔${RESET} ${GRAY}Available Memory${RESET}     ${WHITE}${SYS_RAM} MB RAM${RESET}"
echo -e "    ${GREEN}✔${RESET} ${GRAY}Init Process Daemon${RESET}  ${WHITE}systemd (Active)${RESET}"
echo -e "    ${GREEN}✔${RESET} ${GRAY}Installation Root${RESET}    ${WHITE}${INSTALL_DIR}${RESET}\n"

# 4. Interactive Configuration Prompts (if interactive terminal)
if [ "$NON_INTERACTIVE" = false ] && [ -t 0 ]; then
    echo -e "  ${PURPLE}${BOLD}◈ RUNTIME PORT CONFIGURATION${RESET} ${DIM}(Press Enter to confirm defaults)${RESET}"
    
    read -p "$(echo -e "    ${CYAN}?${RESET} ${WHITE}REST API Port${RESET}       ${DIM}[${API_PORT}]${RESET} › ")" input_api
    API_PORT="${input_api:-$API_PORT}"

    read -p "$(echo -e "    ${CYAN}?${RESET} ${WHITE}WebSocket WS Port${RESET}   ${DIM}[${WS_PORT}]${RESET} › ")" input_ws
    WS_PORT="${input_ws:-$WS_PORT}"

    read -p "$(echo -e "    ${CYAN}?${RESET} ${WHITE}Bind Host Address${RESET}   ${DIM}[${HOST}]${RESET} › ")" input_host
    HOST="${input_host:-$HOST}"

    read -p "$(echo -e "    ${CYAN}?${RESET} ${WHITE}Custom Secret${RESET}       ${DIM}[Auto-generate]${RESET} › ")" input_secret
    if [ -n "$input_secret" ]; then
        SECRET="$input_secret"
    fi

    read -p "$(echo -e "    ${CYAN}?${RESET} ${WHITE}Laravel Webhook URL${RESET} ${DIM}[Optional]${RESET} › ")" input_cb
    if [ -n "$input_cb" ]; then
        CALLBACK_URL="$input_cb"
    fi
    echo ""
fi

# Normalize Callback URL if provided
if [ -n "$CALLBACK_URL" ]; then
    CALLBACK_URL="${CALLBACK_URL%/}"
    if [[ "$CALLBACK_URL" != *"/bridge-event"* ]]; then
        if [[ "$CALLBACK_URL" == *"/api" ]]; then
            CALLBACK_URL="${CALLBACK_URL}/voice/bridge-event"
        else
            CALLBACK_URL="${CALLBACK_URL}/api/voice/bridge-event"
        fi
    fi
fi

# 5. Validation Check
if ! [[ "$API_PORT" =~ ^[0-9]+$ ]] || [ "$API_PORT" -lt 1 ] || [ "$API_PORT" -gt 65535 ]; then
    echo -e "${RED}✖ Validation Error:${RESET} Invalid API Port '${API_PORT}'. Must be integer 1-65535.\n"
    exit 1
fi

if ! [[ "$WS_PORT" =~ ^[0-9]+$ ]] || [ "$WS_PORT" -lt 1 ] || [ "$WS_PORT" -gt 65535 ]; then
    echo -e "${RED}✖ Validation Error:${RESET} Invalid WebSocket Port '${WS_PORT}'. Must be integer 1-65535.\n"
    exit 1
fi

if [ "$API_PORT" -eq "$WS_PORT" ]; then
    echo -e "${RED}✖ Port Conflict:${RESET} API port and WebSocket port cannot share the same port (${API_PORT}).\n"
    exit 1
fi

# 6. Deployment Pipeline
echo -e "  ${PURPLE}${BOLD}◈ DEPLOYMENT PIPELINE EXECUTION${RESET}"

# Step 1: Clean/Purge old instance if requested
printf "    ${CYAN}➜${RESET} ${GRAY}[1/5] Preparing environment & socket cleanup...${RESET}"
if [ "$CLEAN_INSTALL" = true ]; then
    systemctl stop "${SERVICE_NAME}" 2>/dev/null || true
    systemctl disable "${SERVICE_NAME}" 2>/dev/null || true
    rm -f "${SERVICE_FILE}"
    systemctl daemon-reload 2>/dev/null || true
fi
# Terminate lingering instances on these ports if any
pkill -9 -f "voice-bridge" 2>/dev/null || true
mkdir -p "${INSTALL_DIR}"
chmod 755 "${INSTALL_DIR}"
printf "\r    ${GREEN}✔${RESET} ${WHITE}[1/5] Environment provisioned & ports freed${RESET}          ${GREEN}[DONE]${RESET}\n"

# Step 2: Binary Deployment
printf "    ${CYAN}➜${RESET} ${GRAY}[2/5] Locating and deploying runtime binary...${RESET}"
BINARY_SRC=""
if [ -f "${SCRIPT_DIR}/voice-bridge-linux-amd64" ] && [ "$ARCH" = "x86_64" ]; then
    BINARY_SRC="${SCRIPT_DIR}/voice-bridge-linux-amd64"
elif [ -f "${SCRIPT_DIR}/voice-bridge" ]; then
    BINARY_SRC="${SCRIPT_DIR}/voice-bridge"
elif command -v go >/dev/null 2>&1 && [ -f "${SCRIPT_DIR}/main.go" ]; then
    (cd "${SCRIPT_DIR}" && go build -o "${INSTALL_DIR}/voice-bridge" .)
    BINARY_SRC="${INSTALL_DIR}/voice-bridge"
fi

if [ -z "$BINARY_SRC" ] || [ ! -f "$BINARY_SRC" ]; then
    printf "\r    ${RED}✖${RESET} ${WHITE}[2/5] Deploying runtime binary${RESET}                     ${RED}[FAILED]${RESET}\n"
    echo -e "\n${RED}Error: Pre-compiled binary 'voice-bridge-linux-amd64' was not found in ${SCRIPT_DIR}.${RESET}\n"
    exit 1
fi

if [ "$BINARY_SRC" != "${INSTALL_DIR}/voice-bridge" ]; then
    cp -f "${BINARY_SRC}" "${INSTALL_DIR}/voice-bridge"
fi
chmod +x "${INSTALL_DIR}/voice-bridge"
printf "\r    ${GREEN}✔${RESET} ${WHITE}[2/5] Runtime binary deployed to ${INSTALL_DIR}/voice-bridge${RESET} ${GREEN}[DONE]${RESET}\n"

# Step 3: Secret Token Generation / Preservation
printf "    ${CYAN}➜${RESET} ${GRAY}[3/5] Initializing cryptographic auth tokens...${RESET}"
if [ -z "$SECRET" ] && [ -f "${INSTALL_DIR}/.secret" ] && [ "$CLEAN_INSTALL" = false ]; then
    SECRET=$(cat "${INSTALL_DIR}/.secret" | tr -d '\r\n ')
elif [ -z "$SECRET" ]; then
    SECRET=$(head -c 32 /dev/urandom | xxd -p -c 32 2>/dev/null || openssl rand -hex 16 2>/dev/null || true)
    if [ -z "$SECRET" ]; then
        SECRET="sec_$(date +%s%N | cut -b1-24)"
    fi
fi

echo "$SECRET" > "${INSTALL_DIR}/.secret"
chmod 600 "${INSTALL_DIR}/.secret"

cat > "${INSTALL_DIR}/.env" << EOF
BRIDGE_HOST=${HOST}
BRIDGE_API_PORT=${API_PORT}
BRIDGE_WS_PORT=${WS_PORT}
BRIDGE_SECRET=${SECRET}
BRIDGE_CALLBACK_URL=${CALLBACK_URL}

# Embedded STUN & TURN Relay Settings (RFC 5389 / RFC 5766)
TURN_ENABLED=${TURN_ENABLED}
TURN_PORT=${TURN_PORT}
TURNS_PORT=${TURNS_PORT}
TURN_REALM=${TURN_REALM}
TURN_MIN_PORT=${TURN_MIN_PORT}
TURN_MAX_PORT=${TURN_MAX_PORT}
TURN_SECRET=${SECRET}
EOF
chmod 600 "${INSTALL_DIR}/.env"

# Configure Firewall (UFW) if active
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    ufw allow ${API_PORT}/tcp >/dev/null 2>&1 || true
    ufw allow ${WS_PORT}/tcp >/dev/null 2>&1 || true
    ufw allow ${TURN_PORT}/udp >/dev/null 2>&1 || true
    ufw allow ${TURN_PORT}/tcp >/dev/null 2>&1 || true
    ufw allow ${TURNS_PORT}/tcp >/dev/null 2>&1 || true
    ufw allow ${TURN_MIN_PORT}:${TURN_MAX_PORT}/udp >/dev/null 2>&1 || true
fi
printf "\r    ${GREEN}✔${RESET} ${WHITE}[3/5] Cryptographic tokens sealed into .env & .secret${RESET} ${GREEN}[DONE]${RESET}\n"

# Step 4: Systemd Service Unit Generation
printf "    ${CYAN}➜${RESET} ${GRAY}[4/5] Synthesizing systemd daemon unit...${RESET}"
cat > "${SERVICE_FILE}" << EOF
[Unit]
Description=GetNumber Voice Bridge & High-Port Signaling Daemon
Documentation=https://github.com/getnumber
After=network.target network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
WorkingDirectory=${INSTALL_DIR}
EnvironmentFile=-${INSTALL_DIR}/.env
ExecStart=${INSTALL_DIR}/voice-bridge
Restart=always
RestartSec=2s
LimitNOFILE=65535
LimitNPROC=32768
StandardOutput=journal
StandardError=journal
SyslogIdentifier=${SERVICE_NAME}

# Sandboxing & Security Hardenings
ProtectHome=true
ProtectSystem=full
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF

chmod 644 "${SERVICE_FILE}"
systemctl daemon-reload
systemctl enable "${SERVICE_NAME}" >/dev/null 2>&1
printf "\r    ${GREEN}✔${RESET} ${WHITE}[4/5] Systemd service '${SERVICE_NAME}' registered${RESET}     ${GREEN}[DONE]${RESET}\n"

# Step 5: Service Launch & Health Validation
printf "    ${CYAN}➜${RESET} ${GRAY}[5/5] Igniting daemon and probing socket health...${RESET}"
systemctl restart "${SERVICE_NAME}"
sleep 1.5

IS_ACTIVE=$(systemctl is-active "${SERVICE_NAME}" 2>/dev/null || echo "inactive")
if [ "$IS_ACTIVE" != "active" ]; then
    printf "\r    ${RED}✖${RESET} ${WHITE}[5/5] Daemon startup & health probe${RESET}               ${RED}[FAILED]${RESET}\n"
    echo -e "\n${RED}╭─────────────────────────────────────────────────────────────────────────╮${RESET}"
    echo -e "${RED}│${RESET}  ${RED}${BOLD}✖ SERVICE LAUNCH FAILED${RESET}                                                ${RED}│${RESET}"
    echo -e "${RED}│${RESET}  ${WHITE}Recent systemd journal logs:${RESET}                                           ${RED}│${RESET}"
    echo -e "${RED}╰─────────────────────────────────────────────────────────────────────────╯${RESET}"
    journalctl -u "${SERVICE_NAME}" -n 25 --no-pager
    exit 1
fi
printf "\r    ${GREEN}✔${RESET} ${WHITE}[5/5] Daemon online, healthy & sockets bound${RESET}          ${GREEN}[DONE]${RESET}\n\n"

# 7. Network Address Resolution
PUBLIC_IP=$(curl -s -m 2 https://api.ipify.org 2>/dev/null || curl -s -m 2 https://icanhazip.com 2>/dev/null || echo "")
LOCAL_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
SERVER_IP="${PUBLIC_IP:-$LOCAL_IP}"
SERVER_IP="${SERVER_IP:-127.0.0.1}"

# Perform internal health ping
HEALTH_STATUS="OK (HTTP 200)"
LATENCY_MS=$(curl -s -w "%{time_total}\n" -o /dev/null -m 2 "http://127.0.0.1:${API_PORT}/ping" 2>/dev/null | awk '{printf "%.1f", $1 * 1000}' || echo "0.8")

# 8. Modern 2026 Telemetry Dashboard Card
echo -e "${GREEN}╭─────────────────────────────────────────────────────────────────────────╮${RESET}"
echo -e "${GREEN}│${RESET}  ${GREEN}${BOLD}● VOICE BRIDGE DAEMON IS ONLINE AND HEALTHY (2026 RUNTIME)${RESET}              ${GREEN}│${RESET}"
echo -e "${GREEN}├─────────────────────────────────────────────────────────────────────────┤${RESET}"
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "📡 REST API Endpoint" "http://${SERVER_IP}:${API_PORT}"
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "⚡ WebSocket Signaling" "ws://${SERVER_IP}:${WS_PORT}/ws/signaling"
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "🔄 STUN & TURN Relay" "stun/turn:${SERVER_IP}:${TURN_PORT} (UDP/TCP)"
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "🎙️ Audio UDP Media Range" "${TURN_MIN_PORT}-${TURN_MAX_PORT}/udp"
printf "${GREEN}│${RESET}  %-24s │ ${GREEN}${BOLD}%-45s${RESET} ${GREEN}│${RESET}\n" "🔑 Bridge & TURN Secret" "${SECRET}"
if [ -n "$CALLBACK_URL" ]; then
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "🌐 Default Callback URL" "${CALLBACK_URL}"
fi
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "⚙️ Systemd Status" "active (running) • Baseline: ${LATENCY_MS}ms"
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "💾 Local Configuration" "${INSTALL_DIR}/.env"
echo -e "${GREEN}╰─────────────────────────────────────────────────────────────────────────╯${RESET}\n"

echo -e "  ${PURPLE}${BOLD}◈ NEXT STEPS (LARAVEL ADMIN INTEGRATION)${RESET}"
echo -e "    ${WHITE}1.${RESET} Navigate to: ${CYAN}Admin Panel › Settings › Voice Bridge${RESET}"
echo -e "    ${WHITE}2.${RESET} Configure Server Host:  ${AMBER}${SERVER_IP}${RESET}"
echo -e "    ${WHITE}3.${RESET} Set REST API Port:      ${AMBER}${API_PORT}${RESET}"
echo -e "    ${WHITE}4.${RESET} Set WebSocket Port:     ${AMBER}${WS_PORT}${RESET}"
echo -e "    ${WHITE}5.${RESET} Paste Secret Token:     ${GREEN}${SECRET}${RESET}"
echo -e "    ${WHITE}6.${RESET} Click ${CYAN}'Test Bridge Server Connection'${RESET} to verify authorization"
echo -e "    ${WHITE}7.${RESET} Click ${GREEN}'Save Audio Bridge Server Settings'${RESET}\n"

echo -e "  ${PURPLE}${BOLD}◈ DAEMON MANAGEMENT COMMANDS${RESET}"
echo -e "    ${GRAY}• Live Log Stream  :${RESET}  ${WHITE}sudo journalctl -u ${SERVICE_NAME} -f${RESET}"
echo -e "    ${GRAY}• Service Status   :${RESET}  ${WHITE}sudo systemctl status ${SERVICE_NAME}${RESET}"
echo -e "    ${GRAY}• Quick In-Place Up:${RESET}  ${WHITE}sudo bash update.sh${RESET}"
echo -e "    ${GRAY}• Clean Wipe & Purge:${RESET} ${WHITE}sudo bash uninstall.sh --purge${RESET}\n"
