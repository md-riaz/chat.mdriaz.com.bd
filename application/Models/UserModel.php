<?php

namespace App\Models;

use Framework\Core\Database;
use Framework\Core\DBManager;
use App\Enum\Status;
use App\Enum\PartyRole;
use App\Enum\PartyType;
use App\Enum\RoleType;
use App\Enum\PhoneType;
use App\Enum\SystemParty;
use App\Enum\ServiceType;
use App\Models\PhoneModel;

use Framework\Core\Auth;
use Framework\Core\Util;

use Exception;

class UserModel
{
    private static $db;

    public static function initDB()
    {
        if (!self::$db) {
            self::$db = new Database();
        }
        return self::$db;
    }

    public static function phoneNumberCheck($phone_number)
    {
        $db = self::initDB();
        return $db->query("SELECT * FROM phone_number AS pn
        JOIN party_phone AS pp ON pp.phone_id = pn.id 
        JOIN party AS p ON p.id = pp.party_id AND p.status = ?
        WHERE pp.status = ? AND pn.status = ? AND pn.number = ? ", [Status::ACTIVE->value, Status::ACTIVE->value, Status::ACTIVE->value, $phone_number])->numRows();
    }

    public static function addParty($name, $phone, $email, $password, $business_name, $timezone, $party_id = NULL)
    {
        $db = self::initDB();
        $owner_id = NULL;

        try {
            $db->beginTransaction();
            $status = Status::ACTIVE->value;

            if (!empty($business_name)) {

                $organizationPartyId = self::insertParty($business_name, PartyType::ORGANIZATION->value, SystemParty::IPBXManagement->value, $status);

                self::insertPartyRole($organizationPartyId, PartyRole::CUSTOMER->value, $status, SystemParty::IPBXManagement->value);

                $individualPartyId = self::insertParty($name, PartyType::INDIVIDUAL->value, $organizationPartyId, $status);

                self::insertPartyRole($individualPartyId, PartyRole::ADMINISTRATOR->value, $status, $organizationPartyId);

                $owner_id = $organizationPartyId;
            } else {

                $individualPartyId = self::insertParty($name, PartyType::INDIVIDUAL->value, $party_id ?? SystemParty::IPBXManagement->value, $status);

                if (empty($party_id)) {
                    self::insertPartyRole($individualPartyId, PartyRole::CUSTOMER->value, $status, SystemParty::IPBXManagement->value);
                }

                self::insertPartyRole($individualPartyId, PartyRole::ADMINISTRATOR->value, $status, $individualPartyId ?? SystemParty::IPBXManagement->value);

                $owner_id = $individualPartyId;
            }

            // $db->query("INSERT INTO party_setting( party_id, category, name, value, created, updated) VALUES (?, ?, ?, ?, ?, ?)", [$individualPartyId, 'time_zone', 'name', $timezone, TIMESTAMP, TIMESTAMP]);

            $setting_id = SettingsModel::getSettingId('user_profile', 'timezone');

            if (empty($setting_id)) {
                throw new Exception("Setting not found");
            } 

            SettingsModel::updateSetting(
                $owner_id,
                $individualPartyId,
                $setting_id,
                $timezone
            );

            $type = PhoneType::MAIN->value;

            $is_primary = 1;

            self::createPartyPhoneRelation($type, $is_primary, $phone, $individualPartyId);

            self::createPartyEmailRelation($individualPartyId, $email);

            self::addLogin($individualPartyId, $email, $password);

            $db->commit();

            return [
                'owner_id' => $owner_id,
                'party_id' => $individualPartyId,
            ];
        } catch (Exception $e) {

            $db->rollBack();
        }

        return false;
    }

    public static function addLogin($party_id, $email, $password)
    {
        $db = self::initDB();

        $email_id = self::getEmailId($email);

        $db->query('INSERT INTO login(party_id, email_id, password,created, updated) VALUES (?, ?, ?,?, ?)', [$party_id, $email_id, $password, TIMESTAMP, TIMESTAMP]);

        return $db->lastInsertID();
    }

    private static function insertParty($name, $party_type, $parent, $status)
    {
        $db = self::initDB();

        return $db->query("INSERT INTO party(name, type, parent, status, created, updated) VALUES (?, ?, ?, ?, ?, ?)", [$name, $party_type, $parent, $status, TIMESTAMP, TIMESTAMP])->lastInsertID();
    }

    private static function insertPartyRole($party_id, $party_role, $status, $org_id = 0)
    {
        $db = self::initDB();

        $db->query('INSERT INTO party_role(party_id, role_id, "from", status, created, updated, organization_id) VALUES (?, ?, ?, ?, ?, ?, ?)', [$party_id, $party_role, TIMESTAMP, $status, TIMESTAMP, TIMESTAMP, $org_id]);

        return $db->lastInsertID();
    }

    public static function createPartyPhoneRelation($type, $is_primary, $phone, $party_id)
    {
        $db = self::initDB();

        $phone_id = PhoneModel::getPhoneId($phone);

        $partyPhone = $db->query("SELECT phone_id FROM party_phone WHERE party_id = ? AND phone_id = ? AND `to` IS NULL AND status = ? AND type = ?", [$party_id, $phone_id, Status::ACTIVE->value, $type])->fetchArray();

        // Phone type update 
        if (empty($partyPhone)) {
            $db->query('INSERT INTO party_phone (party_id, phone_id, `from`, is_primary ,`type`, `created`, `updated`) VALUES (?, ?, ?, ?, ?, ?, ?)', [$party_id, $phone_id, TIMESTAMP, $is_primary, $type, TIMESTAMP, TIMESTAMP]);
        }
    }

    private static function createPartyEmailRelation($party_id, $email)
    {
        $db = self::initDB();
        $email_id = self::getEmailId($email);
        $db->query('INSERT INTO party_email(party_id, email_id, "from", created, updated) VALUES (?, ?, ?, ?, ?)', [$party_id, $email_id, TIMESTAMP, TIMESTAMP, TIMESTAMP]);
    }

    public static function getEmailId($email)
    {
        $db = self::initDB();

        $checkEmail = $db->query("SELECT * FROM email WHERE email = ?", [$email])->fetchArray();

        if (empty($checkEmail)) {
            return $db->query('INSERT INTO email(email,created,updated) VALUES (?,?,?)', [$email, TIMESTAMP, TIMESTAMP])->lastInsertID();
        }

        return $checkEmail['id'];
    }

    public static function emailDuplicateCheck($email, $account_id = 0)
    {
        $db = self::initDB();
        return $db->query("SELECT * FROM email AS e 
      JOIN party_email AS pe ON e.id = pe.email_id 
      JOIN login AS l ON pe.email_id = l.email_id
      WHERE pe.status = ? AND pe.party_id != ? AND e.email = ? ", [Status::ACTIVE->value, $account_id, $email])->numRows();
    }

    public static function getUserName($party_id)
    {

        $db = self::initDB();

        $party = $db->query("SELECT name FROM party WHERE id = ? AND status = ?", [$party_id, Status::ACTIVE->value])->fetchArray();

        return $party['name'] ?? '';
    }

    public static function UsersList($limit = null)
    {
        $db = self::initDB();

        $query = "SELECT
            p.id, p.name, p.status AS enabled,
            TO_CHAR(p.created, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS created,
            r.name AS role, pn.number,
            e.email
            FROM party AS p
            JOIN party_role AS pr ON pr.party_id = p.id AND pr.status = ?
            JOIN role AS r ON r.id = pr.role_id AND r.type = ? AND r.status = ?
            JOIN party_email pe ON p.id = pe.party_id AND pe.to IS NULL AND pe.status = ?
            JOIN email e ON pe.email_id = e.id
            JOIN party_phone pp ON p.id = pp.party_id AND pp.status = ?
            JOIN phone_number pn ON pp.phone_id = pn.id
            WHERE pr.organization_id = ? AND p.status = ? ORDER BY p.id DESC";

        if ($limit) {
            $query .= " LIMIT $limit";
        }

        return $db->query(
            $query,
            [
                Status::ACTIVE->value,
                RoleType::ORGANIZATION->value,
                Status::ACTIVE->value,
                Status::ACTIVE->value,
                Status::ACTIVE->value,
                Auth::$ownerId,
                Status::ACTIVE->value,
            ]
        )->fetchAll();
    }

    public static function getActiveUsersCount()
    {
        $db = self::initDB();

        $owner_id = Auth::$ownerId;

        return $db->query(
            "SELECT COUNT(*) AS count
            FROM party AS p
            JOIN party_role AS pr ON pr.party_id = p.id AND pr.status = :active_status
            JOIN role AS r ON r.id = pr.role_id AND r.type = :role_type AND r.status = :active_status
            WHERE pr.organization_id = :owner_id AND p.status = :active_status",
            [
                ':active_status' => Status::ACTIVE->value,
                ':role_type' => RoleType::ORGANIZATION->value,
                ':owner_id' => $owner_id
            ]
        )->fetchArray()['count'];
    }

    public static function AllUsersList()
    {
        $db = self::initDB();

        return $db->query(
            "SELECT
            p.id, p.name, p.status AS enabled, st.name AS status,
            TO_CHAR(p.created, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS created,
            r.name AS role, pn.number, c.format_template,
            e.email
            FROM party AS p
            JOIN party_role AS pr ON pr.party_id = p.id AND pr.to IS NULL AND pr.status != ?
            JOIN role AS r ON r.id = pr.role_id AND r.type = ? AND r.status = ?
            JOIN party_email pe ON p.id = pe.party_id AND pe.status = ?
            JOIN email e ON pe.email_id = e.id
            LEFT JOIN party_phone pp ON p.id = pp.party_id AND pp.status = ?
            LEFT JOIN phone_number pn ON pp.phone_id = pn.id
            LEFT JOIN country AS c ON c.id = pn.country_id
            JOIN status st ON pr.status = st.id
            WHERE pr.organization_id = ? AND p.status = ? ORDER BY p.id DESC",
            [
                Status::DELETED->value,
                RoleType::ORGANIZATION->value,
                Status::ACTIVE->value,
                Status::ACTIVE->value,
                Status::ACTIVE->value,
                Auth::$ownerId,
                Status::ACTIVE->value,
            ]
        )->fetchAll();
    }

    public static function checkOrganizationUser($party_id, $org_id)
    {

        $db = self::initDB();

        $user = $db->query(
            "SELECT
            p.id, p.name
            FROM party AS p
            JOIN party_role AS pr ON pr.party_id = p.id AND pr.status = ?
            JOIN role AS r ON r.id = pr.role_id AND r.type = ? AND r.status = ?
            WHERE pr.organization_id = ? AND p.status = ? AND p.id = ?",
            [
                Status::ACTIVE->value,
                RoleType::ORGANIZATION->value,
                Status::ACTIVE->value,
                $org_id,
                Status::ACTIVE->value,
                $party_id
            ]
        )->numRows();

        return $user;
    }

    public static function getOrganizations($party_id)
    {

        $db = self::initDB();
        $organizations = $db->query(
            'SELECT
                p.id,
                p.name,
                r.name AS role
                FROM
                party AS p
                JOIN party_role AS pr ON p.id = pr.organization_id
                AND pr.party_id = :party_id
                AND pr.status = :active_status
                JOIN role AS r ON r.id = pr.role_id AND pr.status = :active_status AND r.type = :role_type
                JOIN entity_type AS et ON et.id = r.type
                WHERE
                p.status = :active_status
                ORDER BY
                pr.created ASC',
            [
                'party_id' => $party_id,
                'active_status' => Status::ACTIVE->value,
                'role_type' => RoleType::ORGANIZATION->value
            ]
        )->fetchAll();

        return $organizations;
    }

    public static function generateTokenForOrganization($org_id, $tokenId)
    {
        $db = self::initDB();

        $currentToken = $db->query("SELECT * FROM `token` WHERE id = ?", $tokenId)->fetchArray();

        // disable current token
        // $db->query("UPDATE `token` SET `expire` = 1 WHERE id = ?", [$tokenId]);

        $origin = isset($_SERVER['HTTP_ORIGIN']) ? parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) : null;

        $token_data = [
            'token' => Util::generateRandomString(64),
            'login_id' => $currentToken['login_id'],
            'type' => 'login',
            'origin' => $origin ?? $currentToken['origin'],
            'remember' => 0,
            'ip' => Util::getClientIP() ?? '',
            'data' => '',
            'created' => TIMESTAMP,
            'device_id' => $currentToken['device_id'],
            'organization_id' => $org_id,
        ];

        $tokenInsert = $db->query(

            "INSERT INTO `token` (`login_id`, `token`, `type`, `origin`, `remember`, `data`, `ip_address`, `created`, `device_id`, `organization_id`) VALUES (?,?,?,?,?,?,?,?,?,?)",
            [
                $token_data['login_id'],
                $token_data['token'],
                $token_data['type'],
                $token_data['origin'],
                $token_data['remember'],
                $token_data['data'],
                $token_data['ip'],
                $token_data['created'],
                $token_data['device_id'],
                $token_data['organization_id'],
            ]

        )->affectedRows();


        if ($tokenInsert) {

            return $token_data['token'];
        }

        return false;
    }

    public static function getPartyRoles($partyId)
    {
        $db = self::initDB();

        return $db->query(
            "SELECT
                r.id AS role_id,
                r.name AS role,
                et.name AS type,
                pr.organization_id
                FROM
                party_role AS pr
                JOIN role AS r ON r.id = pr.role_id
                AND r.status = :active_status AND r.type = :role_type
                JOIN entity_type AS et ON et.id = r.type
                WHERE
                pr.party_id = :party_id
                AND pr.status = :active_status
                ORDER BY pr.created ASC",
            [
                ':active_status' => Status::ACTIVE->value,
                ':role_type' => RoleType::ORGANIZATION->value,
                ':party_id' => $partyId
            ]
        )->fetchAll();
    }

    public static function getPartyLastLoginOrganizationId($party_id)
    {
        $db = self::initDB();

        $loginIds = self::getPartyLoginIds($party_id);

        $data =  $db->query(
            "SELECT organization_id FROM token WHERE type = 'login' AND login_id IN (" . implode(',', $loginIds) . ") ORDER BY created DESC LIMIT 1",
        )->fetchArray();

        return $data['organization_id'] ?? 0;
    }

    public static function getPartyLoginIds($party_id)
    {
        $db = self::initDB();

        $logins = $db->query(
            "SELECT id FROM login WHERE party_id = :party_id AND status = :active_status",
            [
                'party_id' => $party_id,
                'active_status' => Status::ACTIVE->value
            ]
        )->fetchAll();

        return array_column($logins, 'id');
    }

    public static function saveOrderRequest($token, $fields,  $mode = null)
    {

        $db = self::initDB();

        if (empty($token)) {
            return false; // Token is required
        }

        // Auto-detect mode if not provided
        if ($mode !== 'create' && $mode !== 'update') {
            $existing = $db->query("SELECT id FROM order_request WHERE token = ?", [$token])->fetchArray();
            $mode = $existing ? 'update' : 'create';
        }

        // Always include updated_at
        $fields['updated_at'] = TIMESTAMP;

        // Convert JSON fields if needed
        foreach (['order_details', 'request_details'] as $jsonField) {
            if (isset($fields[$jsonField])) {
                $fields[$jsonField] = json_encode($fields[$jsonField]);
            }
        }

        if ($mode === 'create') {
            $fields['token'] = $token;
            $fields['created_at'] = TIMESTAMP;

            $columns = array_keys($fields);
            $placeholders = array_fill(0, count($columns), '?');
            $values = array_values($fields);

            $sql = "INSERT INTO order_request (" . implode(', ', array_map(fn($col) => "`$col`", $columns)) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $db->query($sql, $values);
        } elseif ($mode === 'update') {

            $setParts = [];
            $values = [];

            foreach ($fields as $key => $value) {
                $setParts[] = "`$key` = ?";
                $values[] = $value;
            }

            $values[] = $token;
            $sql = "UPDATE order_request SET " . implode(', ', $setParts) . " WHERE `token` = ?";
            $db->query($sql, $values);
        }
    }
}
