#!/bin/bash

# Install smart mv alias that cascades through available options
# Priority: book-mv -> mkdmv -> mv

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Detect shell
if [ -n "$ZSH_VERSION" ]; then
    SHELL_RC="$HOME/.zshrc"
    SHELL_NAME="zsh"
elif [ -n "$BASH_VERSION" ]; then
    SHELL_RC="$HOME/.bashrc"
    SHELL_NAME="bash"
else
    echo "Unsupported shell. Please add alias manually."
    exit 1
fi

echo "Installing smart mv alias for $SHELL_NAME..."

# Create the alias function
ALIAS_FUNCTION='
# Smart mv alias - cascades through book-mv -> mkdmv -> mv
mv() {
    local book_mv_script="'"$PROJECT_ROOT"'/scripts/book-mv.sh"
    local mkdmv_script="$HOME/tools/mkdmv"
    
    # Try book-mv first (for book directories)
    if [ -x "$book_mv_script" ]; then
        "$book_mv_script" "$@"
        local exit_code=$?
        
        # Exit code 2 means "not a book, use fallback"
        if [ $exit_code -ne 2 ]; then
            return $exit_code
        fi
    fi
    
    # Fall back to mkdmv if available (creates parent dirs)
    if [ -x "$mkdmv_script" ]; then
        "$mkdmv_script" "$@"
        return $?
    fi
    
    # Final fallback to regular mv
    command mv "$@"
}
'

# Check if alias already exists
if grep -q "# Smart mv alias" "$SHELL_RC" 2>/dev/null; then
    echo "Alias already exists in $SHELL_RC"
    echo "Remove it manually if you want to reinstall"
    exit 0
fi

# Backup shell rc
cp "$SHELL_RC" "$SHELL_RC.backup.$(date +%Y%m%d_%H%M%S)"

# Add alias to shell rc
echo "$ALIAS_FUNCTION" >> "$SHELL_RC"

echo "✓ Alias installed to $SHELL_RC"
echo "✓ Backup created: $SHELL_RC.backup.*"
echo ""
echo "To activate now, run:"
echo "  source $SHELL_RC"
echo ""
echo "Or start a new shell session"
echo ""
echo "Priority order:"
echo "  1. book-mv.sh (if source is in book root)"
echo "  2. mkdmv (if available, creates parent dirs)"
echo "  3. mv (standard fallback)"
