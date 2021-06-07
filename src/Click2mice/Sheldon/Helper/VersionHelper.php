<?php

namespace Click2mice\Sheldon\Helper;

use Symfony\Component\Console\Helper\Helper;

class VersionHelper extends Helper
{
    const POS_MAJOR = 0;
    const POS_MINOR = 1;
    const POS_PATCH = 2;

    /**
     * Returns the canonical name of this helper.
     *
     * @return string The canonical name
     *
     * @api
     */
    public function getName()
    {
        return 'version';
    }

    /**
     * Увеличивает указанный разряд в версии
     *
     * @param string $version
     * @param int    $position
     *
     * @return string
     */
    public function incrementVersion($version, $position)
    {
        $this->assertPosition($position);
        $normalized = $this->normalizeVersion($version);

        $parts = explode('.', $normalized);
        $parts[$position]++;

        foreach( $this->getAvailablePositions() as $availablePosition ) {
            if ( $availablePosition > $position ) {
                $parts[$availablePosition] = 0;
            }
        }

        return implode('.', $parts);
    }

    /**
     * @param string $version
     *
     * @return string
     * @throws \Exception
     */
    public function normalizeVersion($version)
    {
        if (preg_match('{^v?(\d{1,3})(\.\d+)?(\.\d+)?(\.\d+)?$}i', $version, $matches)) {
            $version =
                $matches[1] .
                (isset($matches[2]) ? $matches[2] : '.0') .
                (isset($matches[3]) ? $matches[3] : '.0');

            return $version;
        } else {
            throw new \InvalidArgumentException('Формат версии: MAJOR.MINOR.PATCH');
        }
    }

    /**
     * @param int $position
     *
     * @throws \InvalidArgumentException
     */
    protected function assertPosition($position)
    {
        if (in_array($position, $this->getAvailablePositions()) == false) {
            throw new \InvalidArgumentException("unknown position: {$position}");
        }
    }

    /**
     * @return array
     */
    protected function getAvailablePositions()
    {
        return array(self::POS_MAJOR, self::POS_MINOR, self::POS_PATCH);
    }

}