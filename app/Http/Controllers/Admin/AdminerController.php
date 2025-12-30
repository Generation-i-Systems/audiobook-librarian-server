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
        if (!isset($_GET['username'])) {
            $_GET['username'] = config('database.connections.mysql.username');
        }
        if (!isset($_GET['server'])) {
            $_GET['server'] = config('database.connections.mysql.host');
        }
        if (!isset($_GET['db'])) {
            $_GET['db'] = config('database.connections.mysql.database');
        }
        
        // Ensure DRIVER is set
        if (!isset($_GET['server']) && !isset($_GET['sqlite']) && !isset($_GET['pgsql']) && !isset($_GET['oracle']) && !isset($_GET['mssql']) && !isset($_GET['mongo'])) {
            $_GET['server'] = config('database.connections.mysql.host');
        }

        // Define constants expected by Adminer
        if (!defined('SID')) {
            define('SID', ''); 
        }

        // Populate globals
        $_GET = array_replace($_GET, $request->query->all());
        $_POST = array_replace($_POST, $request->request->all());
        $_SERVER = array_replace($_SERVER, $request->server->all());

        // Define the adminer_object function in the global namespace
        // It will be found by adminer.php even if it's namespaced
        if (!function_exists('adminer_object')) {
            function adminer_object() {
                // Include the class definition only when called
                // At this point, the Adminer class will be defined by adminer.php
                require_once base_path('resources/adminer/adminer-custom-class.php');
                return new \Adminer\AdminerCustom;
            }
        }

        ob_start();
        require base_path('resources/adminer/adminer.php');
        $output = ob_get_clean();

        return Response::make($output);
    }
}
