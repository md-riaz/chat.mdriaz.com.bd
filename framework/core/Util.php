<?php

namespace Framework\Core;
use DateTime;
use DateTimeZone;

class Util
{

    public static function generateRandomString($length = 32, $specialChars = false)
    {

        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        if ($specialChars) {
            $characters .= '!@#$%^&*()_=+[]{}<>';
        }

        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    public static function redirect($url)
    {

        header("Location: $url");
        die();
    }

    public static function validateNumber($num)
    {
        if (!$num) {
            return false;
        }

        $num = ltrim(trim($num), "+88");
        $number = '88' . ltrim($num, "88");

        $ext = ["88017", "88013", "88016", "88015", "88018", "88019", "88014"];
        if (is_numeric($number) && strlen($number) == 13) {
            if (in_array(substr($number, 0, 5), $ext)) {
                return $number;
            }
        }

        return false;
    }

    public static function validateDate($date): bool
    {
        if (!DateTime::createFromFormat("Y-m-d", $date)) {
            return false;
        }

        return true;
    }

    public static function validateString($string, $max = 255, $min = 1): bool
    {

        if (filter_var($string, FILTER_SANITIZE_STRING) != $string) {
            return false;
        }

        $string = trim($string);

        if (mb_strlen($string) > $max || strlen($string) < $min) {
            return false;
        }

        return true;
    }

    public static function getClientIP()
    {
        $ipaddress = '';
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
        } else if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else if (isset($_SERVER['HTTP_X_FORWARDED'])) {
            $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
        } else if (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
            $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
        } else if (isset($_SERVER['HTTP_FORWARDED'])) {
            $ipaddress = $_SERVER['HTTP_FORWARDED'];
        } else if (isset($_SERVER['REMOTE_ADDR'])) {
            $ipaddress = $_SERVER['REMOTE_ADDR'];
        } else {
            $ipaddress = 'UNKNOWN';
        }

        return $ipaddress;
    }

    public static function generateCaptcha()
    {

        $image = imagecreatetruecolor(150, 40) or die("Cannot Initialize new GD image stream");

        $background_color = imagecolorallocate($image, 255, 255, 255);

        $texture_color = imagecolorallocate($image, 219, 219, 219);

        $colors[] = imagecolorallocate($image, 150, 27, 0);

        $colors[] = imagecolorallocate($image, 8, 0, 125);

        $colors[] = imagecolorallocate($image, 0, 121, 4);

        $colors[] = imagecolorallocate($image, 0, 0, 0);

        $line_color = imagecolorallocate($image, 64, 64, 64);

        imagefilledrectangle($image, 0, 0, 200, 50, $background_color);

        //Lined Background
        /*for ($i = 0; $i < 3; $i++) {
        imageline($image, 0, rand() % 50, 200, rand() % 50, $line_color);
        }*/

        //Texture Background
        for ($i = 0; $i < 1000; $i++) {
            imagesetpixel($image, rand() % 200, rand() % 50, $texture_color);
        }

        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ123456789';

        $len = strlen($letters);

        $letter = $letters[rand(0, $len - 1)];

        $text_color = imagecolorallocate($image, 150, 27, 0);

        $word = "";

        //Print Captcha
        for ($i = 0; $i < 6; $i++) {
            $letter = $letters[rand(0, $len - 1)];
            imagestring($image, 7, 20 + ($i * 20), 13, $letter, $colors[array_rand($colors, 1)]);
            $word .= $letter;
        }

        $stream = fopen("php://memory", "w+");

        imagejpeg($image, $stream);

        rewind($stream);

        $image_data_base64 = base64_encode(stream_get_contents($stream));

        return ['code' => $word, 'image' => "data:image/jpeg;base64,$image_data_base64"];
    }

    public static function formatBytes($bytes, $precision = 2): string
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        // Uncomment one of the following alternatives
        $bytes /= 1024 ** $pow;
        // $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    public static function log(string $message, array $context = []): void
    {
        if (!defined('LOGS_DIR')) {
            return;
        }

        if (!is_dir(LOGS_DIR)) {
            @mkdir(LOGS_DIR, 0755, true);
        }

        $date = date('Y-m-d');
        $timestamp = date('Y-m-d H:i:s');
        $logFile = LOGS_DIR . "/app-$date.log";

        $entry = "[$timestamp] $message";
        if (!empty($context)) {
            $entry .= ' ' . json_encode($context);
        }
        $entry .= PHP_EOL;

        if (file_put_contents($logFile, $entry, FILE_APPEND) === false) {
            error_log("Failed to write log entry to $logFile");
        }
    }
}
