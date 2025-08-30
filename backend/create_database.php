<?php
// Simple script to create the database
$host = '127.0.0.1';
$username = 'root';
$password = '';
$database = 'whatsapp_bot';

try {
    // Connect to MySQL without specifying a database
    $pdo = new PDO("mysql:host=$host", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to MySQL successfully.\n";
    
    // Create the database if it doesn't exist
    $sql = "CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    $pdo->exec($sql);
    
    echo "Database '$database' created successfully or already exists.\n";
    
    // Test connection to the new database
    $pdo = new PDO("mysql:host=$host;dbname=$database", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Successfully connected to database '$database'.\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
} 