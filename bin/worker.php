<?php

require_once __DIR__ . "/../framework/core/Framework.php";
Framework::run();

use App\Enum\Status;
use Framework\Core\DBManager;
use Framework\Queue\JobInterface;

const SLEEP_INTERVAL = 2;

/**
 * Worker class for processing queued jobs from the database.
 * Handles job fetching, processing, error handling, and graceful shutdown.
 */
class Worker
{
    private $db;

    /**
     * Initialize the Worker with a database connection.
     */
    public function __construct()
    {
        $this->db = DBManager::getDB();
    }

    /**
     * Main loop for the worker.
     * Listens for jobs, processes them, and handles signals for graceful shutdown.
     */
    public function run()
    {
        // Enable signal handling for graceful shutdown
        declare (ticks = 1);
        pcntl_signal(SIGTERM, function () {
            $this->logMessage("Received SIGTERM, exiting...");
            exit;
        });

        $this->logMessage("Worker started listening...");

        while (true) {

            $job = [];

            try {
                // Start transaction to fetch and lock a pending job
                $this->db->beginTransaction();

                $job = $this->fetchPendingJob();

                if (empty($job)) {
                    // No pending job found, commit the transaction
                    $this->db->commit(); // commit before continue

                    // No pending job found, sleep before retrying
                    sleep(SLEEP_INTERVAL);
                    continue;
                }

                // Attempt to mark the job as processing
                if (!$this->markJobProcessing($job['id'])) {
                    $this->logMessage("Job with ID {$job['id']} is no longer pending.");

                    // Job is no longer pending, commit the transaction
                    $this->db->commit();
                    // Sleep before retrying
                    sleep(SLEEP_INTERVAL);
                    continue;
                }

                // Process the fetched job
                $this->processJob($job);

                // Commit the transaction to release locks
                $this->db->commit();

            } catch (\Throwable $e) {

                // Rollback transaction in case of error
                $this->db->rollback();

                // Handle job failure and log the error
                $this->handleJobFailure($job['id'], $e->getMessage());
                $this->logMessage("Error occurred: " . $e->getMessage());

            }

            sleep(SLEEP_INTERVAL);

        }
    }

    /**
     * Fetch a single pending job from the database with row locking.
     *
     * @return array|null The job data or null if none found.
     */
    private function fetchPendingJob()
    {
        return $this->db->query(
            "SELECT * FROM jobs WHERE status = ? ORDER BY id ASC FOR UPDATE SKIP LOCKED LIMIT 1",
            [Status::PENDING->value]
        )->fetchArray();
    }

    /**
     * Mark a job as processing to prevent other workers from picking it up.
     *
     * @param int $jobId
     * @return bool True if the job was marked as processing, false otherwise.
     */
    private function markJobProcessing($jobId)
    {
        return $this->db->query(
            "UPDATE jobs SET status = ? WHERE id = ?",
            [Status::PROCESSING->value, $jobId]
        )->affectedRows() > 0;
    }

    /**
     * Unserialize and process the job payload.
     * Updates job status to completed on success.
     *
     * @param array $job The job data from the database.
     * @throws \Exception If job payload is invalid or processing fails.
     */
    private function processJob($job)
    {
        $jobInstance  = unserialize(stream_get_contents($job['payload']));
        $jobClassName = $job['job_class'];

        if ($jobInstance === false) {
            throw new \Exception("Failed to unserialize job payload for job ID {$job['id']}.");
        }

        if (! $jobInstance instanceof JobInterface) {
            throw new \Exception("Job class {$jobClassName} does not implement JobInterface.");
        }

        // Execute the job's handle method
        $jobInstance->handle();

        // Mark job as completed
        $this->db->query(
            "UPDATE jobs SET status = ?, processed_at = ? WHERE id = ?",
            [Status::COMPLETED->value, $this->getTimestamp(), $job['id']]
        );
    }

    /**
     * Handle job failure by updating its status and logging the error.
     *
     * @param int $jobId
     * @param string $errorMessage
     */
    private function handleJobFailure($jobId, $errorMessage)
    {
        $this->logMessage("Error processing job: " . $errorMessage);
        $this->db->query(
            "UPDATE jobs SET status = ?, failed_at = ?, error = ? WHERE id = ?",
            [Status::FAILED->value, $this->getTimestamp(), $errorMessage, $jobId]
        );
    }

    /**
     * Log a message with a timestamp.
     *
     * @param string $message
     */
    private function logMessage($message)
    {
        echo $this->getTimestamp() . " [Worker] {$message}\n";
    }

    /**
     * Get the current timestamp.
     *
     * @return string
     */
    private function getTimestamp()
    {
        return date('Y-m-d H:i:s');
    }
}

// Instantiate and run the worker
$worker = new Worker();
$worker->run();
