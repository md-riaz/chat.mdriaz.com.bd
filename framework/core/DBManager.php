<?php

namespace Framework\Core;

class DBManager
{
    // Hold multiple instances of Database based on database name
    private static $instances = [];

    // Static method to get a Database instance for a specific database
    public static function getDB($dbname = null)
    {
        $dbname = $dbname ?? DB_NAME;

        // Check if a database connection exists and is still active
        if (!isset(self::$instances[$dbname]) || self::isConnectionClosed(self::$instances[$dbname])) {
            // Recreate the instance if it's missing or closed
            self::$instances[$dbname] = new Database(DB_HOST, DB_USER, DB_PASS, $dbname);
        }

        return self::$instances[$dbname];
    }

    // Helper method to check if the connection is closed
    private static function isConnectionClosed($db)
    {
        try {
            // Perform a simple query to check if the connection is still active
            $db->query("SELECT 1");
        } catch (\PDOException $e) {
            // If an exception is thrown, the connection is likely closed
            return true;
        }

        return false;
    }

    // Begin a transaction for a specific database
    // public static function beginTransaction($dbname = null)
    // {
    //     self::getDB($dbname)->beginTransaction();
    // }

    // // Commit a transaction for a specific database
    // public static function commit($dbname = null)
    // {
    //     self::getDB($dbname)->commit();
    // }

    // // Rollback a transaction for a specific database
    // public static function rollback($dbname = null)
    // {
    //     self::getDB($dbname)->rollback();
    // }

    public static function isTransactionActive($dbname = null)
    {
        return self::getDB($dbname)->inTransaction();
    }

    // Handle dynamic static method calls to the Database instance
    public static function __callStatic($method, $arguments)
    {
        // Get the database name from the arguments if passed, or use the default
        $dbname = $arguments[0]['dbname'] ?? null;

        // Remove 'dbname' from arguments before passing them to the database method (if provided)
        if (isset($arguments[0]['dbname'])) {
            unset($arguments[0]['dbname']);
        }

        // Get the Database instance for the given database name
        $db = self::getDB($dbname);

        // Call the method dynamically with the remaining arguments
        return call_user_func_array([$db, $method], $arguments);
    }
}
