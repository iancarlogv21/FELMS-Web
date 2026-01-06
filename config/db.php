<?php
// Make sure you have run 'composer require mongodb/mongodb vlucas/phpdotenv' in your project folder.
require_once __DIR__ . '/../vendor/autoload.php';

// Check if the vendor directory exists and provide a helpful error message if not.
$vendorPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($vendorPath)) {
    die("<h1>Configuration Error: Composer Dependencies Not Found</h1>" .
        "<p>The required libraries are missing. Please run <code>composer install</code> in your project's root directory from your terminal.</p>" .
        "<p>If you don't have Composer, please <a href='https://getcomposer.org/download/'>download and install it</a> first.</p>");
}

use Dotenv\Dotenv;
use MongoDB\Client;
use MongoDB\Database as MongoDatabase;
use MongoDB\Collection;

class Database {
    private static ?Database $instance = null;
    private Client $client; // <-- CRITICAL FIX: Declare the client property
    private MongoDatabase $db;

    private function __construct() {
        try {
            $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
            $dotenv->load();

            $uri = $_ENV['MONGO_URI'] ?? "mongodb://localhost:27017";
            $dbName = $_ENV['MONGO_DB_NAME'] ?? "Library";

            $this->client = new Client($uri); // <-- CRITICAL FIX: Assign client to the class property
            $this->db = $this->client->selectDatabase($dbName); // Now using the client property

        } catch (\Exception $e) {
            throw new \Exception("MongoDB Connection Error: " . $e->getMessage());
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

   public function getClient(): Client {
        return $this->client;
    }

    // --- COLLECTION GETTERS ---
    // These methods now have explicit return types for better code quality.

    public function books(): Collection {
        return $this->db->selectCollection('AddBook');
    }
    

    public function students(): Collection {
        return $this->db->selectCollection('Students');
    }

    public function borrows(): Collection {
        return $this->db->selectCollection('borrow_book');
    }
    
    public function returns(): Collection {
        return $this->db->selectCollection('return_book');
    }
    
    public function users(): Collection {
        return $this->db->selectCollection('users');
    }


     public function notifications()
    {
        return $this->db->selectCollection('notifications');
    }

    public function activity_logs(): Collection 
    {
        return $this->db->selectCollection('activity_logs');
    }

    // Add this inside your Database class in config/db.php

     public function attendance_logs(): Collection
{
        return $this->db->selectCollection('attendance_logs');
}

public function admins(): Collection {
        return $this->db->selectCollection('admins');
    }
public function login_history(): MongoDB\Collection {
        return $this->db->selectCollection('login_history');
    }

    

    private function __clone() { }
    public function __wakeup() { }
}

