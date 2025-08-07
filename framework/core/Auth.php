<?php

namespace Framework\Core;

use App\Enum\SystemParty;
use App\Enum\Status;
use Framework\Core\Database;

class Auth
{

    public static $ownerId;

    public static $partyId;

    public static $name;

    public static $ownerType;

    public static $partyRoleIds;

    public static $session_id;

    public static $token;

    public static $authType;

    private static $db;


    public static function checkPermission($section, $controller, $action)
    {

        self::$db = new Database;

        //Check Permission

        $resource = $section . $controller;

        $checkResource = self::$db->query(
            " SELECT ar.id, pd.effect, pd.action
                                            FROM access_resource AS ar
                                            JOIN policy_resource AS pr ON pr.resource_id = ar.id 
                                            JOIN policy_definition AS pd ON pd.id = pr.definition_id
                                            JOIN role_policy AS rp ON rp.policy_id = pd.policy_id
                                            WHERE ar.resource = ? AND LOWER(pd.action) = ?",
            [$resource, strtolower($action)]
        )->fetchArray();

        if (!empty($checkResource)) {

            if (!self::authorized()) {
                echo json_encode(['error' => 405, 'msg' => 'Authorization required']);
                exit();
            }

            $ids = implode(',', self::$partyRoleIds);

            $action = strtolower($action);

            $all_roles_policy = self::$db->query(" WITH RECURSIVE role_hierarchy AS (
                                        SELECT id, name
                                        FROM role
                                        WHERE id IN ($ids)
                                    
                                        UNION ALL

                                        SELECT r.id, r.name
                                        FROM role r
                                        INNER JOIN role_hierarchy rh ON r.parent_id = rh.id
                                        )
                                        SELECT rh.id AS role_id, pd.effect
                                        FROM role_hierarchy AS rh
                                        JOIN role_policy AS rp ON rp.role_id = rh.id
                                        JOIN policy_definition AS pd ON pd.policy_id = rp.policy_id
                                        JOIN policy_resource AS pr ON pr.definition_id = pd.id
                                        WHERE pr.resource_id = {$checkResource['id']} AND LOWER(pd.action) = '{$action}'")->fetchAll();

            foreach ($all_roles_policy as $role_policy) {

                if ($role_policy['effect'] == 1) {

                    foreach ($all_roles_policy as $inner_policy) {
                        if (($inner_policy['role_id'] === self::$partyRoleIds[0]) && ($inner_policy['effect'] == 0)) {
                            return false;
                        }
                    }
                    return true;
                }
            }

            return false;
        }

        self::$db->close();

        return true;
    }

    private static function authorized()
    {
        $token = self::getTokenFromAuthorizationHeader();
        if ($token) {
            return self::authorizeWithToken($token);
        }

        $apiKey = self::getApiKeyFromRequest();

        if ($apiKey) {
            return self::authorizeWithApiKey($apiKey);
        }

        return false;
    }

    private static function getTokenFromAuthorizationHeader()
    {
        $authToken = explode(' ', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
        return $authToken[1] ?? null;
    }

    private static function getApiKeyFromRequest()
    {
        $jsonToken = json_decode((file_get_contents('php://input') ?? '{}'), true);
        return $_REQUEST['api_key'] ?? $jsonToken['api_key'] ?? null;
    }

    private static function authorizeWithToken($token)
    {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) : '';
        $timeout = date("Y-m-d H:i:s", strtotime(TOKEN_EXPIRATION ?? ''));
        $timeout5min = date("Y-m-d H:i:s", strtotime('-5 minutes'));

        $userQuery = self::$db->query(
            "SELECT s.id AS session_id, p.id, p.name, s.organization_id AS owner_id
                FROM `token` AS s
                JOIN login AS l ON l.id = s.login_id
                JOIN party AS p ON p.id = l.party_id AND p.status = ?
                WHERE s.token = ? AND s.expire = 0 
                    AND (
                        (s.type = 'login' AND (s.created > ? OR s.last_activity > ?)) 
                        OR 
                        (s.type = 'admin_login' AND (s.created > ? OR s.last_activity > ?))
                    )
                    AND origin = ?",
            Status::ACTIVE->value,
            $token,
            $timeout,
            $timeout,
            $timeout5min,
            $timeout5min,
            $origin
        );


        if (!empty($userQuery) && $userQuery->numRows() > 0) {
            $uinfo = $userQuery->fetchArray();
            self::$db->query("UPDATE token SET last_activity=? WHERE id = ?", TIMESTAMP, $uinfo['session_id']);
            self::$session_id = $uinfo['session_id'];
            self::$token = $token;

            // Retrieve party role ids and other necessary data
            self::retrievePartyData($uinfo);

            self::$authType = 'token';

            return true;
        }

        return false;
    }

    private static function authorizeWithApiKey($apiKey)
    {

        $userQuery = self::$db->query(
            "SELECT p.id, p.name, p.parent AS owner_id 
                FROM api_key AS apiKey 
                JOIN party AS p ON p.id = apiKey.party_id
                WHERE apiKey.`key` = ? AND p.status = ?
                ",
            [$apiKey, Status::ACTIVE->value]
        );

        if (!empty($userQuery) && $userQuery->numRows() > 0) {

            $uinfo = $userQuery->fetchArray();

            // update last used api key
            self::$db->query("UPDATE api_key SET last_used = ? WHERE `key` = ?", TIMESTAMP, $apiKey);

            $orgQuery = self::$db->query("SELECT id FROM party WHERE id = ? AND status = ?", [$uinfo['owner_id'], Status::ACTIVE->value]);

            if ($orgQuery->numRows() < 1) {
                return false;
            }

            // Retrieve party role ids and other necessary data
            self::retrievePartyData($uinfo);

            self::$authType = 'api_key';

            return true;
        }

        return false;
    }

    private static function retrievePartyData($uinfo)
    {
        self::$partyId = $uinfo['id'];
        self::$name = $uinfo['name'];
        self::$ownerId =   (empty($uinfo['owner_id']) || $uinfo['owner_id'] == SystemParty::IPBXManagement->value) ? $uinfo['id'] : $uinfo['owner_id'];
        self::$ownerType = (empty($uinfo['owner_id']) || $uinfo['owner_id'] == SystemParty::IPBXManagement->value) ? 'Individual' : 'Organization';

        self::$partyRoleIds = array_column(
            self::$db->query("SELECT role_id FROM party_role WHERE party_id = ? AND organization_id = ? AND status = ? ORDER BY role_id", [$uinfo['id'], self::$ownerId, Status::ACTIVE->value])->fetchAll(),
            'role_id'
        );
    }
}
