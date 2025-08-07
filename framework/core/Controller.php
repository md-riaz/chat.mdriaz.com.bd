<?php
namespace Framework\Core;

class Controller
{

    public $data = array();

    public $db;

    public function response()
    {

        if (!$this->db) {

            $this->db = new Database;
        }

        $response = $log_response = json_encode($this->data);

        $endpoint = parse_url("http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]", PHP_URL_PATH);

        if (!in_array($endpoint, ['/polling', '/user/login'], true)) {
            $this->db->Query("INSERT INTO access_log (channel, endpoint, response, party_id, ip_address, created) VALUES (?, ?, ?, ?, ?, ?)", (isset($_SERVER['HTTP_AUTHORIZATION']) ? 'WEB' : 'API'), $endpoint, $log_response, Auth::$partyId ?? 0, Util::getClientIP(), TIMESTAMP);
        }

        echo $response;
        exit();
    }
}
