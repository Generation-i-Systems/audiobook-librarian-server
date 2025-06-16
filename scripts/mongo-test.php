
<?php
use MongoDB\Driver\ServerApi;

$uri = 'mongodb+srv://ericthelin:Heap7-Ellipse-Dandelion-Reformist@cluster0.ghigivi.mongodb.net/?retryWrites=true&w=majority&appName=Cluster0';

// Set the version of the Stable API on the client
$apiVersion = new ServerApi(ServerApi::V1);

// Create a new client and connect to the server
$client = new MongoDB\Client($uri, [], ['serverApi' => $apiVersion]);

try {
    // Send a ping to confirm a successful connection
    $client->selectDatabase('admin')->command(['ping' => 1]);
    echo "Pinged your deployment. You successfully connected to MongoDB!\n";
} catch (Exception $e) {
    printf($e->getMessage());
}
