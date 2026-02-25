#!/bin/bash
set -e

BINARY_NAME="scanarr-watcher"
INSTALL_DIR="/usr/local/bin"
CONFIG_DIR="/etc/scanarr"
SERVICE_FILE="/etc/systemd/system/${BINARY_NAME}.service"

echo "=== Scanarr Watcher Installer ==="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "Error: Please run as root (sudo ./install.sh)"
    exit 1
fi

# Check Go is installed
if ! command -v go &> /dev/null; then
    echo "Error: Go is not installed. Please install Go 1.22+ first."
    exit 1
fi

GO_VERSION=$(go version | awk '{print $3}' | sed 's/go//')
echo "Go version: $GO_VERSION"

# Build the binary
echo ""
echo "Building ${BINARY_NAME}..."
cd "$(dirname "$0")"
go build -o "${BINARY_NAME}" -ldflags="-s -w" .
echo "Build successful."

# Create scanarr user if it doesn't exist
if ! id -u scanarr &>/dev/null; then
    echo ""
    echo "Creating scanarr system user..."
    useradd --system --no-create-home --shell /usr/sbin/nologin scanarr
fi

# Install binary
echo ""
echo "Installing binary to ${INSTALL_DIR}..."
cp "${BINARY_NAME}" "${INSTALL_DIR}/${BINARY_NAME}"
chmod 755 "${INSTALL_DIR}/${BINARY_NAME}"
rm "${BINARY_NAME}"

# Install config
echo ""
echo "Setting up configuration..."
mkdir -p "${CONFIG_DIR}"
if [ ! -f "${CONFIG_DIR}/watcher.env" ]; then
    cp watcher.env.example "${CONFIG_DIR}/watcher.env"
    chmod 600 "${CONFIG_DIR}/watcher.env"
    chown scanarr:scanarr "${CONFIG_DIR}/watcher.env"
    echo "Configuration file created at ${CONFIG_DIR}/watcher.env"
    echo ">>> IMPORTANT: Edit ${CONFIG_DIR}/watcher.env with your settings before starting the service."
else
    echo "Configuration file already exists at ${CONFIG_DIR}/watcher.env (not overwritten)."
fi

# Install systemd service
echo ""
echo "Installing systemd service..."
cp scanarr-watcher.service "${SERVICE_FILE}"
systemctl daemon-reload

echo ""
echo "=== Installation complete ==="
echo ""
echo "Next steps:"
echo "  1. Edit /etc/scanarr/watcher.env with your configuration"
echo "  2. Start the service: sudo systemctl start ${BINARY_NAME}"
echo "  3. Enable at boot:    sudo systemctl enable ${BINARY_NAME}"
echo "  4. Check status:      sudo systemctl status ${BINARY_NAME}"
echo "  5. View logs:         sudo journalctl -u ${BINARY_NAME} -f"
