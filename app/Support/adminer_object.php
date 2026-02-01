<?php

declare(strict_types=1);

if (!function_exists('adminer_object')) {
    function adminer_object()
    {
        if (!class_exists('Adminer\AdminerCustom')) {
            require_once base_path('resources/adminer/adminer-custom-class.php');
        }

        require_once base_path('resources/adminer/adminer-plugins/sql-gemini.php');

        /** @phpstan-ignore-next-line */
        return new \Adminer\Plugin(array(
            /** @phpstan-ignore-next-line */
            new \Adminer\AdminerCustom(),
            /** @phpstan-ignore-next-line */
            new \AdminerSqlGemini(),
        ));
    }
}
