#!/bin/bash
set -e

echo "🔄 Test Reorganization"
echo "====================="
echo ""
echo "This will reorganize tests by category:"
echo "  - tests/Feature/Api → tests/Api/Feature"
echo "  - tests/Unit/Services → tests/Core/Unit/Services"
echo "  - etc."
echo ""

BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$BASE_DIR"

# Create new directory structure
echo "📁 Creating new directory structure..."
mkdir -p tests/Api/{Feature,Unit}
mkdir -p tests/Web/{Feature,Unit}
mkdir -p tests/Cli/{Feature,Unit}
mkdir -p tests/Import/{Feature,Unit}
mkdir -p tests/Core/{Feature,Unit}

# Function to move files and update namespaces
move_and_update() {
    local source=$1
    local dest=$2
    local old_namespace=$3
    local new_namespace=$4

    if [ ! -d "$source" ]; then
        echo "⚠️  Skipping $source (not found)"
        return
    fi

    echo "📦 Moving: $source → $dest"

    # Find all PHP test files
    find "$source" -type f -name "*.php" | while read -r file; do
        # Calculate relative path
        rel_path="${file#$source/}"
        dest_file="$dest/$rel_path"
        dest_dir="$(dirname "$dest_file")"

        # Create destination directory
        mkdir -p "$dest_dir"

        # Copy file and update namespace
        sed "s|namespace $old_namespace|namespace $new_namespace|g" "$file" > "$dest_file"

        echo "  ✓ $(basename "$file")"
    done
}

# API Tests
move_and_update \
    "tests/Feature/Api" \
    "tests/Api/Feature" \
    "Tests\\\\Feature\\\\Api" \
    "Tests\\\\Api\\\\Feature"

move_and_update \
    "tests/Unit/Controllers/Api" \
    "tests/Api/Unit/Controllers" \
    "Tests\\\\Unit\\\\Controllers\\\\Api" \
    "Tests\\\\Api\\\\Unit\\\\Controllers"

# Web/Admin Tests
move_and_update \
    "tests/Feature/Controllers/Admin" \
    "tests/Web/Feature/Controllers" \
    "Tests\\\\Feature\\\\Controllers\\\\Admin" \
    "Tests\\\\Web\\\\Feature\\\\Controllers"

move_and_update \
    "tests/Feature/Admin" \
    "tests/Web/Feature/Admin" \
    "Tests\\\\Feature\\\\Admin" \
    "Tests\\\\Web\\\\Feature\\\\Admin"

move_and_update \
    "tests/Unit/Controllers/Admin" \
    "tests/Web/Unit/Controllers" \
    "Tests\\\\Unit\\\\Controllers\\\\Admin" \
    "Tests\\\\Web\\\\Unit\\\\Controllers"

if [ -d "tests/Unit/Views" ]; then
    move_and_update \
        "tests/Unit/Views" \
        "tests/Web/Unit/Views" \
        "Tests\\\\Unit\\\\Views" \
        "Tests\\\\Web\\\\Unit\\\\Views"
fi

# CLI Tests
move_and_update \
    "tests/Feature/Commands" \
    "tests/Cli/Feature/Commands" \
    "Tests\\\\Feature\\\\Commands" \
    "Tests\\\\Cli\\\\Feature\\\\Commands"

move_and_update \
    "tests/Unit/Commands" \
    "tests/Cli/Unit/Commands" \
    "Tests\\\\Unit\\\\Commands" \
    "Tests\\\\Cli\\\\Unit\\\\Commands"

move_and_update \
    "tests/Unit/Console/Commands" \
    "tests/Cli/Unit/Console/Commands" \
    "Tests\\\\Unit\\\\Console\\\\Commands" \
    "Tests\\\\Cli\\\\Unit\\\\Console\\\\Commands"

# Core/Service Tests
move_and_update \
    "tests/Unit/Services" \
    "tests/Core/Unit/Services" \
    "Tests\\\\Unit\\\\Services" \
    "Tests\\\\Core\\\\Unit\\\\Services"

move_and_update \
    "tests/Feature/Services" \
    "tests/Core/Feature/Services" \
    "Tests\\\\Feature\\\\Services" \
    "Tests\\\\Core\\\\Feature\\\\Services"

if [ -d "tests/Unit/Traits" ]; then
    move_and_update \
        "tests/Unit/Traits" \
        "tests/Core/Unit/Traits" \
        "Tests\\\\Unit\\\\Traits" \
        "Tests\\\\Core\\\\Unit\\\\Traits"
fi

if [ -d "tests/Unit/Support" ]; then
    move_and_update \
        "tests/Unit/Support" \
        "tests/Core/Unit/Support" \
        "Tests\\\\Unit\\\\Support" \
        "Tests\\\\Core\\\\Unit\\\\Support"
fi

# Import Tests - collect from various locations
echo "📦 Collecting Import-related tests..."
if [ -d "tests/Unit/Import" ]; then
    move_and_update \
        "tests/Unit/Import" \
        "tests/Import/Unit" \
        "Tests\\\\Unit\\\\Import" \
        "Tests\\\\Import\\\\Unit"
fi

# Find remaining Import tests scattered in Feature/Unit
find tests/Feature tests/Unit -type f -name "*Import*.php" 2>/dev/null | while read -r file; do
    if [ -f "$file" ]; then
        rel_path="$(basename "$file")"

        # Determine destination based on path
        if [[ "$file" == *"Feature"* ]]; then
            dest_dir="tests/Import/Feature"
            old_ns="Tests\\\\Feature"
            new_ns="Tests\\\\Import\\\\Feature"
        else
            dest_dir="tests/Import/Unit"
            old_ns="Tests\\\\Unit"
            new_ns="Tests\\\\Import\\\\Unit"
        fi

        mkdir -p "$dest_dir"
        sed "s|namespace $old_ns|namespace $new_ns|g" "$file" > "$dest_dir/$rel_path"
        echo "  ✓ Import: $rel_path"
    fi
done

echo ""
echo "✅ File reorganization complete!"
echo ""
echo "🧹 Cleaning up old directories..."

# Remove old empty directories (be careful!)
find tests/Feature tests/Unit -type d -empty -delete 2>/dev/null || true

echo ""
echo "⚠️  Next steps:"
echo "   1. Update composer.json autoload"
echo "   2. Run: composer dump-autoload"
echo "   3. Run: php artisan test"
echo ""
