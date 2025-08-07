<?php

namespace Framework\Queue;

use Framework\Queue\JobInterface;
use Framework\Core\DBManager;
use PDO;

class Dispatcher
{

    public static function dispatch(JobInterface $job)
    {
        $db = DBManager::getDB();

        $jobClass = get_class($job);
        $payload = serialize($job);

        // Check for serialization errors
        if ($payload === false) {
            throw new \InvalidArgumentException("Job payload could not be encoded to serialized format.");
        }

        $stmt = $db->prepare('INSERT INTO jobs (job_class,payload,created_at) VALUES (?, ?, ?)');

        $stmt->bindValue(1, $jobClass, PDO::PARAM_STR);
        $stmt->bindValue(2, $payload, PDO::PARAM_LOB);
        $stmt->bindValue(3, TIMESTAMP, PDO::PARAM_STR);

        $stmt->execute();

        return $stmt->rowCount();
    }
}
