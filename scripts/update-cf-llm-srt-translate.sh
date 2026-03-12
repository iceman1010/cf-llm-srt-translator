#!/usr/bin/env bash
#
# Auto-update script for cf-llm-srt-translate PHAR
# Pulls the latest release from GitHub and installs it.
#
# Usage:
#   update-cf-llm-srt-translate          # check & update
#
# Cron (every 7 days at 03:00):
#   0 3 */7 * * /usr/local/bin/update-cf-llm-srt-translate
#
# Requires: curl, jq, sudo

set -euo pipefail

REPO="iceman1010/cf-llm-srt-translator"
INSTALL_PATH="/usr/local/bin/cf-llm-srt-translate"
VERSION_FILE="/usr/local/lib/cf-llm-srt-translate.version"
ASSET_NAME="cf-llm-srt-translate.phar"
API_URL="https://api.github.com/repos/${REPO}/releases/latest"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

# Check dependencies
for cmd in curl jq sudo; do
    if ! command -v "$cmd" &>/dev/null; then
        log "ERROR: Required command '$cmd' not found"
        exit 1
    fi
done

# Fetch latest release info
log "Checking for updates..."
release_json=$(curl -fsSL "$API_URL") || {
    log "ERROR: Failed to fetch release info from GitHub"
    exit 1
}

latest_tag=$(echo "$release_json" | jq -r '.tag_name')
if [[ -z "$latest_tag" || "$latest_tag" == "null" ]]; then
    log "ERROR: Could not extract tag from release JSON"
    exit 1
fi

# Read current installed version
current_version="none"
if [[ -f "$VERSION_FILE" ]]; then
    current_version=$(cat "$VERSION_FILE")
fi

log "Installed: ${current_version} | Latest: ${latest_tag}"

if [[ "$current_version" == "$latest_tag" ]]; then
    log "Already up to date."
    exit 0
fi

# Extract download URL for the PHAR asset
download_url=$(echo "$release_json" | jq -r ".assets[] | select(.name == \"${ASSET_NAME}\") | .browser_download_url")
if [[ -z "$download_url" || "$download_url" == "null" ]]; then
    log "ERROR: PHAR asset '${ASSET_NAME}' not found in release ${latest_tag}"
    exit 1
fi

# Download to a temp file
tmp_file=$(mktemp)
trap 'rm -f "$tmp_file"' EXIT

log "Downloading ${ASSET_NAME} from ${latest_tag}..."
curl -fsSL -o "$tmp_file" "$download_url" || {
    log "ERROR: Download failed"
    exit 1
}

# Verify it's a valid PHAR (starts with #!/usr/bin/env php or has PHP signature)
if ! head -c 64 "$tmp_file" | grep -q 'php'; then
    log "ERROR: Downloaded file does not appear to be a valid PHAR"
    exit 1
fi

# Install
log "Installing to ${INSTALL_PATH}..."
sudo install -m 0755 "$tmp_file" "$INSTALL_PATH"

# Update version file
sudo mkdir -p "$(dirname "$VERSION_FILE")"
echo "$latest_tag" | sudo tee "$VERSION_FILE" > /dev/null

log "Updated to ${latest_tag}"
