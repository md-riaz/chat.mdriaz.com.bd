<?php 
namespace Framework\Core;
use DateTimeZone;
use DateTime;

class UTC
{
    protected $dateTime;

    public static function toLocal($dateTimeString, $localZone)
    {
        // Create a new DateTime object in UTC timezone
        $dateTime = new DateTime($dateTimeString, new DateTimeZone('UTC'));

        // Convert to America/New_York timezone
        $dateTime->setTimezone(new DateTimeZone($localZone));

        return $dateTime;
    }

    public static function fromLocal($dateTimeString, $localZone)
    {
        // Create a new DateTime object in America/New_York timezone
        $dateTime = new DateTime($dateTimeString, new DateTimeZone($localZone));

        // Convert to UTC timezone
        $dateTime->setTimezone(new DateTimeZone('UTC'));

        return $dateTime;
    }

    public static function localNow($localZone)
    {
        return new DateTime('now', new DateTimeZone($localZone));
    }

    public static function now()
    {
        return new DateTime('now');
    }

    public function __call($method, $args)
    {
        // Forward method calls to the DateTime object
        return call_user_func_array([$this->dateTime, $method], $args);
    }
}