<?php

require_once __DIR__ . "/../framework/core/Framework.php";
Framework::run();

use Framework\Core\DBManager;
use Framework\Queue\TaskInterface;

const SLEEP_INTERVAL = 60; // Sleep for 60 seconds between checks

/**
 * Scheduler Daemon for running scheduled tasks.
 */
class Scheduler
{
    private $db;

    /**
     * Constructor initializes the database connection.
     */
    public function __construct()
    {
        $this->db = DBManager::getDB();
    }

    /**
     * Main loop for the scheduler daemon.
     * Handles signal for graceful shutdown and runs tasks as needed.
     */
    public function run()
    {
        declare (ticks = 1);

        // Register signal handler for graceful shutdown
        pcntl_signal(SIGTERM, function () {
            $this->logMessage("Received SIGTERM, exiting...");
            exit;
        });

        $this->logMessage("[*] Scheduler Daemon Started...");

        while (true) {
            $now = new DateTime('now', new DateTimeZone('UTC'));

            // Fetch all enabled scheduled tasks
            $tasks = $this->fetchScheduledTasks();

            foreach ($tasks as $task) {
                $this->logMessage("Found scheduled task: {$task['name']}");

                $run = false;

                // Parse schedule string for "every X min" format
                if (preg_match('/every\s+(\d+)\s+min/i', $task['schedule'], $m)) {
                    $minutes = (int) $m[1];
                    $last    = new DateTime($task['last_run_at'] ?? '1970-01-01 00:00:00', new DateTimeZone('UTC'));

                    $diff = $now->getTimestamp() - $last->getTimestamp();

                    // Check if enough time has passed since last run
                    if ($diff >= $minutes * 60) {
                        $run = true;
                    }
                }

                if ($run) {
                    $this->logMessage("[✓] Running task: {$task['name']}");

                    try {
                        // Ensure the task class exists
                        if (! class_exists($task['class_name'])) {
                            throw new Exception("Class {$task['class_name']} not found.");
                        }

                        // Instantiate the task and check interface
                        $taskInstance = new $task['class_name']();

                        if (! $taskInstance instanceof TaskInterface) {
                            throw new Exception("Class {$task['class_name']} does not implement TaskInterface.");
                        }

                        // Execute the task directly
                        $taskInstance->handle();

                        // Update last run timestamp
                        $updated = $this->updateLastRun($task['id']);
                        $this->logMessage("[✓] Successfully executed {$task['name']}");

                        if ($updated === 0) {
                            $this->logMessage("[✗] Error updating last run timestamp for {$task['name']}");
                        }

                    } catch (\Throwable $e) {
                        // Log any errors during task execution
                        $this->logMessage("[✗] Error executing {$task['name']}: " . $e->getMessage());
                    }
                }
            }

            // Sleep before next check
            sleep(SLEEP_INTERVAL);
        }
    }

    /**
     * Fetch all enabled scheduled tasks from the database.
     * @return array
     */
    private function fetchScheduledTasks()
    {
        return $this->db->query("SELECT * FROM scheduled_tasks WHERE enabled = 1")->fetchAll();
    }

    /**
     * Log a message with a timestamp.
     * @param string $message
     */
    private function logMessage($message)
    {
        echo $this->getTimestamp() . " [Scheduler] {$message}\n";
    }

    /**
     * Get the current timestamp.
     * Uses TIMESTAMP constant if defined, otherwise current time.
     * @return string
     */
    private function getTimestamp()
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Update the last_run_at field for a scheduled task.
     * @param int $taskId
     * @return int Number of affected rows
     */
    private function updateLastRun($taskId)
    {
        return $this->db->query("UPDATE scheduled_tasks SET last_run_at = ? WHERE id = ?", [$this->getTimestamp(), $taskId])->affectedRows();
    }
}

// Start the scheduler daemon
$scheduler = new Scheduler();
$scheduler->run();
// Ensure graceful shutdown on script termination
pcntl_signal_dispatch(); // Dispatch any pending signals
// This is necessary to ensure the signal handler is called if the script is terminated
