<?php

/**
 * Расширение стандартного класса для работы с Memchache.
 * Реализован как синглтон.
 *
 */
class MemcacheException extends Exception {};

class MCache extends Memcache
{
	protected static $_instance;
    protected static $_config;

	/**
	 * Статичный метод синглтона
	 *
	 * @return MCache
	 */
	public static function getInstance()
	{
		if (!is_object(static::$_instance))
		{
			static::$_instance = new static;
		}

		return static::$_instance;
	}

    /**
     * Установка конфига
     *
     * @param $config
     */
    public static function setConfig($config)
    {
        self::$_config = $config;
    }

	/**
	 * Конструктор для memcached
	 * Порследовательно подключает пул серверов
	 */
	protected function __construct() {
		$memcaches = self::$_config['memcaches'];
		
		foreach($memcaches as $memcache)
		{
			/**
			 * PERSISTENT = FALSE
			 * Т.к. ключи ставятся, а из другого мемкеша только с n-ой попытки достаются.
			 */
			$this->addServer($memcache['host'], $memcache['port'], false);
		}
	}
}
