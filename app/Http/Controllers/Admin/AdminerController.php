<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

        $config = config('database.connections.mysql');

        $server = $config['host'] ?? '127.0.0.1';

        $username = $config['username'] ?? '';

        $db = $config['database'] ?? '';

        $password = $config['password'] ?? '';



        // Auto-populate connection details for Adminer

        if (!isset($_GET['username'])) {
            $_GET['username'] = $username;
        }

        if (!isset($_GET['server'])) {
            $_GET['server'] = $server;
        }

        if (!isset($_GET['db'])) {
            $_GET['db'] = $db;
        }



        // Ensure DRIVER is set (defaults to 'server' for MySQL)

        if (!isset($_GET['server']) && !isset($_GET['sqlite']) && !isset($_GET['pgsql']) && !isset($_GET['oracle']) && !isset($_GET['mssql']) && !isset($_GET['mongo'])) {
            $_GET['server'] = $server;
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



        $driver = 'server';



        $_SESSION["pwds"][$driver][$_GET['server']][$_GET['username']] = $password;







        // Prevent Adminer from trying to decrypt the password if an old key exists



        $_COOKIE["adminer_key"] = "";







        // Define the adminer_object function in the global namespace.





        if (!function_exists('adminer_object')) {
            function adminer_object()
            {
                if (!class_exists('Adminer\AdminerCustom')) {
                    require_once base_path('resources/adminer/adminer-custom-class.php');
                }

                require_once base_path('resources/adminer/adminer-plugins/sql-gemini.php');

                return new \Adminer\Plugin(array(
                    new \Adminer\AdminerCustom(),
                    new \AdminerSqlGemini(),
                ));
            }
        }



        ob_start();

        require base_path('resources/adminer/adminer.php');

        $output = ob_get_clean();



        return Response::make($output);
    }
}
