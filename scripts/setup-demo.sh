#!/usr/bin/env bash
# =============================================================================
# setup-demo.sh — Build or rebuild the Audiobook Librarian demo instance
#
# What this does:
#   1. Prompts for the book storage directory (BOOK_STORAGE_PATH)
#   2. Updates .env with DEMO_MODE=true and BOOK_STORAGE_PATH
#   3. Runs database migrations
#   4. Seeds the database (genres, badges, demo users admin/admin and user/user)
#   5. Imports the 30 classic LibriVox books into the library:
#       - Fetches metadata and chapters from the LibriVox API
#       - Creates directory structure under BOOK_STORAGE_PATH
#       - Downloads CC0 cover images (Standard Ebooks / Open Library)
#       - Writes librarian.json per book
#       - Sets source='local' so books are treated as regular library books
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SERVER_ROOT="$(dirname "$SCRIPT_DIR")"
ENV_FILE="$SERVER_ROOT/.env"

echo "============================================="
echo " Audiobook Librarian — Demo Setup"
echo "============================================="
echo ""
echo "Server root: $SERVER_ROOT"
echo ""

# ---------------------------------------------------------------------------
# 1. Prompt for BOOK_STORAGE_PATH
# ---------------------------------------------------------------------------

# Read existing value if present
CURRENT_BOOK_ROOT=""
if [[ -f "$ENV_FILE" ]]; then
    CURRENT_BOOK_ROOT=$(grep -E '^BOOK_STORAGE_PATH=' "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'" | xargs 2>/dev/null || true)
fi

DEFAULT_PATH="${CURRENT_BOOK_ROOT:-/media/archive/demo-books}"

echo "Enter the directory where demo audiobook files will be stored."
echo "(Audio files are not downloaded — only cover images and metadata are created.)"
echo ""
read -rp "Book storage path [$DEFAULT_PATH]: " BOOK_ROOT_INPUT
BOOK_ROOT="${BOOK_ROOT_INPUT:-$DEFAULT_PATH}"

echo ""
echo "Book storage path: $BOOK_ROOT"
echo ""
read -rp "Continue? [y/N] " CONFIRM
if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
    echo "Aborted."
    exit 0
fi

echo ""

# ---------------------------------------------------------------------------
# 2. Update .env
# ---------------------------------------------------------------------------

echo "--- Updating .env ---"

set_env_var() {
    local key="$1" val="$2"
    if grep -qE "^${key}=" "$ENV_FILE" 2>/dev/null; then
        # Replace existing line
        sed -i "s|^${key}=.*|${key}=${val}|" "$ENV_FILE"
    else
        echo "${key}=${val}" >> "$ENV_FILE"
    fi
}

set_env_var "DEMO_MODE" "true"
set_env_var "BOOK_STORAGE_PATH" "$BOOK_ROOT"

echo "DEMO_MODE=true"
echo "BOOK_STORAGE_PATH=$BOOK_ROOT"
echo ""

# ---------------------------------------------------------------------------
# 3. Create directory
# ---------------------------------------------------------------------------

if [[ ! -d "$BOOK_ROOT" ]]; then
    echo "--- Creating book storage directory: $BOOK_ROOT ---"
    mkdir -p "$BOOK_ROOT"
fi

# ---------------------------------------------------------------------------
# 4. Artisan: migrate + seed
# ---------------------------------------------------------------------------

cd "$SERVER_ROOT"

echo "--- Running database migrations ---"
php artisan migrate --force

echo ""
echo "--- Seeding database (genres, badges, demo users) ---"
php artisan db:seed --force

echo ""

# ---------------------------------------------------------------------------
# 5. Import demo books
# ---------------------------------------------------------------------------

echo "--- Importing 30 demo books ---"
echo "(Downloads metadata from LibriVox API, cover images, and MP3 audio files)"
echo "(Audio download may take a while depending on connection speed)"
echo ""

python3 "$SCRIPT_DIR/import-demo-books.py"

echo ""

# ---------------------------------------------------------------------------
# 6. Pre-generate chunk hashes for download manifests
# ---------------------------------------------------------------------------

echo "--- Pre-generating file chunk hashes ---"
php artisan books:cache-file-chunk-hashes --all

echo ""

# ---------------------------------------------------------------------------
# 7. Clear caches
# ---------------------------------------------------------------------------

echo "--- Clearing application cache ---"
php artisan config:cache
php artisan cache:clear

echo ""
echo "============================================="
echo " Demo setup complete!"
echo "============================================="
echo ""
echo " Demo credentials:"
echo "   Admin:  admin@example.com / admin"
echo "   User:   user@example.com  / user"
echo ""
echo " Book storage: $BOOK_ROOT"
echo ""
