<?php

namespace Adminer;

class AdminerCustom extends Adminer
{
    function name()
    {
        return 'Librarian Database';
    }

    function credentials()
    {
        return [
            \config('database.connections.mysql.host'),
            \config('database.connections.mysql.username'),
            \config('database.connections.mysql.password')
        ];
    }

    function database()
    {
        return \config('database.connections.mysql.database');
    }

    function login($login, $password)
    {
        return true;
    }

    function loginForm()
    {
        return "";
    }
}
