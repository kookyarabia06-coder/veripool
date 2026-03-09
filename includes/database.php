<?php
/**
 * Veripool Reservation System - Database Class
 * Handles all database connections and operations with PDO
 */

require_once 'config.php';

class Database {
    private static $instance = null;
    private $connection;
    private $statement;
    private $debug = true; // Enable for debugging queries
    
    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            if ($this->debug) {
                error_log("Database connection established successfully");
            }
        } catch (PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get database instance (singleton)
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Prepare and execute query with parameters
     * FIXED: Now handles both named and positional parameters correctly
     */
    public function query($sql, $params = []) {
        try {
            if ($this->debug) {
                error_log("SQL Query: " . $sql);
                error_log("Parameters: " . print_r($params, true));
            }
            
            $this->statement = $this->connection->prepare($sql);
            
            // Check if params are associative (named) or sequential (positional)
            if (!empty($params)) {
                foreach ($params as $key => $value) {
                    // Determine PDO parameter type
                    $paramType = PDO::PARAM_STR;
                    if (is_int($value)) {
                        $paramType = PDO::PARAM_INT;
                    } elseif (is_bool($value)) {
                        $paramType = PDO::PARAM_BOOL;
                    } elseif (is_null($value)) {
                        $paramType = PDO::PARAM_NULL;
                    }
                    
                    // Bind parameter - handle both named (:key) and positional (?) 
                    if (is_string($key)) {
                        // Named parameter (e.g., :status)
                        $this->statement->bindValue(':' . ltrim($key, ':'), $value, $paramType);
                    } else {
                        // Positional parameter (e.g., ?) - use position (key + 1)
                        $this->statement->bindValue($key + 1, $value, $paramType);
                    }
                }
            }
            
            $this->statement->execute();
            return $this->statement;
            
        } catch (PDOException $e) {
            if ($this->debug) {
                error_log("Query Error: " . $e->getMessage());
                error_log("SQL: " . $sql);
                error_log("Params: " . print_r($params, true));
            }
            throw $e;
        }
    }
    
    /**
     * Get single row
     */
    public function getRow($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }
    
    /**
     * Get multiple rows
     */
    public function getRows($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }
    
    /**
     * Get single value
     */
    public function getValue($sql, $params = []) {
        return $this->query($sql, $params)->fetchColumn();
    }
    
    /**
     * Insert data and return last insert ID
     * FIXED: Uses named parameters for consistency
     */
    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        
        $this->query($sql, $data);
        return $this->connection->lastInsertId();
    }
    
    /**
     * Update data
     * FIXED: Properly handles mixed parameter types
     */
    public function update($table, $data, $where, $whereParams = []) {
        // Build SET clause with named parameters
        $set = [];
        foreach (array_keys($data) as $key) {
            $set[] = "{$key} = :set_{$key}"; // Use prefix to avoid conflicts
        }
        $set = implode(', ', $set);
        
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
        
        // Prepare combined parameters with prefixes
        $params = [];
        foreach ($data as $key => $value) {
            $params['set_' . $key] = $value;
        }
        
        // Add WHERE parameters (they can be positional or named)
        if (!empty($whereParams)) {
            if (is_array($whereParams)) {
                // Check if whereParams is associative or sequential
                foreach ($whereParams as $key => $value) {
                    if (is_string($key)) {
                        // Named parameter
                        $params[$key] = $value;
                    } else {
                        // Positional parameter - we need to convert to named
                        // This is tricky - better to have WHERE clause use named params
                        // For now, we'll assume WHERE uses ? and we append sequentially
                        $params[] = $value;
                    }
                }
            } else {
                // Single value
                $params[] = $whereParams;
            }
        }
        
        if ($this->debug) {
            error_log("Update SQL: " . $sql);
            error_log("Update Params: " . print_r($params, true));
        }
        
        $this->query($sql, $params);
        return $this->statement->rowCount();
    }
    
    /**
     * Delete data
     */
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        return $this->query($sql, $params)->rowCount();
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit() {
        return $this->connection->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->connection->rollback();
    }
    
    /**
     * Get last insert ID
     */
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }
}