<?php

namespace Click2mice\JiraClient;

use Click2mice\JiraClient\Clients\Common;

class Factory
{
    private static $versionsMap = [
        '4.4.4'  => 'v4_4_4',
        '5.1.8'  => 'v5_1_8',
        '5.2.11' => 'v5_2_11',
        '6.3.8'  => 'v6_3_8',
    ];

    /**
     * @param $version
     * @param $url
     * @param $username
     * @param $password
     * @return Common
     * @throws \InvalidArgumentException
     */
    public static function getInstance($version, $url, $username, $password)
    {
        if (isset(self::$versionsMap[$version])) {
            $className = '\\Click2mice\\JiraClient\\Clients\\' . self::$versionsMap[$version] . '\\Client';
            return new $className($url, $username, $password);
        }
        else {
            throw new \InvalidArgumentException('Version is not supported');
        }
    }
}