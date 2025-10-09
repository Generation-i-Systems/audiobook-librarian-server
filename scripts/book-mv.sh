#!/bin/bash

# Enhanced mv command for book directories
# Automatically updates database when moving directories in book root
# Falls back to regular mv if not in book root or if update fails

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Check if we have at least 2 arguments
if [ $# -lt 2 ]; then
    echo -e "${RED}Usage: book-mv <source> <destination> [options]${NC}"
    echo "Options:"
    echo "  --dry-run    Show what would be done without making changes"
    echo "  --no-db      Only move files, do not update database"
    echo "  --force-mv   Use regular mv even if in book root"
    exit 1
fi

SOURCE="$1"
DESTINATION="$2"
shift 2

# Parse options
DRY_RUN=""
NO_DB=""
FORCE_MV=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --dry-run)
            DRY_RUN="--dry-run"
            shift
            ;;
        --no-db)
            NO_DB="--no-db"
            shift
            ;;
        --force-mv)
            FORCE_MV=true
            shift
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            exit 1
            ;;
    esac
done

# If force-mv, just use regular mv
if [ "$FORCE_MV" = true ]; then
    echo -e "${YELLOW}Using regular mv (--force-mv)${NC}"
    mv "$SOURCE" "$DESTINATION"
    exit $?
fi

# Check if source exists
if [ ! -e "$SOURCE" ]; then
    echo -e "${RED}Error: Source does not exist: $SOURCE${NC}"
    exit 1
fi

# Check if source is a directory
if [ ! -d "$SOURCE" ]; then
    echo -e "${YELLOW}Source is not a directory, using regular mv${NC}"
    mv "$SOURCE" "$DESTINATION"
    exit $?
fi

# Load environment to get BOOK_STORAGE_PATH
if [ -f "$PROJECT_ROOT/.env" ]; then
    export $(grep -v '^#' "$PROJECT_ROOT/.env" | grep BOOK_STORAGE_PATH | xargs)
fi

# Check if BOOK_STORAGE_PATH is set
if [ -z "$BOOK_STORAGE_PATH" ]; then
    echo -e "${YELLOW}BOOK_STORAGE_PATH not set, using regular mv${NC}"
    mv "$SOURCE" "$DESTINATION"
    exit $?
fi

# Resolve absolute paths
SOURCE_ABS=$(realpath "$SOURCE" 2>/dev/null || echo "$SOURCE")
BOOK_ROOT=$(realpath "$BOOK_STORAGE_PATH" 2>/dev/null || echo "$BOOK_STORAGE_PATH")

# Fast check: is source in book root?
if [[ ! "$SOURCE_ABS" == "$BOOK_ROOT"* ]]; then
    echo -e "${YELLOW}Source not in book root, using regular mv${NC}"
    mv "$SOURCE" "$DESTINATION"
    exit $?
fi

# Source is in book root, use Laravel command
echo -e "${GREEN}Source is in book root, using enhanced move...${NC}"

# Run the Laravel command
cd "$PROJECT_ROOT"

if [ -n "$DRY_RUN" ]; then
    php artisan books:move-directory "$SOURCE" "$DESTINATION" --dry-run $NO_DB
    EXIT_CODE=$?
else
    php artisan books:move-directory "$SOURCE" "$DESTINATION" $NO_DB
    EXIT_CODE=$?
fi

# If command failed, offer to use regular mv
if [ $EXIT_CODE -ne 0 ]; then
    echo -e "${RED}Enhanced move failed${NC}"
    read -p "Use regular mv instead? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        mv "$SOURCE" "$DESTINATION"
        exit $?
    else
        exit $EXIT_CODE
    fi
fi

exit 0
