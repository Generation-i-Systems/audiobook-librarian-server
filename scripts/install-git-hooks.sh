#!/bin/bash

# Install git hooks from scripts/git-hooks/ to .git/hooks/

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
HOOKS_DIR="$PROJECT_ROOT/.git/hooks"
SOURCE_DIR="$SCRIPT_DIR/git-hooks"

echo "Installing git hooks..."

# Copy pre-push hook
if [ -f "$SOURCE_DIR/pre-push" ]; then
    cp "$SOURCE_DIR/pre-push" "$HOOKS_DIR/pre-push"
    chmod +x "$HOOKS_DIR/pre-push"
    echo "✅ Installed pre-push hook"
else
    echo "❌ pre-push hook not found in $SOURCE_DIR"
    exit 1
fi

echo ""
echo "✅ Git hooks installed successfully!"
echo ""
echo "The pre-push hook will now run automatically before every push."
echo "To test the validation manually, run: ./scripts/validate-build.sh"
