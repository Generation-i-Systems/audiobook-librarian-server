<?php

declare(strict_types=1);

if (!function_exists('adminer_object')) {
    function adminer_object()
    {
        if (!class_exists('Adminer\AdminerCustom')) {
            require_once base_path('resources/adminer/adminer-custom-class.php');
        }

        require_once base_path('resources/adminer/adminer-plugins/sql-gemini.php');

        /** @phpstan-ignore-next-line External Adminer class loaded immediately above. */
        return new \Adminer\AdminerCustom(
            /** @phpstan-ignore-next-line External Adminer plugin loaded immediately above. */
            new \AdminerSqlGemini(),
        );
    }
}
