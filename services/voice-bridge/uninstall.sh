#!/usr/bin/env bash
# ==============================================================================
# GetNumber Voice Bridge - Complete Uninstaller & Clean Wiper
# Completely stops, disables, and wipes the Voice Bridge daemon & systemd service
# ==============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

SERVICE_NAME="getnumber-voice-bridge"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
INSTALL_DIR="/opt/getnumber-bridge"

echo -e "${CYAN}==================================================================${NC}"
echo -e "${RED}🛑 GetNumber Voice Bridge Uninstaller & Cleaner${NC}"
echo -e "${CYAN}==================================================================${NC}"

# 1. Root privilege check
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}❌ Please run this script with sudo or as root:${NC}"
    echo "   sudo bash uninstall.sh"
    exit 1
fi

# 2. Parse flags
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
            echo "Usage: sudo bash uninstall.sh [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --purge, -p        Completely remove all files including .secret and .env"
            echo "  --keep-config, -k  Remove binary and service but preserve .secret and .env"
            echo "  --yes, -y          Run without interactive confirmation"
            echo "  --help             Show this help message"
            exit 0
            ;;
        *)
            echo -e "${YELLOW}Unknown option: $1 (ignoring)${NC}"
            shift
            ;;
    esac
done

if [ "$NON_INTERACTIVE" = false ] && [ "$PURGE_ALL" = false ] && [ "$KEEP_CONFIG" = false ] && [ -t 0 ]; then
    echo -e "${YELLOW}⚠️  This script will stop and completely remove the '${SERVICE_NAME}' system service.${NC}"
    read -p "Do you want to completely purge /opt/getnumber-bridge including .secret? (y/N): " confirm_purge
    if [[ "$confirm_purge" =~ ^[Yy]$ ]]; then
        PURGE_ALL=true
    else
        KEEP_CONFIG=true
    fi
fi

# 3. Stop systemd service if running
echo -e "\n${BLUE}[1/5] Stopping systemd service (${SERVICE_NAME})...${NC}"
if systemctl is-active --quiet "${SERVICE_NAME}" 2>/dev/null; then
    systemctl stop "${SERVICE_NAME}" 2>/dev/null || true
    echo -e "${GREEN}✓ Service stopped successfully.${NC}"
else
    echo -e "${YELLOW}ℹ Service was not currently running.${NC}"
fi

# 4. Disable systemd service
echo -e "\n${BLUE}[2/5] Disabling systemd service...${NC}"
if systemctl is-enabled --quiet "${SERVICE_NAME}" 2>/dev/null; then
    systemctl disable "${SERVICE_NAME}" 2>/dev/null || true
    echo -e "${GREEN}✓ Service disabled from startup.${NC}"
else
    echo -e "${YELLOW}ℹ Service was not enabled.${NC}"
fi

# 5. Remove systemd service file
echo -e "\n${BLUE}[3/5] Removing systemd unit configuration...${NC}"
if [ -f "${SERVICE_FILE}" ]; then
    rm -f "${SERVICE_FILE}"
    echo -e "${GREEN}✓ Removed ${SERVICE_FILE}${NC}"
fi

systemctl daemon-reload 2>/dev/null || true
systemctl reset-failed 2>/dev/null || true

# 6. Kill any orphan voice-bridge processes
echo -e "\n${BLUE}[4/5] Checking for any orphan voice-bridge processes...${NC}"
pkill -9 -f "voice-bridge" 2>/dev/null || true
# Wait a moment to ensure ports are released
sleep 1

# 7. Clean installation directory
echo -e "\n${BLUE}[5/5] Cleaning installation files in ${INSTALL_DIR}...${NC}"
if [ -d "${INSTALL_DIR}" ]; then
    if [ "$PURGE_ALL" = true ]; then
        rm -rf "${INSTALL_DIR}"
        echo -e "${GREEN}✓ Completely removed ${INSTALL_DIR}${NC}"
    elif [ "$KEEP_CONFIG" = true ]; then
        rm -f "${INSTALL_DIR}/voice-bridge"
        echo -e "${GREEN}✓ Removed binary. Preserved configuration & secret in ${INSTALL_DIR}${NC}"
    else
        # Default behavior: remove binary, preserve .secret/.env
        rm -f "${INSTALL_DIR}/voice-bridge"
        echo -e "${GREEN}✓ Removed binary. Preserved configuration & secret in ${INSTALL_DIR}${NC}"
    fi
else
    echo -e "${YELLOW}ℹ Directory ${INSTALL_DIR} does not exist.${NC}"
fi

echo -e "\n${CYAN}==================================================================${NC}"
echo -e "${GREEN}🎉 GETNUMBER VOICE BRIDGE UNINSTALLED & CLEANED SUCCESSFULLY!${NC}"
echo -e "${CYAN}==================================================================${NC}"
echo -e "✓ Service ${SERVICE_NAME} has been completely removed."
echo -e "✓ All ports (49000 / 49001 or custom) have been freed."
echo -e "✓ Ready for a clean re-installation whenever you wish."
echo -e "${CYAN}==================================================================${NC}\n"
