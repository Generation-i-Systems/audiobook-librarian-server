#!/bin/bash

# Script to manually run all pre-push validation checks
# This allows you to test your code without triggering an actual push

echo "Running pre-push validation checks..."
echo ""

# Execute the pre-push hook
.git/hooks/pre-push

EXIT_CODE=$?

if [ $EXIT_CODE -eq 0 ]; then
    echo ""
    echo "✅ All validation checks passed!"
    echo "Your code is ready to commit and push."
else
    echo ""
    echo "❌ Some validation checks failed."
    echo "Please fix the issues before pushing."
fi

exit $EXIT_CODE
