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
        // Auto-populate connection details for the database
        // This ensures Adminer starts with the right context
        if (!isset($_GET['username'])) {
            $_GET['username'] = config('database.connections.mysql.username');
        }
        if (!isset($_GET['server'])) {
            $_GET['server'] = config('database.connections.mysql.host');
        }
        if (!isset($_GET['db'])) {
            $_GET['db'] = config('database.connections.mysql.database');
        }
        
        // Ensure DRIVER is set (defaults to server/mysql)
        if (!isset($_GET['server']) && !isset($_GET['sqlite']) && !isset($_GET['pgsql']) && !isset($_GET['oracle']) && !isset($_GET['mssql']) && !isset($_GET['mongo'])) {
            $_GET['server'] = config('database.connections.mysql.host');
        }

        // Define constants expected by Adminer
        if (!defined('SID')) {
            define('SID', ''); 
        }

        // Populate globals. Adminer uses these heavily.
        $_GET = array_replace($_GET, $request->query->all());
        $_POST = array_replace($_POST, $request->request->all());
        $_SERVER = array_replace($_SERVER, $request->server->all());

        // Define the adminer_object function in the global namespace.
        // Adminer (inside Adminer namespace) will find this global function.
        if (!function_exists('adminer_object')) {
            /**
             * This function is the standard Adminer hook for customization.
             * It returns an instance of a class that extends Adminer.
             */
            function adminer_object() {
                // We define the class inside the Adminer namespace to match adminer.php
                if (!class_exists('Adminer\AdminerCustom')) {
                    require_once base_path('resources/adminer/adminer-custom-class.php');
                }
                return new \Adminer\AdminerCustom;
            }
        }

        ob_start();
        // Execute Adminer
        require base_path('resources/adminer/adminer.php');
        $output = ob_get_clean();

        return Response::make($output);
    }
}