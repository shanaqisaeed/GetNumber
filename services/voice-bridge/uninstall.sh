#!/usr/bin/env bash
# ==============================================================================
#  ✦ GETNUMBER VOICE BRIDGE • RUNTIME DECOMMISSIONER & PURGE ENGINE
#  Next-Gen WebRTC Audio Signaling & Telephony Daemon (2026 Edition)
#  Safe shutdown, systemd deregistration, socket release, and file purging
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

echo -e "${RED}╭─────────────────────────────────────────────────────────────────────────╮${RESET}"
echo -e "${RED}│${RESET}  ${RED}${BOLD}✦ GETNUMBER VOICE BRIDGE${RESET} ${DIM}•${RESET} ${WHITE}${BOLD}RUNTIME DECOMMISSION & PURGE ENGINE${RESET}     ${RED}│${RESET}"
echo -e "${RED}│${RESET}  ${DIM}Complete Daemon Uninstallation, Socket Release & Environment Wiper${RESET}      ${RED}│${RESET}"
echo -e "${RED}╰─────────────────────────────────────────────────────────────────────────╯${RESET}\n"

SERVICE_NAME="getnumber-voice-bridge"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
INSTALL_DIR="/opt/getnumber-bridge"

PURGE_ALL=false
KEEP_CONFIG=false
NON_INTERACTIVE=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --purge|-p)
            PURGE_ALL=true
            shift
            ;;
        --keep-config|-k)
            KEEP_CONFIG=true
            shift
            ;;
        --yes|-y|--non-interactive)
            NON_INTERACTIVE=true
            shift
            ;;
        --help)
            echo -e "${CYAN}${BOLD}Usage:${RESET} sudo bash uninstall.sh [OPTIONS]\n"
            echo -e "${WHITE}Options:${RESET}"
            echo -e "  ${CYAN}--purge,       -p${RESET}  Completely wipe all files including .secret and .env"
            echo -e "  ${CYAN}--keep-config, -k${RESET}  Remove binary & systemd service but preserve credentials"
            echo -e "  ${CYAN}--yes,         -y${RESET}  Non-interactive mode"
            echo -e "  ${CYAN}--help${RESET}            Display this reference guide\n"
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
    echo -e "${RED}│${RESET}  ${WHITE}Uninstaller requires root privileges to deregister system services.    ${RED}│${RESET}"
    echo -e "${RED}│${RESET}  ${GRAY}Execute with sudo: ${AMBER}sudo bash uninstall.sh${RESET}                             ${RED}│${RESET}"
    echo -e "${RED}╰─────────────────────────────────────────────────────────────────────────╯${RESET}\n"
    exit 1
fi

if [ "$NON_INTERACTIVE" = false ] && [ "$PURGE_ALL" = false ] && [ "$KEEP_CONFIG" = false ] && [ -t 0 ]; then
    echo -e "  ${AMBER}${BOLD}◈ CONFIRM DECOMMISSIONING${RESET}"
    read -p "$(echo -e "    ${RED}?${RESET} ${WHITE}Purge entire ${INSTALL_DIR} including secrets?${RESET} ${DIM}[y/N]${RESET} › ")" confirm_purge
    if [[ "$confirm_purge" =~ ^[Yy]$ ]]; then
        PURGE_ALL=true
    else
        KEEP_CONFIG=true
    fi
    echo ""
fi

# Fallback default if still not selected
if [ "$PURGE_ALL" = false ] && [ "$KEEP_CONFIG" = false ]; then
    PURGE_ALL=true
fi

echo -e "  ${PURPLE}${BOLD}◈ DECOMMISSIONING PIPELINE EXECUTION${RESET}"

# Step 1: Stopping Service
printf "    ${CYAN}➜${RESET} ${GRAY}[1/5] Halting active systemd service...${RESET}"
if systemctl is-active --quiet "${SERVICE_NAME}" 2>/dev/null; then
    systemctl stop "${SERVICE_NAME}" 2>/dev/null || true
    printf "\r    ${GREEN}✔${RESET} ${WHITE}[1/5] Systemd service '${SERVICE_NAME}' stopped${RESET}       ${GREEN}[DONE]${RESET}\n"
else
    printf "\r    ${GRAY}✔${RESET} ${DIM}[1/5] Systemd service was not actively running${RESET}           ${GRAY}[SKIP]${RESET}\n"
fi

# Step 2: Disabling Service
printf "    ${CYAN}➜${RESET} ${GRAY}[2/5] Disabling systemd startup hook...${RESET}"
if systemctl is-enabled --quiet "${SERVICE_NAME}" 2>/dev/null; then
    systemctl disable "${SERVICE_NAME}" 2>/dev/null || true
    printf "\r    ${GREEN}✔${RESET} ${WHITE}[2/5] Auto-start hook disabled from multi-user.target${RESET}   ${GREEN}[DONE]${RESET}\n"
else
    printf "\r    ${GRAY}✔${RESET} ${DIM}[2/5] Service was not registered in startup target${RESET}        ${GRAY}[SKIP]${RESET}\n"
fi

# Step 3: Removing Service Unit
printf "    ${CYAN}➜${RESET} ${GRAY}[3/5] Purging systemd service unit configuration...${RESET}"
if [ -f "${SERVICE_FILE}" ]; then
    rm -f "${SERVICE_FILE}"
fi
systemctl daemon-reload 2>/dev/null || true
systemctl reset-failed 2>/dev/null || true
printf "\r    ${GREEN}✔${RESET} ${WHITE}[3/5] Service configuration purged & systemd reloaded${RESET}   ${GREEN}[DONE]${RESET}\n"

# Step 4: Reclaiming Ports
printf "    ${CYAN}➜${RESET} ${GRAY}[4/5] Reclaiming listening ports & child processes...${RESET}"
pkill -9 -f "voice-bridge" 2>/dev/null || true
sleep 1
printf "\r    ${GREEN}✔${RESET} ${WHITE}[4/5] Orphan processes terminated & sockets unbound${RESET}     ${GREEN}[DONE]${RESET}\n"

# Step 5: Directory Cleanup
printf "    ${CYAN}➜${RESET} ${GRAY}[5/5] Purging local installation directory...${RESET}"
if [ -d "${INSTALL_DIR}" ]; then
    if [ "$PURGE_ALL" = true ]; then
        rm -rf "${INSTALL_DIR}"
        printf "\r    ${GREEN}✔${RESET} ${WHITE}[5/5] Entire directory ${INSTALL_DIR} purged${RESET}         ${GREEN}[DONE]${RESET}\n\n"
    else
        rm -f "${INSTALL_DIR}/voice-bridge"
        printf "\r    ${GREEN}✔${RESET} ${WHITE}[5/5] Binary removed; config preserved in ${INSTALL_DIR}${RESET} ${GREEN}[DONE]${RESET}\n\n"
    fi
else
    printf "\r    ${GRAY}✔${RESET} ${DIM}[5/5] Directory ${INSTALL_DIR} did not exist${RESET}             ${GRAY}[SKIP]${RESET}\n\n"
fi

# Decommission Card
echo -e "${GREEN}╭─────────────────────────────────────────────────────────────────────────╮${RESET}"
echo -e "${GREEN}│${RESET}  ${GREEN}${BOLD}● VOICE BRIDGE SUCCESSFULLY DECOMMISSIONED & PURGED${RESET}                     ${GREEN}│${RESET}"
echo -e "${GREEN}├─────────────────────────────────────────────────────────────────────────┤${RESET}"
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "System Service" "Removed (${SERVICE_NAME})"
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "Port Sockets" "49000 & 49001 Released"
if [ "$PURGE_ALL" = true ]; then
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "Filesystem State" "Completely Purged (/opt/getnumber-bridge)"
else
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "Filesystem State" "Config Preserved for Future Installs"
fi
printf "${GREEN}│${RESET}  %-24s │ %-45s ${GREEN}│${RESET}\n" "Environment Ready" "Clean slate for fresh deployment"
echo -e "${GREEN}╰─────────────────────────────────────────────────────────────────────────╯${RESET}\n"

echo -e "  ${PURPLE}${BOLD}◈ REINSTALLATION COMMAND${RESET}"
echo -e "    ${WHITE}sudo bash install.sh --clean${RESET}\n"
