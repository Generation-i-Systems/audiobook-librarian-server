<?php

declare(strict_types=1);

namespace Adminer;

/**
 * Custom Adminer class to handle auto-login using Laravel's configuration.
 */
class AdminerCustom extends Adminer
{
    /** @var array<object> */
    private array $plugins;

    public function __construct(object ...$plugins)
    {
        $this->plugins = $plugins;
    }

    /**
     * Set the name displayed in the Adminer interface.
     */
    public function name()
    {
        return 'Librarian Database';
    }

    /**
     * Provide database credentials from Laravel's config.
     */
    public function credentials()
    {
        $config = \app(\App\Services\AdminerConnectionConfig::class)->get();
        return [
            $config['server'],
            $config['username'],
            $config['password'],
        ];
    }

    /**
     * Provide the default database name.
     */
    public function database()
    {
        return \app(\App\Services\AdminerConnectionConfig::class)->get()['database'];
    }

    /**
     * Automatic login: always return true.
     * Access is already restricted to Laravel Admins via middleware.
     */
    public function login($login, $password)
    {
        return true;
    }

    /**
     * Hide the login form as it's not needed.
     */
    public function loginForm()
    {
        return "";
    }

    public function headers()
    {
        foreach ($this->plugins as $plugin) {
            if (method_exists($plugin, 'headers')) {
                $plugin->headers();
            }
        }
    }

    public function sqlPrintAfter()
    {
        foreach ($this->plugins as $plugin) {
            if (method_exists($plugin, 'sqlPrintAfter')) {
                $plugin->sqlPrintAfter();
            }
        }
    }
}
