<?php

namespace App\Api\Models;

use Framework\Core\Model;

class MessageAttachmentModel extends Model
{
    protected static string $table = 'message_attachments';
    protected array $fillable = [
        'message_id', 'uploader_id', 'file_url', 'mime_type', 'size',
        'original_name', 'uploaded_at', 'linked'
    ];
    protected bool $timestamps = false;

    /**
     * Add attachment to a message (alias for createAttachment)
     */
    public static function addAttachment($messageId, $fileUrl, $mimeType, $fileSize = null, $originalName = null, $uploaderId = 1)
    {
        return self::createAttachment($messageId, $uploaderId, $fileUrl, $mimeType, $fileSize, $originalName);
    }

    public static function createAttachment($messageId, $uploaderId, $fileUrl, $mimeType = null, $size = null, $originalName = null)
    {
        $attachment = new static([
            'message_id'   => $messageId,
            'uploader_id'  => $uploaderId,
            'file_url'     => $fileUrl,
            'mime_type'    => $mimeType,
            'size'         => $size,
            'original_name'=> $originalName,
            'linked'       => 1,
        ]);
        $attachment->save();
        return $attachment->id;
    }

    public static function createUnlinkedAttachment($uploaderId, $fileUrl, $mimeType = null, $size = null, $originalName = null)
    {
        $attachment = new static([
            'uploader_id'  => $uploaderId,
            'file_url'     => $fileUrl,
            'mime_type'    => $mimeType,
            'size'         => $size,
            'original_name'=> $originalName,
            'linked'       => 0,
        ]);
        $attachment->save();
        return $attachment->id;
    }

    public static function linkAttachmentToMessage($attachmentId, $messageId)
    {
        $attachment = static::find((int) $attachmentId);
        if (!$attachment) {
            return false;
        }
        $attachment->message_id = $messageId;
        $attachment->linked = 1;
        $attachment->save();
        return true;
    }

    public static function getMessageAttachments($messageId)
    {
        $db = static::db();

        return $db->query(
            "SELECT * FROM message_attachments WHERE message_id = ? ORDER BY uploaded_at ASC",
            [$messageId]
        )->fetchAll();
    }

    public static function getAttachmentById($attachmentId)
    {
        $attachment = static::find((int) $attachmentId);
        return $attachment?->toArray();
    }

    public static function deleteAttachment($attachmentId, $uploaderId = null)
    {
        $attachment = static::find((int) $attachmentId);
        if (!$attachment) {
            return false;
        }
        if ($uploaderId && $attachment->uploader_id != $uploaderId) {
            return false;
        }
        $attachment->delete();
        return true;
    }

    public static function cleanupUnlinkedAttachments($olderThanHours = 24)
    {
        $db = static::db();

        return $db->query(
            "DELETE FROM message_attachments"
            . " WHERE linked = 0 AND uploaded_at < DATE_SUB(NOW(), INTERVAL ? HOUR)",
            [$olderThanHours]
        );
    }
}

