<?php

namespace App;

use App\Models\SettingsModel;
use Framework\Core\Controller;
use Framework\Core\Database;
use Framework\Core\Auth;
use Framework\Core\Util;
use App\Enum\SystemParty;
use App\Enum\EventType;
use App\Enum\Status;
use App\Enum\TokenType;
use App\Enum\PartyType;
use App\Enum\ServiceType;
use App\Models\EventModel;
use App\Models\UserModel;
use App\Models\PhoneModel;
use App\Models\VoiceModel;
use App\Models\ExtensionModel;
use App\Models\PhonenumberModel;
use App\Models\ServiceModel;
use App\Services\BandwidthService;
use App\Services\FusionPBX\Bridge;
use App\Services\FusionPBX\Destination;
use App\Services\FusionPBX\Domain;
use App\Services\FusionPBX\Efax;
use App\Services\FusionPBX\Helper;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Exception;

class User extends Controller
{

    public function __construct()
    {
        $this->db = new Database;
    }

    public function Index()
    {
        $this->data = [
            'error' => 0,
            'msg' => 'Success',
            'data' => [
                'item' => [],
            ],
        ];
        $this->response();
    }

    public function Login()
    {

        $json = file_get_contents('php://input');
        $_POST = json_decode($json, true);

        if (empty($_POST['email']) || empty($_POST['password'])) {
            $this->data = ['error' => 400, 'msg' => 'Missing required parameters'];
            $this->response();
        }

        $partyQuery = $this->db->query(
            "SELECT
            p.id, p.name, p.status, l.password,l.id as login_id, l.type, ph.number AS phone, e.email
            FROM login AS l
            JOIN party AS p ON p.id = l.party_id
            JOIN email AS e ON e.id = l.email_id
            JOIN party_phone AS pp ON pp.party_id = p.id AND pp.status = ?
            JOIN phone_number AS ph ON ph.id = pp.phone_id
            WHERE e.email = ?
            AND p.status != ?
            LIMIT 1",
            [Status::ACTIVE->value, $_POST['email'], Status::DELETED->value]
        );


        if ($partyQuery->numRows() > 0) {

            $partyInfo = $partyQuery->fetchArray();


            if (password_verify($_POST['password'], $partyInfo['password'])) {

                if ($partyInfo['status'] !== Status::ACTIVE->value) {

                    $this->data = ['error' => 403, 'msg' => 'Your account is not active.'];
                } else {

                    $remember_me = 0;

                    $user_agent = $_POST['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? '';

                    if (empty($user_agent)) {
                        $this->data = ['error' => 400, 'msg' => 'Missing user agent.'];
                        $this->response();
                    }

                    $deviceId = $this->getDeviceId($partyInfo['id'], $user_agent, $_POST['platform'] ?? '');

                    // logic to decide which organization the person will login now

                    // check active party role 
                    $partyRoles = UserModel::getPartyRoles($partyInfo['id']);

                    if (empty($partyRoles)) {
                        $this->data = ['error' => 400, 'msg' => 'Sorry! You are not a member of any organization.'];
                        $this->response();
                    }

                    $lastLoggedInOrgId = UserModel::getPartyLastLoginOrganizationId($partyInfo['id']);

                    if (!in_array($lastLoggedInOrgId, array_column($partyRoles, 'organization_id'))) {
                        $lastLoggedInOrgId = $partyRoles[0]['organization_id'];
                    }

                    $partyInfo['owner_id'] = !empty($lastLoggedInOrgId) ? $lastLoggedInOrgId : $partyRoles[0]['organization_id'];

                    $origin = '';

                    if (!empty($_POST['origin'])) {
                        $origin = $_POST['origin'];
                    } elseif (!empty($_SERVER['HTTP_ORIGIN'])) {
                        $parsed_origin = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
                        $origin = $parsed_origin !== false ? $parsed_origin : '';
                    }

                    $token_data = [
                        'token' => Util::generateRandomString(64),
                        'login_id' => $partyInfo['login_id'],
                        'type' => 'login',
                        'origin' => $origin,
                        'remember' => $remember_me,
                        'ip' => $_POST['client_ip'] ?? Util::getClientIP() ?? '',
                        'data' => '',
                        'created' => TIMESTAMP,
                        'device_id' => $deviceId,
                        'organization_id' => $partyInfo['owner_id'],
                    ];

                    $tokenInsert = $this->db->query(

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

                    if (!$tokenInsert) {
                        $this->data = ['error' => 500, 'msg' => 'Failed to generate token'];
                        $this->response();
                    }

                    $data = $this->getLoginData($partyInfo);

                    $this->data = [
                        'error' => 0,
                        'msg' => 'Success',
                        'token' => $token_data['token'],
                        'data' => $data,
                    ];
                }

                $this->response();
            }
        }

        $this->data = ['error' => 400, 'msg' => 'Incorrect email or password.'];

        $this->response();
    }

    public function Register()
    {

        $request = json_decode(file_get_contents('php://input'), true) ?? $_REQUEST ?: [];

        // Extract data from the POST request
        $name = !empty($request['name']) && is_string($request['name']) ? trim($request['name']) : NULL;
        $business_name = !empty($request['business_name']) && is_string($request['business_name']) ? trim($request['business_name']) : NULL;
        $phone = !empty($request['phone']) && is_string($request['phone']) ? trim($request['phone']) : NULL;
        $email = !empty($request['email']) && is_string($request['email']) ? strtolower(trim($request['email'])) : NULL;
        $password = !empty($request['password']) && is_string($request['password']) ? $request['password'] : NULL;
        $timezone = !empty($request['timezone']) && is_string($request['timezone']) ? $request['timezone'] : NULL;
        $cart = !empty($request['cart']) && is_array($request['cart']) ? $request['cart'] : [];
        $request_token = !empty($request['requestToken']) && is_string($request['requestToken']) ? trim($request['requestToken']) : NULL;

        if (empty($name) || empty($phone) || empty($email) || empty($password) || empty($timezone)) {
            //response with error
            $this->responseWithError(400, 'Missing required parameters');
        }

        if (!in_array($timezone, timezone_identifiers_list())) {
            //response with error
            $this->responseWithError(400, 'Invalid timezone');
        }

        // Validate the length of the name
        if (strlen($name) > 250) {
            $this->responseWithError(400, 'Name is too long');
        }

        if (empty($cart['number'])) {
            $this->responseWithError(400, 'You must select a number');
        }

        if (!PhoneModel::getCountry($cart['number'])) {
            $this->responseWithError(400, 'You must select a valid number');
        }

        $serviceTypeIds = [];
        if (!empty($cart['services'])) {
            foreach ($cart['services'] as $service) {
                $serviceTypeIds[] = ServiceType::getValue($service);
            }
        }
        if (empty($serviceTypeIds)) {
            $this->responseWithError(400, 'You must select a service');
        }

        $emailDuplicateCheck = UserModel::emailDuplicateCheck($email);

        if ($emailDuplicateCheck) {
            $this->responseWithError(400, 'Email already exists');
        }

        // Verify Mobile Number and Email Format
        $validatedCountryphone = PhoneModel::getCountry($phone);

        if (!$validatedCountryphone) {
            $this->responseWithError(400, 'Invalid phone number');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->responseWithError(400, 'Invalid email');
        }

        // Password verification
        if (strlen($password) < 8 || strlen($password) > 20) {
            $this->responseWithError(400, 'Password must be between 8 and 20 characters');
        }
        // Hash the password
        $password = password_hash(trim($password), PASSWORD_DEFAULT);

        $phoneDuplicateCheck = UserModel::phoneNumberCheck($phone);

        if ($phoneDuplicateCheck) {
            $this->responseWithError(400, 'Phone number already exists');
        }

        $order_request = $this->db->query("SELECT token, phone_number, request_details FROM order_request WHERE token = ? AND phone_number = ?", [$request_token, $cart['number']])->fetchArray();

        if (empty($order_request)) {
            $this->responseWithError(400, 'Invalid request token');
        }

        $request_details = json_decode($order_request['request_details'] ?? '', true);

        $request_details['personalDetails'] = [
            'name' => $name,
            'business_name' => $business_name,
            'phone' => $phone,
            'email' => $email,
            'password' => $password,
            'timezone' => $timezone,
        ];

        UserModel::saveOrderRequest($request_token, [
            'request_details' => $request_details,
            'register_status' => Status::PENDING->value,
        ]);

        $serviceSettings = $request_details['serviceSettings'] ?? [];

        $caller_id_name = !empty(trim($serviceSettings['callerIdName'] ?? "")) ? trim($serviceSettings['callerIdName']) : NULL;
        $forward_number = null;
        if (($serviceSettings['onIncomingCall']['type'] ?? '') === 'call_forward') {
            $number = trim($serviceSettings['onIncomingCall']['value'] ?? '');
            if ($number !== '') {
                $forward_number = ltrim($number, '+');
            }
        }
        $play_recording =
            isset($serviceSettings['onIncomingCall']) &&
            $serviceSettings['onIncomingCall']['type'] === 'play_recording' &&
            $serviceSettings['onIncomingCall']['value'] === true;

        $fax_delivery_email = !empty(trim($serviceSettings['faxDeliveryEmail'] ?? ""))
            ? implode(',', array_filter(array_map('trim', explode(',', $serviceSettings['faxDeliveryEmail']))))
            : NULL;


        $metadata = [
            'label' => $caller_id_name ?? $business_name ?? $name,
        ];

        array_unshift($serviceTypeIds, ServiceType::NUMBER->value); //add number service to serviceTypeIds
        $isVoiceSelected = in_array(ServiceType::VOICE->value, $serviceTypeIds) ? true : false;
        $isEfaxSelected = in_array(ServiceType::EFAX->value, $serviceTypeIds) ? true : false;
        $phoneNumber = ltrim($order_request['phone_number'], "+");

        // fusionpbx operations for fax and voice service
        if ($isVoiceSelected || $isEfaxSelected) {

            // Create domain
            $domain_name = $business_name ?? $name;
            $domain = Domain::create($domain_name);

            // Create fax server
            if ($isEfaxSelected) {
                $faxServerData = [
                    'domain_name' => $domain['domain_name'],
                    'domain_uuid' => $domain['domain_uuid'],
                    'fax_caller_id_name' => $caller_id_name ?? $business_name ?? $name,
                    'fax_caller_id_number' => $phoneNumber,
                    'fax_email' => $fax_delivery_email,
                ];
                $fax = Efax::createServer($faxServerData);
            }

            // Create destination
            if (Destination::checkExists($phoneNumber)) {
                $this->responseWithError(400, 'Destination already exists');
            }

            $destinationData = [
                'domain_name' => $domain['domain_name'],
                'domain_uuid' => $domain['domain_uuid'],
                'destination_number' => $phoneNumber,
                'destination_caller_id_name' => $caller_id_name ?? $business_name ?? $name,
                'destination_caller_id_number' => $phoneNumber,
                'destination_actions' => []
            ];

            // Create bridge for call forward
            if (!empty($forward_number)) {
                $bidge = Bridge::add($forward_number, $domain['domain_uuid']);
                $destinationData['destination_actions'][] = ['application' => 'bridges', 'target' => $bidge['bridge_destination']];
            }
            // create and attach recording
            if ($play_recording) {
                $location = Helper::getDirectoryLocation('recordings');
                $file = $location['dir'] . '/on_incoming_call_recording.wav';
                $fileCopyPath = $location['dir'] . '/' . $domain['domain_name'] . '/' . 'on_incoming_call_recording.wav';
                $file_name = basename($file);

                if (file_exists($file)) {
                    if (copy($file, $fileCopyPath)) {
                        $recording_uuid = Helper::generate_unique_uuid(tableName: 'v_recordings', columnName: 'recording_uuid');
                        $this->fusionDB->query("INSERT INTO v_recordings (recording_uuid, domain_uuid, recording_filename, recording_name, recording_description, insert_date) 
                                               VALUES(?, ?, ?, ?, ?, ?)", [$recording_uuid, $domain['domain_uuid'], $file_name, $file_name, $file_name, TIMESTAMP]);
                        $destinationData['destination_actions'][] = ['application' => 'recordings', 'target' => $file_name];
                    }
                }
            }

            if ($isEfaxSelected) {

                $destinationData['fax_uuid'] = $fax['fax_uuid'];
                $destinationData['destination_type_fax'] = 1;

                if (empty($destinationData['destination_actions'])) {
                    $destinationData['destination_actions'][] = ['application' => 'faxes', 'target' => $fax['fax_extension']];
                }
            }

            if ($isVoiceSelected) {
                $destinationData['destination_type_voice'] = 1;
            }

            Destination::create($destinationData);
        }

        try {

            $this->db->beginTransaction();

            // insert party and services
            $party =  UserModel::addParty($name, $phone, $email, $password, $business_name, $timezone);
            $party_id = $party['owner_id'] ?? $party['party_id'];
            $phone_id = PhoneModel::getPhoneId($order_request['phone_number'], is_internal: 1);

            foreach ($serviceTypeIds as $serviceTypeId) {

                $service_settings = [
                    [
                        'name' => 'label',
                        'value' => $metadata['label'],
                    ]
                ];

                $serviceStatus = Status::PENDING->value;

                if ($serviceTypeId == ServiceType::NUMBER->value) {
                    $serviceStatus = Status::ACTIVE->value;
                }
                if ($serviceTypeId == ServiceType::EFAX->value) {
                    $metadata['fax_uuid'] = $fax['fax_uuid'];
                    $service_settings[] = [
                        'name' => 'business_name',
                        'value' => $metadata['label']
                    ];
                }
                // add service
                $service_id = ServiceModel::addService($party_id, $serviceTypeId, TIMESTAMP, $serviceStatus, $metadata);

                // add phone service
                ServiceModel::addPhoneService($phone_id, $service_id);

                if (!empty($forward_number) && $serviceTypeId == ServiceType::NUMBER->value) {
                    PhonenumberModel::saveCallForwardSettings($phone_id, $service_id, ('+' . $forward_number), Status::ACTIVE->value, $party['party_id'], $party_id, $bidge['bridge_uuid']);
                }

                // add service settings
                foreach ($service_settings as $setting) {
                    $this->db->query("INSERT INTO service_setting (service_id, category, name, value, created, updated) VALUES (?,?,?,?,?,?)", [$service_id, strtolower(ServiceType::from($serviceTypeId)->name), $setting['name'], $setting['value'], TIMESTAMP, TIMESTAMP]);
                }
            }

            $settingId = SettingsModel::getSettingId('notification', 'push');

            if (!empty($settingId)) {
                SettingsModel::updateSetting($party_id, $party['party_id'], $settingId, Status::ACTIVE->value);
            }

            UserModel::saveOrderRequest($request_token, [
                'party_id' => $party_id,
                'register_status' => Status::COMPLETED->value
            ]);

            $this->db->commit();

            $this->data = [
                'error' => 0,
                'msg' => 'Registration successful.',
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->data = [
                'error' => 400,
                'msg' => "Failed to create account",
            ];
        }

        $this->response();
    }

    private function responseWithError($errorCode, $message)
    {
        $this->data = ['error' => $errorCode, 'msg' => $message];
        $this->response();
    }



    public function Logout()
    {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) : '';

        $login = $this->db->query("SELECT id FROM `login` WHERE `party_id` = ?", [Auth::$partyId])->fetchArray();

        $logout = $this->db->query("UPDATE `token` SET `expire` = 1 WHERE `login_id` = ? AND `type` = 'login' AND `origin` = ? AND `id` = ?", [$login['id'], $origin, Auth::$session_id]);

        if ($logout->affectedRows() === 0) {

            $this->data = [
                'error' => 400,
                'msg' => 'Failed to logout!',
            ];

            $this->response();
        }

        $this->data = [
            'error' => 0,
            'msg' => 'Success',
        ];

        $this->response();
    }

    private function addSubscription($party_id)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

            $this->data = ['error' => 400, 'msg' => 'Invalid API request'];
            $this->response();
        }

        $json = file_get_contents('php://input');
        $_POST = json_decode($json, true);

        if (empty($_POST['deviceType']) || empty($_POST['token'])) {

            $this->data = ['error' => 400, 'msg' => 'Missing required parameters'];
            $this->response();
        }

        $deviceId = $this->getDeviceId($party_id, $_POST['userAgent'], $_POST['platform']);

        $getSubscription = $this->db->query("SELECT id, device_id FROM push_subscriptions WHERE device_id = ? AND status = ?", [$deviceId, Status::ACTIVE->value])->fetchArray();

        //if subscription already exist then disabled the old subscription
        if (!empty($getSubscription)) {
            $this->db->query("UPDATE push_subscriptions SET status = ? WHERE id = ?", [Status::DISABLED->value, $getSubscription['id']]);
        }

        //insert new subscription
        $this->db->query("INSERT INTO push_subscriptions (`device_id`,`platform`, `fcm_token`, `created`) VALUES(?,?,?,?)", [$deviceId, $_POST['deviceType'], $_POST['token'], TIMESTAMP]);
    }


    private function getDeviceId($partyId, $user_agent, $platform)
    {

        $deviceId = $this->db->query("SELECT id FROM user_devices WHERE party_id = ? AND user_agent = ? AND platform = ?", [$partyId, $user_agent, $platform])->fetchArray();

        if (empty($deviceId)) {
            $this->db->query("INSERT INTO user_devices(party_id, user_agent, platform, created) VALUES(?,?,?,?)", [$partyId, $user_agent, $platform, TIMESTAMP]);

            $deviceId = $this->db->lastInsertId();
        } else {
            $deviceId = $deviceId['id'];
        }

        return $deviceId;
    }

}
