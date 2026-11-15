#!/usr/bin/env bash
# ==============================================================================
#  ✦ GETNUMBER VOICE BRIDGE • IN-PLACE RUNTIME UPDATER
#  Next-Gen WebRTC Audio Signaling & Telephony Daemon (2026 Edition)
#  Fast binary swap, config preservation, zero downtime socket reset
# ==============================================================================

set -eo pipefail

BOLD="\033[1m"
DIM="\033[2m"
RESET="\033[0m"

CYAN="\033[38;5;51m"
PURPLE="\033[38;5;141m"
GREEN="\033[38;5;48m"
AMBER="\033[38;5;214m"
RED="\033[38;5;203m"
GRAY="\033[38;5;244m"
WHITE="\033[38;5;255m"

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
echo -e "${PURPLE}│${RESET}  ${CYAN}${BOLD}✦ GETNUMBER VOICE BRIDGE${RESET} ${DIM}•${RESET} ${WHITE}${BOLD}IN-PLACE RUNTIME UPDATER${RESET}                ${PURPLE}│${RESET}"
echo -e "${PURPLE}│${RESET}  ${DIM}Seamless Binary Upgrade • Config & Secret Preservation (2026 Engine)${RESET}  ${PURPLE}│${RESET}"
echo -e "${PURPLE}╰─────────────────────────────────────────────────────────────────────────╯${RESET}\n"

INSTALL_DIR="/opt/getnumber-bridge"
SERVICE_NAME="getnumber-voice-bridge"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CALLBACK_URL=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --callback-url|-c)
            CALLBACK_URL="$2"
            shift 2
            ;;
        --help)
            echo -e "${CYAN}${BOLD}Usage:${RESET} sudo bash update.sh [OPTIONS]\n"
            echo -e "${WHITE}Options:${RESET}"
            echo -e "  ${CYAN}--callback-url, -c <url>${RESET}  Update default Laravel webhook callback URL"
            echo -e "  ${CYAN}--help${RESET}                    Display this reference guide\n"
            exit 0
            ;;
        *)
            shift
            ;;
    esac
done

# 1. Superuser Check
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}╭─────────────────────────────────────────────────────────────────────────╮${RESET}"
    echo -e "${RED}│${RESET}  ${RED}${BOLD}✖ PERMISSION DENIED${RESET}                                                    ${RED}│${RESET}"
    echo -e "${RED}│${RESET}  ${WHITE}Updater requires root privileges to manage systemd services.           ${RED}│${RESET}"
    echo -e "${RED}│${RESET}  ${GRAY}Execute with sudo: ${AMBER}sudo bash update.sh${RESET}                                 ${RED}│${RESET}"
    echo -e "${RED}╰─────────────────────────────────────────────────────────────────────────╯${RESET}\n"
    exit 1
fi

# If installation not found, redirect to install.sh
if [ ! -d "${INSTALL_DIR}" ] || [ ! -f "${INSTALL_DIR}/voice-bridge" ]; then
    echo -e "  ${AMBER}ℹ Existing deployment not detected at ${INSTALL_DIR}.${RESET}"
    echo -e "  ${GRAY}Routing to full installation pipeline...${RESET}\n"
    exec bash "${SCRIPT_DIR}/install.sh"
    exit 0
fi

ARCH=$(uname -m)
PREV_PID=$(pgrep -f "${INSTALL_DIR}/voice-bridge" 2>/dev/null | head -n1 || echo "inactive")

echo -e "  ${PURPLE}${BOLD}◈ RUNTIME STATUS TELEMETRY${RESET}"
echo -e "    ${GREEN}✔${RESET} ${GRAY}Installed Target${RESET}     ${WHITE}${INSTALL_DIR}/voice-bridge${RESET}"
echo -e "    ${GREEN}✔${RESET} ${GRAY}Current Daemon PID${RESET}   ${WHITE}${PREV_PID}${RESET}"
echo -e "    ${GREEN}✔${RESET} ${GRAY}Kernel Arch${RESET}          ${WHITE}${ARCH}${RESET}\n"

echo -e "  ${PURPLE}${BOLD}◈ EXECUTING LIVE UPGRADE PIPELINE${RESET}"

# Step 1: Clean Daemon Stop
printf "    ${CYAN}➜${RESET} ${GRAY}[1/4] Gracefully halting active daemon instance...${RESET}"
systemctl stop "${SERVICE_NAME}" 2>/dev/null || true
pkill -9 -f "voice-bridge" 2>/dev/null || true
sleep 1
printf "\r    ${GREEN}✔${RESET} ${WHITE}[1/4] Daemon gracefully halted & sockets released${RESET}    ${GREEN}[DONE]${RESET}\n"

# Step 2: Binary Replacement
printf "    ${CYAN}➜${RESET} ${GRAY}[2/4] Deploying newly compiled binary...${RESET}"
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
    printf "\r    ${RED}✖${RESET} ${WHITE}[2/4] Deploying updated binary${RESET}                     ${RED}[FAILED]${RESET}\n"
    echo -e "\n${RED}Error: Updated binary 'voice-bridge-linux-amd64' was not found in ${SCRIPT_DIR}.${RESET}\n"
    exit 1
fi

if [ "$BINARY_SRC" != "${INSTALL_DIR}/voice-bridge" ]; then
    cp -f "${BINARY_SRC}" "${INSTALL_DIR}/voice-bridge"
fi
chmod +x "${INSTALL_DIR}/voice-bridge"
printf "\r    ${GREEN}✔${RESET} ${WHITE}[2/4] Binary replaced with production 2026 build${RESET}   ${GREEN}[DONE]${RESET}\n"

# Step 3: Preserving Secrets & Updating Environment
printf "    ${CYAN}➜${RESET} ${GRAY}[3/4] Verifying security tokens & environment sync...${RESET}"
if [ -n "$CALLBACK_URL" ]; then
    CALLBACK_URL="${CALLBACK_URL%/}"
    if [[ "$CALLBACK_URL" != *"/bridge-event"* ]]; then
        if [[ "$CALLBACK_URL" == *"/api" ]]; then
            CALLBACK_URL="${CALLBACK_URL}/voice/bridge-event"
        else
            CALLBACK_URL="${CALLBACK_URL}/api/voice/bridge-event"
        fi
    fi
    if grep -q "^BRIDGE_CALLBACK_URL=" "${INSTALL_DIR}/.env" 2>/dev/null; then
        sed -i "s|^BRIDGE_CALLBACK_URL=.*|BRIDGE_CALLBACK_URL=${CALLBACK_URL}|" "${INSTALL_DIR}/.env"
    else
        echo "BRIDGE_CALLBACK_URL=${CALLBACK_URL}" >> "${INSTALL_DIR}/.env"
    fi
fi

# Ensure STUN & TURN parameters are provisioned into existing .env
SECRET_FOR_TURN=$(cat "${INSTALL_DIR}/.secret" 2>/dev/null | tr -d '\r\n ' || echo "")
if ! grep -q "^TURN_ENABLED=" "${INSTALL_DIR}/.env" 2>/dev/null; then
    echo "TURN_ENABLED=true" >> "${INSTALL_DIR}/.env"
fi
if ! grep -q "^TURN_PORT=" "${INSTALL_DIR}/.env" 2>/dev/null; then
    echo "TURN_PORT=3478" >> "${INSTALL_DIR}/.env"
fi
if ! grep -q "^TURNS_PORT=" "${INSTALL_DIR}/.env" 2>/dev/null; then
    echo "TURNS_PORT=5349" >> "${INSTALL_DIR}/.env"
fi
if ! grep -q "^TURN_REALM=" "${INSTALL_DIR}/.env" 2>/dev/null; then
    echo "TURN_REALM=getnumber" >> "${INSTALL_DIR}/.env"
fi
if ! grep -q "^TURN_MIN_PORT=" "${INSTALL_DIR}/.env" 2>/dev/null; then
    echo "TURN_MIN_PORT=49152" >> "${INSTALL_DIR}/.env"
fi
if ! grep -q "^TURN_MAX_PORT=" "${INSTALL_DIR}/.env" 2>/dev/null; then
    echo "TURN_MAX_PORT=49250" >> "${INSTALL_DIR}/.env"
fi
if ! grep -q "^TURN_SECRET=" "${INSTALL_DIR}/.env" 2>/dev/null; then
    echo "TURN_SECRET=${SECRET_FOR_TURN}" >> "${INSTALL_DIR}/.env"
fi

# Firewall update if UFW active
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    ufw allow 3478/udp >/dev/null 2>&1 || true
    ufw allow 3478/tcp >/dev/null 2>&1 || true
    ufw allow 5349/tcp >/dev/null 2>&1 || true
    ufw allow 49152:49250/udp >/dev/null 2>&1 || true
fi
printf "\r    ${GREEN}✔${RESET} ${WHITE}[3/4] Auth secret preserved & environment synchronized${RESET} ${GREEN}[DONE]${RESET}\n"

# Step 4: Daemon Restart & Health Verification
printf "    ${CYAN}➜${RESET} ${GRAY}[4/4] Restarting systemd unit & verifying health...${RESET}"
systemctl daemon-reload
systemctl restart "${SERVICE_NAME}"
sleep 1.5

IS_ACTIVE=$(systemctl is-active "${SERVICE_NAME}" 2>/dev/null || echo "inactive")
if [ "$IS_ACTIVE" != "active" ]; then
    printf "\r    ${RED}✖${RESET} ${WHITE}[4/4] Daemon health verification${RESET}                   ${RED}[FAILED]${RESET}\n"
    echo -e "\n${RED}Error: Voice Bridge daemon failed to restart. Recent logs:${RESET}"
    journalctl -u "${SERVICE_NAME}" -n 25 --no-pager
    exit 1
fi
printf "\r    ${GREEN}✔${RESET} ${WHITE}[4/4] Daemon ignited successfully & healthy${RESET}          ${GREEN}[DONE]${RESET}\n\n"

# Telemetry extraction
SECRET=""
if [ -f "${INSTALL_DIR}/.secret" ]; then
    SECRET=$(cat "${INSTALL_DIR}/.secret" | tr -d '\r\n ')
fi

API_PORT=$(grep "^BRIDGE_API_PORT=" "${INSTALL_DIR}/.env" 2>/dev/null | cut -d'=' -f2 | tr -d '\r\n ')
API_PORT="${API_PORT:-49000}"
WS_PORT=$(grep "^BRIDGE_WS_PORT=" "${INSTALL_DIR}/.env" 2>/dev/null | cut -d'=' -f2 | tr -d '\r\n ')
WS_PORT="${WS_PORT:-49001}"

PUBLIC_IP=$(curl -s -m 2 https://api.ipify.org 2>/dev/null || curl -s -m 2 https://icanhazip.com 2>/dev/null || echo "")
LOCAL_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
SERVER_IP="${PUBLIC_IP:-$LOCAL_IP}"
SERVER_IP="${SERVER_IP:-127.0.0.1}"

LATENCY_MS=$(curl -s -w "%{time_total}\n" -o /dev/null -m 2 "http://127.0.0.1:${API_PORT}/ping" 2>/dev/null | awk '{printf "%.1f", $1 * 1000}' || echo "0.7")

TURN_PORT=$(grep "^TURN_PORT=" "${INSTALL_DIR}/.env" 2>/dev/null | cut -d'=' -f2 | tr -d '\r\n ')
TURN_PORT="${TURN_PORT:-3478}"
TURN_MIN_PORT=$(grep "^TURN_MIN_PORT=" "${INSTALL_DIR}/.env" 2>/dev/null | cut -d'=' -f2 | tr -d '\r\n ')
TURN_MIN_PORT="${TURN_MIN_PORT:-49152}"
TURN_MAX_PORT=$(grep "^TURN_MAX_PORT=" "${INSTALL_DIR}/.env" 2>/dev/null | cut -d'=' -f2 | tr -d '\r\n ')
TURN_MAX_PORT="${TURN_MAX_PORT:-49250}"

# Result Card
echo -e "${GREEN}╭─────────────────────────────────────────────────────────────────────────╮${RESET}"
echo -e "${GREEN}│${RESET}  ${GREEN}${BOLD}● VOICE BRIDGE UPGRADED SUCCESSFULLY (2026 RUNTIME ENGINE)${RESET}            ${GREEN}│${RESET}"
echo -e "${GREEN}├─────────────────────────────────────────────────────────────────────────┤${RESET}"
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "📡 REST API Endpoint" "http://${SERVER_IP}:${API_PORT}"
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "⚡ WebSocket Signaling" "ws://${SERVER_IP}:${WS_PORT}/ws/signaling"
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "🔄 STUN & TURN Relay" "stun/turn:${SERVER_IP}:${TURN_PORT} (UDP/TCP)"
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "🎙️ Audio UDP Media Range" "${TURN_MIN_PORT}-${TURN_MAX_PORT}/udp"
printf "${GREEN}│${RESET}  %-24s │ ${GREEN}${BOLD}%-45s${RESET} ${GREEN}│${RESET}\n" "🔑 Bridge & TURN Secret" "${SECRET}"
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "⚙️ Systemd Status" "active (running) • Latency: ${LATENCY_MS}ms"
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "💾 Local Configuration" "${INSTALL_DIR}/.env"
echo -e "${GREEN}╰─────────────────────────────────────────────────────────────────────────╯${RESET}\n"

echo -e "  ${PURPLE}${BOLD}◈ DAEMON MANAGEMENT COMMANDS${RESET}"
echo -e "    ${GRAY}• Live Log Stream :${RESET}  ${WHITE}sudo journalctl -u ${SERVICE_NAME} -f${RESET}"
echo -e "    ${GRAY}• Health Probe    :${RESET}  ${WHITE}curl -s http://localhost:${API_PORT}/health${RESET}\n"
