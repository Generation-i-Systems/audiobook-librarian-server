<?php

namespace App\Support;

/**
 * This file exists solely to provide IDE type hints for MongoDB classes
 *
 * @noinspection PhpIllegalPsrClassPathInspection
 */

// Class aliases for MongoDB classes
if (!class_exists('MongoDB\BSON\Regex')) {
    /**
     * @psalm-suppress MissingDependency
     *
     * @phpstan-ignore-next-line
     */
    class_alias('MongoDB\BSON\Regex', 'MongoDB\BSON\Regex');
}

if (!class_exists('MongoDB\BSON\ObjectId')) {
    /**
     * @psalm-suppress MissingDependency
     *
     * @phpstan-ignore-next-line
     */
    class_alias('MongoDB\BSON\ObjectId', 'MongoDB\BSON\ObjectId');
}

// This file is never actually loaded at runtime, it's just for IDE type hinting
