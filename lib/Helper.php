<?php

class Helper
{
    /**
     * Получает цифровую часть версии из ее полного имени
     * Например: 1.5.1 из order-api-1.5.1
     * Например: 13083 из 13083
     * @param $versionName
     * @return string
     */
    public static function getNumericVersion( $versionName )
    {
        $pos = strrpos( $versionName, '-' );
        return $pos === false ? $versionName : substr( $versionName, $pos + 1 );
    }

    /**
     * Проверяет зависимости в composer.json и возвращает либо true, если все ок, либо название пакета, у которого
     * указана недопустимая зависимость
     * @param string $filePath
     * @return string|bool
     */
    public static function checkComposerDependencies( $filePath )
    {
        if (file_exists($filePath)) {
            $json = json_decode( file_get_contents( $filePath ), true );
            if(!empty($json['require'])) {
                foreach ( $json['require'] as $package => $version ) {
                    if ( strpos( $package, 'wikimart' ) === 0 ) {
                        if ( strpos( $version, 'dev' ) !== false ) {
                            return $package . ' (' . $version . ')';
                        }
                    }
                }
            }
        }
        return true;
    }
}