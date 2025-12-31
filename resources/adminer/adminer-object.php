<?php

namespace Adminer;

if (!function_exists('Adminer\adminer_object')) {
    function adminer_object()
    {
        if (!class_exists('Adminer\AdminerCustom')) {
            class AdminerCustom extends Adminer
            {
                public function name()
                {
                    return 'Librarian Database';
                }

                public function credentials()
                {
                    return [
                        \config('database.connections.mysql.host'),
                        \config('database.connections.mysql.username'),
                        \config('database.connections.mysql.password')
                    ];
                }

                public function database()
                {
                    return \config('database.connections.mysql.database');
                }

                public function login($login, $password)
                {
                    return true;
                }

                public function loginForm()
                {
                    return "";
                }
            }
        }

        return new AdminerCustom();
    }
}
