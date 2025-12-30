<?php

namespace Adminer;

/**
 * Custom Adminer class to handle auto-login using Laravel's configuration.
 */
class AdminerCustom extends Adminer
{
    /**
     * Set the name displayed in the Adminer interface.
     */
    function name()
    {
        return 'Librarian Database';
    }

    /**
     * Provide database credentials from Laravel's config.
     */
    function credentials()
    {
        return [
            \config('database.connections.mysql.host'),
            \config('database.connections.mysql.username'),
            \config('database.connections.mysql.password')
        ];
    }

    /**
     * Provide the default database name.
     */
    function database()
    {
        return \config('database.connections.mysql.database');
    }

    /**
     * Automatic login: always return true.
     * Access is already restricted to Laravel Admins via middleware.
     */
    function login($login, $password)
    {
        return true;
    }

    /**
     * Hide the login form as it's not needed.
     */
    function loginForm()
    {
        return "";
    }
}