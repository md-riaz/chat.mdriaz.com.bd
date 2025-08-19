<?php

namespace App\Api\Models;

use Framework\Core\Model;
use Framework\Core\Util;

class AuthTokenModel extends Model
{
    protected static string $table = 'auth_tokens';
    protected array $fillable = [
        'user_id', 'token', 'ip_address', 'user_agent', 'device_id', 'created_at', 'expires_at', 'revoked_at'
    ];
    protected bool $timestamps = false;

    public static function createToken($userId, $ipAddress = null, $userAgent = null, $deviceId = null, $expiresAt = null)
    {
        $token = hash('sha256', Util::generateRandomString(64));

        $model = new static([
            'user_id'   => $userId,
            'token'     => $token,
            'ip_address'=> $ipAddress,
            'user_agent'=> $userAgent,
            'device_id' => $deviceId,
            'expires_at'=> $expiresAt,
        ]);
        $model->save();

        return $token;
    }

    public static function validateToken($token)
    {
        $db = static::db();

        return $db->query(
            "SELECT at.id as session_id, at.token, at.user_id, at.ip_address, at.user_agent,"
            . " at.device_id, at.created_at, at.expires_at, u.name, u.email, u.username, u.avatar_url"
            . " FROM auth_tokens at"
            . " JOIN users u ON at.user_id = u.id"
            . " WHERE at.token = ?"
            . " AND at.revoked_at IS NULL"
            . " AND (at.expires_at IS NULL OR at.expires_at > NOW())",
            [$token]
        )->fetchArray();
    }

    public static function revokeToken($token)
    {
        $auth = static::first(['token' => $token]);
        if (!$auth) {
            return false;
        }

        $auth->revoked_at = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $auth->save();
        return true;
    }

    public static function revokeUserTokens($userId, $exceptToken = null)
    {
        $conditions = [['user_id', '=', $userId]];

        if ($exceptToken) {
            $conditions[] = ['token', '!=', $exceptToken];
        }

        $tokens = AuthTokenModel::where($conditions);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        foreach ($tokens as $token) {
            $token->revoked_at = $now;
            $token->save();
        }

        return true;
    }
}

