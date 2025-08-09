<?php

namespace App\Api\Models;

use Framework\Core\Database;

class MessageAttachmentModel
{
    private static $db;

    public static function initDB()
    {
        if (!self::$db) {
            self::$db = new Database();
        }
        return self::$db;
    }

    /**
     * Add attachment to a message (alias for createAttachment)
     */
    public static function addAttachment($messageId, $fileUrl, $mimeType, $fileSize = null, $originalName = null, $uploaderId = 1)
    {
        return self::createAttachment($messageId, $uploaderId, $fileUrl, $mimeType, $fileSize, $originalName);
    }

    public static function createAttachment($messageId, $uploaderId, $fileUrl, $mimeType = null, $size = null, $originalName = null)
    {
        $db = self::initDB();

        $result = $db->query(
            "INSERT INTO message_attachments (message_id, uploader_id, file_url, mime_type, size, original_name, uploaded_at, linked)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), 1)",
            [$messageId, $uploaderId, $fileUrl, $mimeType, $size, $originalName]
        );

        return $db->lastInsertId();
    }

    public static function createUnlinkedAttachment($uploaderId, $fileUrl, $mimeType = null, $size = null, $originalName = null)
    {
        $db = self::initDB();

        $result = $db->query(
            "INSERT INTO message_attachments (uploader_id, file_url, mime_type, size, original_name, uploaded_at, linked)
             VALUES (?, ?, ?, ?, ?, NOW(), 0)",
            [$uploaderId, $fileUrl, $mimeType, $size, $originalName]
        );

        return $db->lastInsertId();
    }

    public static function linkAttachmentToMessage($attachmentId, $messageId)
    {
        $db = self::initDB();

        return $db->query(
            "UPDATE message_attachments SET message_id = ?, linked = 1 WHERE id = ?",
            [$messageId, $attachmentId]
        );
    }

    public static function getMessageAttachments($messageId)
    {
        $db = self::initDB();

        return $db->query(
            "SELECT * FROM message_attachments WHERE message_id = ? ORDER BY uploaded_at ASC",
            [$messageId]
        )->fetchAll();
    }

    public static function getAttachmentById($attachmentId)
    {
        $db = self::initDB();

        return $db->query(
            "SELECT * FROM message_attachments WHERE id = ?",
            [$attachmentId]
        )->fetchArray();
    }

    public static function deleteAttachment($attachmentId, $uploaderId = null)
    {
        $db = self::initDB();

        $whereClause = "WHERE id = ?";
        $params = [$attachmentId];

        if ($uploaderId) {
            $whereClause .= " AND uploader_id = ?";
            $params[] = $uploaderId;
        }

        return $db->query(
            "DELETE FROM message_attachments $whereClause",
            $params
        );
    }

    public static function cleanupUnlinkedAttachments($olderThanHours = 24)
    {
        $db = self::initDB();

        return $db->query(
            "DELETE FROM message_attachments
             WHERE linked = 0 AND uploaded_at < DATE_SUB(NOW(), INTERVAL ? HOUR)",
            [$olderThanHours]
        );
    }
}

