#!/bin/bash

# Run tests in a way that avoids hanging issues
# This script runs Unit and Feature tests separately

echo "================================"
echo "Running Unit Tests (Fast)"
echo "================================"
php artisan test --testsuite=Unit

UNIT_EXIT=$?

echo ""
echo "================================"
echo "Running Feature Tests"
echo "================================"
php artisan test --testsuite=Feature --stop-on-failure

FEATURE_EXIT=$?

echo ""
echo "================================"
echo "Test Summary"
echo "================================"
echo "Unit Tests: $([ $UNIT_EXIT -eq 0 ] && echo 'PASSED ✓' || echo 'FAILED ✗')"
echo "Feature Tests: $([ $FEATURE_EXIT -eq 0 ] && echo 'PASSED ✓' || echo 'FAILED ✗')"

# Exit with failure if either suite failed
if [ $UNIT_EXIT -ne 0 ] || [ $FEATURE_EXIT -ne 0 ]; then
    exit 1
fi

exit 0
