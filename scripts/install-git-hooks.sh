#!/bin/bash

# Install git hooks from scripts/git-hooks/ to .git/hooks/

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
HOOKS_DIR="$PROJECT_ROOT/.git/hooks"
SOURCE_DIR="$SCRIPT_DIR/git-hooks"

echo "Installing git hooks..."

# Function to install a hook
install_hook() {
    local name=$1
    if [ -f "$SOURCE_DIR/$name" ]; then
        cp "$SOURCE_DIR/$name" "$HOOKS_DIR/$name"
        chmod +x "$HOOKS_DIR/$name"
        echo "✅ Installed $name hook"
    else
        echo "❌ $name hook not found in $SOURCE_DIR"
        return 1
    fi
}

install_hook "pre-push"
install_hook "pre-commit"

echo ""
echo "✅ Git hooks installed successfully!"
echo ""
echo "The hooks will now run automatically before every commit and push."
echo "To test the push validation manually, run: ./scripts/validate-build.sh"
