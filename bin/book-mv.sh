#!/bin/bash

# Enhanced mv command for book directories (bkmv replacement)
# Automatically updates database when moving directories in book root
# Falls back to regular mv if not in book root
# Supports all standard mv options and multiple sources

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Separate mv options and positional args (like bkmv does)
opts=()
args=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        -*) opts+=("$1"); shift ;;
        *) args+=("$1"); shift ;;
    esac
done

# If less than 2 positional args, fallback to mv
if [[ ${#args[@]} -lt 2 ]]; then
    mv "${opts[@]}" "${args[@]}"
    exit $?
fi

# All but the last positional arg are sources
sources=("${args[@]:0:${#args[@]}-1}")
dest="${args[-1]}"

# Load environment to get BOOK_STORAGE_PATH
if [ -f "$PROJECT_ROOT/.env" ]; then
    export $(grep -v '^#' "$PROJECT_ROOT/.env" | grep BOOK_STORAGE_PATH | xargs)
fi

# Check if BOOK_STORAGE_PATH is set
if [ -z "$BOOK_STORAGE_PATH" ]; then
    mv "${opts[@]}" "${args[@]}"
    exit $?
fi

# Fast check: is ANY source in book root?
BOOK_ROOT=$(realpath "$BOOK_STORAGE_PATH" 2>/dev/null || echo "$BOOK_STORAGE_PATH")
is_book=0

for src in "${sources[@]}"; do
    # Resolve to absolute path if possible
    abs_src=$(readlink -f -- "$src" 2>/dev/null || realpath "$src" 2>/dev/null || echo "$src")
    if [[ "$abs_src" == "$BOOK_ROOT"* ]]; then
        is_book=1
        break
    fi
done

# If no sources are in book root, use regular mv
if [[ $is_book -eq 0 ]]; then
    mv "${opts[@]}" "${args[@]}"
    exit $?
fi

# At least one source is in book root, use Laravel command
cd "$PROJECT_ROOT" || exit 1

# Check if artisan exists
if [ ! -f "artisan" ]; then
    echo -e "${RED}Laravel artisan not found, falling back to mv${NC}" >&2
    mv "${opts[@]}" "${args[@]}"
    exit $?
fi

# Run the Laravel command with all arguments
php artisan books:move "${args[@]}"
EXIT_CODE=$?

# Exit code 2 means "not a book move, use regular mv"
if [ $EXIT_CODE -eq 2 ]; then
    mv "${opts[@]}" "${args[@]}"
    exit $?
fi

# Exit code 0 means success
if [ $EXIT_CODE -eq 0 ]; then
    exit 0
fi

# Any other exit code means failure
echo -e "${RED}Enhanced move failed${NC}" >&2
exit $EXIT_CODE
