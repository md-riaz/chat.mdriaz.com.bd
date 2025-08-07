<?php
class SMS
{

	private $api_token = '';

	public function sendSMS($to, $msg)
	{

		$params = array('api_key' => $this->api_token, 'to' => $to, 'msg' => $msg);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, "http://127.0.0.1/sendsms?" . http_build_query($params, '', '&'));
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type:application/json", "Accept:application/json"));
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

		$response = curl_exec($ch);
		curl_close($ch);

		if ($response) {

			$response = json_decode($response);

			if ($response->error == 0) {
				return true;
			}
		}

		return false;
	}
}
