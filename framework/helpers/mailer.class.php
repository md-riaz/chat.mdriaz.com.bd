<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require FRAMEWORK_PATH . 'libraries/PHPMailer/PHPMailer.php';
require FRAMEWORK_PATH . 'libraries/PHPMailer/Exception.php';
require FRAMEWORK_PATH . 'libraries/PHPMailer/SMTP.php';

class Mailer extends PHPMailer
{

	public function __construct($mailConfig = null)
	{
		$mailConfig = $mailConfig ?? [];

		if (($mailConfig['system_email.mailer'] ?? MAILER) == 'SMTP') {
			$this->isSMTP();
			$this->SMTPAuth = true;
			//$this->SMTPSecure = "tls";
			$this->Host = $mailConfig['system_email.host'] ?? SMTP_HOST;
			$this->Port = $mailConfig['system_email.port'] ?? SMTP_PORT;
			$this->Username = $mailConfig['system_email.email'] ?? SMTP_USER;
			$this->Password = $mailConfig['system_email.password'] ?? SMTP_PASS;
		}

		$this->IsHTML(true);
		$this->CharSet = 'UTF-8';

		$from = (filter_var($mailConfig['system_email.email'] ?? SMTP_USER, FILTER_VALIDATE_EMAIL) ? $mailConfig['system_email.email'] ?? SMTP_USER : 'noreply@' . parse_url(SMSAPP_URL, PHP_URL_HOST));
		$this->SetFrom($from, $mailConfig['site_title'] ?? SITE_TITLE);
	}
}
