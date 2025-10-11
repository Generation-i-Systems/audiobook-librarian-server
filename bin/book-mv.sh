#!/bin/bash

# Enhanced mv command for book directories (bkmv replacement)
# Automatically updates database when moving directories in book root
# Falls back to regular mv if not in book root
# Supports all standard mv options and multiple sources

# Save the original working directory FIRST before any cd commands
ORIGINAL_DIR="$(pwd)"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Debug mode flag
DEBUG=0
DRY_RUN=0

# Debug function
debug() {
    if [[ $DEBUG -eq 1 ]]; then
        echo -e "${BLUE}[DEBUG]${NC} $*" >&2
    fi
}

# Separate mv options and positional args (like bkmv does)
opts=()
args=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        -v|--verbose)
            DEBUG=1
            shift
            ;;
        -n|--dry-run)
            DRY_RUN=1
            shift
            ;;
        -nv|-vn)
            # Handle combined -nv or -vn flags
            DRY_RUN=1
            DEBUG=1
            shift
            ;;
        -*)
            # Check if this is a combined flag containing n or v
            if [[ "$1" =~ n ]]; then
                DRY_RUN=1
            fi
            if [[ "$1" =~ v ]]; then
                DEBUG=1
            fi
            # If it contains other flags, pass them to mv
            if [[ ! "$1" =~ ^-[nv]+$ ]]; then
                opts+=("$1")
            fi
            shift
            ;;
        *)
            args+=("$1")
            shift
            ;;
    esac
done

debug "Script started"
debug "Original directory: $ORIGINAL_DIR"
debug "Project root: $PROJECT_ROOT"
debug "Options: ${opts[*]}"
debug "Arguments: ${args[*]}"

if [[ $DRY_RUN -eq 1 ]]; then
    echo -e "${YELLOW}=== DRY RUN MODE ===${NC}"
    DEBUG=1  # Enable debug output for dry-run
fi

# If less than 2 positional args, fallback to mv
if [[ ${#args[@]} -lt 2 ]]; then
    debug "Less than 2 arguments, falling back to regular mv"
    if [[ $DRY_RUN -eq 1 ]]; then
        echo -e "${YELLOW}Would execute: mv ${opts[*]} ${args[*]}${NC}"
        exit 0
    fi
    mv "${opts[@]}" "${args[@]}"
    exit $?
fi

# All but the last positional arg are sources
sources=("${args[@]:0:${#args[@]}-1}")
dest="${args[-1]}"

debug "Sources: ${sources[*]}"
debug "Destination: $dest"

# Load environment to get BOOK_STORAGE_PATH
if [ -f "$PROJECT_ROOT/.env" ]; then
    debug "Loading environment from $PROJECT_ROOT/.env"
    export $(grep -v '^#' "$PROJECT_ROOT/.env" | grep BOOK_STORAGE_PATH | xargs)
fi

debug "BOOK_STORAGE_PATH: $BOOK_STORAGE_PATH"

# Check if BOOK_STORAGE_PATH is set
if [ -z "$BOOK_STORAGE_PATH" ]; then
    debug "BOOK_STORAGE_PATH not set, falling back to regular mv"
    if [[ $DRY_RUN -eq 1 ]]; then
        echo -e "${YELLOW}Would execute: mv ${opts[*]} ${args[*]}${NC}"
        exit 0
    fi
    mv "${opts[@]}" "${args[@]}"
    exit $?
fi

# Fast check: is ANY source in book root?
BOOK_ROOT=$(realpath "$BOOK_STORAGE_PATH" 2>/dev/null || echo "$BOOK_STORAGE_PATH")
BOOK_ROOT_INODE=$(stat -c %i "$BOOK_ROOT" 2>/dev/null)
is_book=0

debug "Book root: $BOOK_ROOT"
debug "Book root inode: $BOOK_ROOT_INODE"

# Check if current directory is within book root (including via bind mounts)
CWD="$ORIGINAL_DIR"
CWD_INODE=$(stat -c %i "$CWD" 2>/dev/null)

debug "Current working directory: $CWD"
debug "CWD inode: $CWD_INODE"

for src in "${sources[@]}"; do
    debug "Checking source: $src"

    # Resolve to absolute path if possible (from original directory)
    abs_src=$(cd "$ORIGINAL_DIR" && readlink -f -- "$src" 2>/dev/null || cd "$ORIGINAL_DIR" && realpath "$src" 2>/dev/null || echo "$src")

    debug "  Absolute path: $abs_src"

    # Check if path is under book root
    if [[ "$abs_src" == "$BOOK_ROOT"* ]]; then
        debug "  ✓ Source is under book root (path match)"
        is_book=1
        break
    fi

    # Check if source's parent directory has same inode as book root (bind mount detection)
    if [ -e "$src" ]; then
        src_parent=$(dirname "$abs_src")
        debug "  Checking parent directory tree for bind mount..."
        # Walk up the directory tree checking inodes
        while [ "$src_parent" != "/" ]; do
            parent_inode=$(stat -c %i "$src_parent" 2>/dev/null)
            debug "    $src_parent (inode: $parent_inode)"
            if [ "$parent_inode" = "$BOOK_ROOT_INODE" ]; then
                debug "  ✓ Source is under book root (inode match - bind mount detected)"
                is_book=1
                break 2
            fi
            src_parent=$(dirname "$src_parent")
        done
    fi
    debug "  ✗ Source is not under book root"
done

# If no sources are in book root, use regular mv with original working directory
if [[ $is_book -eq 0 ]]; then
    debug "No sources in book root, falling back to regular mv"
    if [[ $DRY_RUN -eq 1 ]]; then
        echo -e "${YELLOW}Would execute: mv ${opts[*]} ${args[*]}${NC}"
        exit 0
    fi
    mv "${opts[@]}" "${args[@]}"
    exit $?
fi

debug "At least one source is in book root, using Laravel command"

if [[ $DRY_RUN -eq 1 ]]; then
    echo -e "${YELLOW}Sources are in book root - will use Laravel database-aware move${NC}"
fi

# At least one source is in book root, use Laravel command
# Resolve all paths to absolute before cd (ORIGINAL_DIR already saved at top of script)
resolved_sources=()
debug "Resolving source paths to absolute..."
for src in "${sources[@]}"; do
    # For sources, they must exist, so use realpath (check existence in original directory)
    if [ -e "$ORIGINAL_DIR/$src" ] || [ -e "$src" ]; then
        abs_src=$(cd "$ORIGINAL_DIR" && realpath "$src" 2>/dev/null)
        resolved_sources+=("$abs_src")
        debug "  $src -> $abs_src"
    else
        echo -e "${RED}Source does not exist: $src${NC}" >&2
        debug "  ✗ Source not found: $src"
        exit 1
    fi
done

# Resolve destination to absolute path
# If destination exists, resolve it
# If it doesn't exist, construct absolute path from current directory
debug "Resolving destination path..."
if [ -e "$dest" ]; then
    abs_dest=$(realpath "$dest" 2>/dev/null)
    debug "  Destination exists: $dest -> $abs_dest"
else
    # Construct absolute path: if dest is relative, prepend current directory
    if [[ "$dest" == /* ]]; then
        abs_dest="$dest"
        debug "  Destination (absolute): $dest -> $abs_dest"
    else
        abs_dest="$ORIGINAL_DIR/$dest"
        debug "  Destination (relative): $dest -> $abs_dest"
    fi
fi

debug "Changing to project root: $PROJECT_ROOT"
cd "$PROJECT_ROOT" || exit 1

# Check if artisan exists
if [ ! -f "artisan" ]; then
    echo -e "${RED}Laravel artisan not found, falling back to mv${NC}" >&2
    debug "artisan file not found in $PROJECT_ROOT"
    mv "${opts[@]}" "${args[@]}"
    exit $?
fi

# Build the Laravel command with options
LARAVEL_OPTS=()
if [[ $DEBUG -eq 1 ]]; then
    LARAVEL_OPTS+=("-v")
fi
if [[ $DRY_RUN -eq 1 ]]; then
    LARAVEL_OPTS+=("--dry-run")
fi

# Run the Laravel command with resolved absolute paths
debug "Running Laravel command: php artisan books:move ${resolved_sources[*]} $abs_dest ${LARAVEL_OPTS[*]}"
php artisan books:move "${resolved_sources[@]}" "$abs_dest" "${LARAVEL_OPTS[@]}"
EXIT_CODE=$?

debug "Laravel command exit code: $EXIT_CODE"

# Exit code 2 means "not a book move, use regular mv"
if [ $EXIT_CODE -eq 2 ]; then
    debug "Exit code 2: Not a book move, falling back to regular mv"
    if [[ $DRY_RUN -eq 1 ]]; then
        echo -e "${YELLOW}No books found in database - would execute: mv ${opts[*]} ${args[*]}${NC}"
        exit 0
    fi
    cd "$ORIGINAL_DIR" || exit 1
    mv "${opts[@]}" "${args[@]}"
    exit $?
fi

# Exit code 0 means success
if [ $EXIT_CODE -eq 0 ]; then
    debug "Exit code 0: Success!"
    exit 0
fi

# Any other exit code means failure
echo -e "${RED}Enhanced move failed with exit code $EXIT_CODE${NC}" >&2
debug "Exit code $EXIT_CODE: Failure"
exit $EXIT_CODE
