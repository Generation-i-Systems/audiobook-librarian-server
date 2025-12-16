#!/bin/bash

# Enhanced mv command for book directories (bkmv replacement)
# Automatically updates database when moving directories in book root
# Falls back to mkdmv (auto-creates parent directories) if not in book root
# Supports all standard mv options and multiple sources

# Show help if requested
if [[ "$1" == "-h" ]] || [[ "$1" == "--help" ]]; then
    cat << 'EOF'
book-mv - Enhanced mv for audiobook directories with database sync

SYNOPSIS
    book-mv [OPTIONS] SOURCE... DEST

DESCRIPTION
    Intelligent move command that automatically updates the database when moving
    audiobook directories. Falls back to standard mv with auto-created parent
    directories when not moving books.

    Features:
    - Detects if moving directories in BOOK_STORAGE_PATH
    - Updates database records automatically for book moves
    - Auto-creates parent directories (mkdmv behavior)
    - Supports bind mounts and symlinks
    - Dry-run mode to preview changes
    - Verbose output for debugging

OPTIONS
    -n, --dry-run
        Show what would be done without making changes

    -v, --verbose
        Enable verbose debug output

    -h, --help
        Show this help message

    --regex=PATTERN
        Use regex-based renaming (format: s/pattern/replacement/flags)
        Applies the regex to each matching book directory basename
        Supports Perl-style regex with flags: g (global), i (case-insensitive),
        m (multiline), s (dotall), x (extended)

    Standard mv options are also supported (e.g., -i, -f, -u)

BEHAVIOR
    1. If moving directories in BOOK_STORAGE_PATH:
       - Uses Laravel command: php artisan books:move
       - Updates database records automatically
       - Preserves book metadata and relationships

    2. If moving files/dirs outside book root:
       - Uses mkdmv (mv with auto-created parent dirs)
       - No database interaction

    3. If database update not needed:
       - Falls back to mkdmv automatically

EXAMPLES
    # Move book directory (updates database)
    book-mv "Action/Author/Book 1" "SciFi/Author/Book 1"

    # Dry-run to preview changes
    book-mv -n "Action/Author/Book 1" "SciFi/Author/Book 1"

    # Verbose mode to see what's happening
    book-mv -v "Action/Author/Book 1" "SciFi/Author/Book 1"

    # Move multiple books to a directory
    book-mv "Action/Author/Book 1" "Action/Author/Book 2" "SciFi/Author/"

    # Regex rename: swap parts of directory name
    book-mv --regex='s#fry (.)/(.*)#$1-$2#' Action/Author/*

    # Regex rename: reorder chapter numbers in filenames
    book-mv --regex='s/(..)( The Way of .* - Chapter )(..)/$3$2$1/' "Series/Book"/*

    # Regex rename: add prefix to all matching directories
    book-mv --regex='s/^/Book /' Action/Author/[0-9]*

    # Regex rename with dry-run to preview
    book-mv -n --regex='s/Book/Novel/g' Action/Author/*

    # Move non-book files (auto-creates parent dirs)
    book-mv ~/file.txt /path/to/new/location/file.txt

ENVIRONMENT
    BOOK_STORAGE_PATH
        Root directory for audiobook storage
        Loaded from the environment; falls back to .env file in project root if not set

FLAGS
    --book-only, --require-book
        Require the move to be a database-backed book move; abort if no matching books are detected
    --non-book
        Force filesystem-only move (mkdmv behavior); never invoke Laravel/books:move
    --verify
        Enable interactive verification of planned path/database changes (passed through to books:move)
    -y, --yes
        Assume "yes" for fallback prompts and proceed with filesystem-only move when no books are detected

EXIT CODES
    0   Success
    1   Error occurred
    2   Not a book move (internal - triggers mkdmv fallback)

SEE ALSO
    mv(1), php artisan books:move --help

STANDARD MV OPTIONS
    The following standard mv options are supported when falling back to mkdmv:

EOF

    # Include standard mv help
    mv --help 2>&1 | sed 's/^/    /'

    exit 0
fi

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
FORCE_BOOK=0
FORCE_NON_BOOK=0
VERIFY_CHANGES=0
ASSUME_YES=0

# Debug function
debug() {
    if [[ $DEBUG -eq 1 ]]; then
        echo -e "${BLUE}[DEBUG]${NC} $*" >&2
    fi
}

# Enhanced mv that auto-creates parent directories (mkdmv behavior)
mkdmv() {
    local n=0 endofargs= dest=

    # Parse arguments to find destination (last non-option arg)
    for arg in "$@"; do
        if [ -n "$endofargs" ] || [ "${arg#-}" = "$arg" ]; then
            n=$((n+1))
            dest=$arg
        elif [ "$arg" = '--' ]; then
            endofargs=1
        fi
    done

    debug "mkdmv: found $n non-option args, dest=$dest"

    # dest is a dir to be created if there are multiple src files or if target ends with "/"
    if [ "$n" -gt 2 ] || [ "${dest%/}" != "$dest" ]; then
        # append `.` to prevent `dirname` from returning parent dir
        dest="$dest/."
        debug "mkdmv: multiple sources or trailing slash, adjusted dest=$dest"
    fi

    local dest_dir
    dest_dir=$(dirname -- "$dest")
    debug "mkdmv: creating parent directory: $dest_dir"

    mkdir -p -- "$dest_dir" && mv "$@"
}

# Separate mv options and positional args (like bkmv does)
opts=()
args=()
REGEX_PATTERN=""
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
        --book-only|--require-book)
            FORCE_BOOK=1
            shift
            ;;
        --non-book|--no-book|--force-non-book)
            FORCE_NON_BOOK=1
            shift
            ;;
        --verify)
            VERIFY_CHANGES=1
            shift
            ;;
        -y|--yes)
            ASSUME_YES=1
            shift
            ;;
        -nv|-vn)
            # Handle combined -nv or -vn flags
            DRY_RUN=1
            DEBUG=1
            shift
            ;;
        --regex=*)
            # Extract regex pattern
            REGEX_PATTERN="${1#*=}"
            shift
            ;;
        --regex)
            # Regex pattern in next argument
            REGEX_PATTERN="$2"
            shift 2
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
debug "Regex pattern: $REGEX_PATTERN"

if [[ $FORCE_BOOK -eq 1 && $FORCE_NON_BOOK -eq 1 ]]; then
    echo -e "${RED}Cannot use --book-only and --non-book together${NC}" >&2
    exit 1
fi

if [[ $DRY_RUN -eq 1 ]]; then
    echo -e "${YELLOW}=== DRY RUN MODE ===${NC}"
    DEBUG=1  # Enable debug output for dry-run
fi

if [[ $FORCE_NON_BOOK -eq 1 ]]; then
    debug "Forced non-book mode enabled, using mkdmv"
    if [[ $DRY_RUN -eq 1 ]]; then
        echo -e "${YELLOW}Would execute (forced non-book): mkdmv ${opts[*]} ${args[*]}${NC}"
        exit 0
    fi
    mkdmv "${opts[@]}" "${args[@]}"
    exit $?
fi

# Handle regex mode directly in bash
if [[ -n "$REGEX_PATTERN" ]]; then
    debug "Regex mode enabled"
    debug "Regex pattern: $REGEX_PATTERN"

    # Basic validation - just check it starts with 's' and has a delimiter
    if [[ ! "$REGEX_PATTERN" =~ ^s. ]]; then
        echo -e "${RED}Invalid regex pattern. Must start with 's' followed by delimiter${NC}" >&2
        echo "Example: s/Book/Novel/g or s#^0[123] ## or s/^0[123] // or s:a:b" >&2
        exit 1
    fi

    # Process each source file/directory
    for src in "${args[@]}"; do
        if [[ ! -e "$src" ]]; then
            echo -e "${YELLOW}Skipping non-existent: $src${NC}"
            continue
        fi

        basename=$(basename "$src")
        dirname=$(dirname "$src")

        # Apply regex using Perl (more compatible with s/// syntax)
        # Just pass the entire pattern to Perl/sed - they know how to parse it!
        if command -v perl >/dev/null 2>&1; then
            newbasename=$(printf '%s\n' "$basename" | perl -pe "$REGEX_PATTERN")
        else
            # Fallback to sed
            newbasename=$(printf '%s\n' "$basename" | sed "$REGEX_PATTERN")
        fi

        if [[ "$basename" == "$newbasename" ]]; then
            debug "No change for: $src"
            continue
        fi

        newsrc="${dirname}/${newbasename}"

        if [[ $DRY_RUN -eq 1 ]]; then
            echo -e "${GREEN}Would rename:${NC} $src ${BLUE}→${NC} $newsrc"
        else
            debug "Renaming: $src → $newsrc"
            if mv "${opts[@]}" "$src" "$newsrc"; then
                echo -e "${GREEN}Renamed:${NC} $basename ${BLUE}→${NC} $newbasename"
            else
                echo -e "${RED}Failed to rename: $src${NC}" >&2
            fi
        fi
    done

    exit 0
fi

# If less than 2 positional args, fallback to mv
if [[ ${#args[@]} -lt 2 ]]; then
    debug "Less than 2 arguments, falling back to mkdmv"
    if [[ $DRY_RUN -eq 1 ]]; then
        echo -e "${YELLOW}Would execute: mkdmv ${opts[*]} ${args[*]}${NC}"
        exit 0
    fi
    mkdmv "${opts[@]}" "${args[@]}"
    exit $?
fi

# All but the last positional arg are sources
sources=("${args[@]:0:${#args[@]}-1}")
dest="${args[-1]}"

debug "Sources: ${sources[*]}"
debug "Destination: $dest"

# Load environment to get BOOK_STORAGE_PATH
if [ -z "$BOOK_STORAGE_PATH" ]; then
    if [ -f "$PROJECT_ROOT/.env" ]; then
        debug "Loading environment from $PROJECT_ROOT/.env"
        export $(grep -v '^#' "$PROJECT_ROOT/.env" | grep BOOK_STORAGE_PATH | xargs)
    fi
else
    debug "BOOK_STORAGE_PATH already set in environment; skipping .env load"
fi

debug "BOOK_STORAGE_PATH: $BOOK_STORAGE_PATH"

# Check if BOOK_STORAGE_PATH is set
if [ -z "$BOOK_STORAGE_PATH" ]; then
    if [[ $FORCE_BOOK -eq 1 ]]; then
        echo -e "${RED}BOOK_STORAGE_PATH not set. Aborting because --book-only is set.${NC}" >&2
        exit 1
    fi
    debug "BOOK_STORAGE_PATH not set, falling back to mkdmv"
    if [[ $DRY_RUN -eq 1 ]]; then
        echo -e "${YELLOW}Would execute: mkdmv ${opts[*]} ${args[*]}${NC}"
        exit 0
    fi
    mkdmv "${opts[@]}" "${args[@]}"
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

# If no sources are in book root, use mkdmv with original working directory
if [[ $is_book -eq 0 ]]; then
    if [[ $FORCE_BOOK -eq 1 ]]; then
        echo -e "${RED}No sources detected under BOOK_STORAGE_PATH. Aborting because --book-only is set.${NC}" >&2
        exit 1
    fi
    debug "No sources in book root, falling back to mkdmv"
    if [[ $DRY_RUN -eq 1 ]]; then
        echo -e "${YELLOW}Would execute: mkdmv ${opts[*]} ${args[*]}${NC}"
        exit 0
    fi
    mkdmv "${opts[@]}" "${args[@]}"
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
    echo -e "${RED}Laravel artisan not found, falling back to mkdmv${NC}" >&2
    debug "artisan file not found in $PROJECT_ROOT"
    cd "$ORIGINAL_DIR" || exit 1
    mkdmv "${opts[@]}" "${args[@]}"
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
if [[ $FORCE_BOOK -eq 1 ]]; then
    LARAVEL_OPTS+=("--require-book")
fi
if [[ $VERIFY_CHANGES -eq 1 ]]; then
    LARAVEL_OPTS+=("--verify")
fi

# Run the Laravel command with resolved absolute paths
debug "Running Laravel command: php artisan books:move ${resolved_sources[*]} $abs_dest ${LARAVEL_OPTS[*]}"
php artisan books:move "${resolved_sources[@]}" "$abs_dest" "${LARAVEL_OPTS[@]}"
EXIT_CODE=$?

debug "Laravel command exit code: $EXIT_CODE"

# Exit code 2 means "not a book move, use mkdmv"
if [ $EXIT_CODE -eq 2 ]; then
    debug "Exit code 2: Not a book move, falling back to mkdmv"

    if [[ $FORCE_BOOK -eq 1 ]]; then
        echo -e "${RED}No matching books detected. Aborting because --book-only is set.${NC}" >&2
        exit 1
    fi

    if [[ $ASSUME_YES -eq 1 ]]; then
        debug "--yes set; proceeding with filesystem-only move"
    elif [[ -t 0 && -t 1 ]]; then
        echo -e "${YELLOW}Sources are under BOOK_STORAGE_PATH but no matching books were found in the database.${NC}" >&2
        read -r -p "Proceed with filesystem-only move? [y/N] " reply < /dev/tty
        if [[ ! "$reply" =~ ^[Yy]$ ]]; then
            echo -e "${YELLOW}Operation cancelled.${NC}" >&2
            exit 0
        fi
    else
        echo -e "${YELLOW}Non-interactive session: falling back to filesystem-only move (no matching books found).${NC}" >&2
    fi

    if [[ $DRY_RUN -eq 1 ]]; then
        echo -e "${YELLOW}No books found in database - would execute: mkdmv ${opts[*]} ${args[*]}${NC}"
        exit 0
    fi
    cd "$ORIGINAL_DIR" || exit 1
    mkdmv "${opts[@]}" "${args[@]}"
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
