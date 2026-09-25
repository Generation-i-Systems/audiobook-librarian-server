<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminerConnectionConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class AdminerController extends Controller
{
    public function index()
    {
        return view('admin.database');
    }

    /**

     * Handle the Adminer request.

     *

     * @param  \Illuminate\Http\Request  $request

     * @return \Illuminate\Http\Response

     */

    public function handle(Request $request)
    {
        // Get database configuration

        $config = app(AdminerConnectionConfig::class)->get();

        $server = $config['server'];

        $username = $config['username'];

        $db = $config['database'];

        $password = $config['password'];

        $driver = $config['driver'];



        // Auto-populate connection details for Adminer

        if (!isset($_GET['username'])) {
            $_GET['username'] = $username;
        }

        if (!isset($_GET[$driver])) {
            $_GET[$driver] = $server;
        }

        if (!isset($_GET['db'])) {
            $_GET['db'] = $db;
        }

        // Define constants expected by Adminer

        if (!defined('SID')) {
            define('SID', '');
        }



        // Merge request data with our defaults

        $_GET = array_replace($_GET, $request->query->all());

        $_POST = array_replace($_POST, $request->request->all());

        $_SERVER = array_replace($_SERVER, $request->server->all());



        // Fake the Adminer login by populating the PHP session

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }



        // Adminer stores passwords in $_SESSION["pwds"][DRIVER][SERVER][USER]



        // We use the values from $_GET to ensure they match what Adminer will check



        $_SESSION["pwds"][$driver][$_GET[$driver]][$_GET['username']] = $password;







        // Prevent Adminer from trying to decrypt the password if an old key exists



        $_COOKIE["adminer_key"] = "";





        require_once base_path('app/Support/adminer_object.php');

        ob_start();

        require base_path('resources/adminer/adminer.php');

        $output = ob_get_clean();



        return Response::make($output);
    }
}
