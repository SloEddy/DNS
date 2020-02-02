<?php

declare(strict_types=1);

/*
 * This file is part of Badcow DNS Library.
 *
 * (c) Samuel Williams <sam@badcow.co>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Badcow\DNS\Rdata;

use Badcow\DNS\Parser\Tokens;

/**
 * Class LocRdata.
 *
 * Mechanism to allow the DNS to carry location
 * information about hosts, networks, and subnets.
 *
 * @see http://tools.ietf.org/html/rfc1876
 */
class LOC implements RdataInterface
{
    use RdataTrait;

    const TYPE = 'LOC';
    const TYPE_CODE = 29;
    const LATITUDE = 'LATITUDE';
    const LONGITUDE = 'LONGITUDE';
    const FORMAT_DECIMAL = 'DECIMAL';
    const FORMAT_DMS = 'DMS';

    /**
     * @var float|null
     */
    private $latitude;

    /**
     * @var float|null
     */
    private $longitude;

    /**
     * @var float
     */
    private $altitude = 0.0;

    /**
     * @var float
     */
    private $size = 1.0;

    /**
     * @var float
     */
    private $horizontalPrecision = 10000.0;

    /**
     * @var float
     */
    private $verticalPrecision = 10.0;

    /**
     * @param float $latitude
     */
    public function setLatitude(float $latitude): void
    {
        $this->latitude = (float) $latitude;
    }

    /**
     * @param string $format
     *
     * @return float|string|null
     */
    public function getLatitude(string $format = self::FORMAT_DECIMAL)
    {
        if (self::FORMAT_DMS === $format) {
            return $this->toDms($this->latitude ?? 0, self::LATITUDE);
        }

        return $this->latitude;
    }

    /**
     * @param float $longitude
     */
    public function setLongitude(float $longitude): void
    {
        $this->longitude = (float) $longitude;
    }

    /**
     * @param string $format
     *
     * @return float|string|null
     */
    public function getLongitude(string $format = self::FORMAT_DECIMAL)
    {
        if (self::FORMAT_DMS === $format) {
            return $this->toDms($this->longitude ?? 0, self::LONGITUDE);
        }

        return $this->longitude;
    }

    /**
     * @param float $altitude
     *
     * @throws \OutOfRangeException
     */
    public function setAltitude(float $altitude): void
    {
        if ($altitude < -100000.00 || $altitude > 42849672.95) {
            throw new \OutOfRangeException('The altitude must be on [-100000.00, 42849672.95].');
        }

        $this->altitude = (float) $altitude;
    }

    /**
     * @return float
     */
    public function getAltitude(): float
    {
        return $this->altitude;
    }

    /**
     * @param float $horizontalPrecision
     *
     * @throws \OutOfRangeException
     */
    public function setHorizontalPrecision(float $horizontalPrecision): void
    {
        if ($horizontalPrecision < 0 || $horizontalPrecision > 9e9) {
            throw new \OutOfRangeException('The horizontal precision must be on [0, 9e9].');
        }

        $this->horizontalPrecision = (float) $horizontalPrecision;
    }

    /**
     * @return float
     */
    public function getHorizontalPrecision(): float
    {
        return $this->horizontalPrecision;
    }

    /**
     * @param float $size
     *
     * @throws \OutOfRangeException
     */
    public function setSize(float $size): void
    {
        if ($size < 0 || $size > 9e9) {
            throw new \OutOfRangeException('The size must be on [0, 9e9].');
        }

        $this->size = (float) $size;
    }

    /**
     * @return float
     */
    public function getSize(): float
    {
        return $this->size;
    }

    /**
     * @param float $verticalPrecision
     *
     * @throws \OutOfRangeException
     */
    public function setVerticalPrecision(float $verticalPrecision): void
    {
        if ($verticalPrecision < 0 || $verticalPrecision > 9e9) {
            throw new \OutOfRangeException('The vertical precision must be on [0, 9e9].');
        }

        $this->verticalPrecision = $verticalPrecision;
    }

    /**
     * @return float
     */
    public function getVerticalPrecision(): float
    {
        return $this->verticalPrecision;
    }

    /**
     * {@inheritdoc}
     */
    public function toText(): string
    {
        return sprintf(
                '%s %s %.2fm %.2fm %.2fm %.2fm',
                $this->getLatitude(self::FORMAT_DMS),
                $this->getLongitude(self::FORMAT_DMS),
                $this->altitude,
                $this->size,
                $this->horizontalPrecision,
                $this->verticalPrecision
        );
    }

    /**
     * Determine the degree minute seconds value from decimal.
     *
     * @param float  $decimal
     * @param string $axis
     *
     * @return string
     */
    private function toDms(float $decimal, string $axis = self::LATITUDE): string
    {
        $d = (int) floor(abs($decimal));
        $m = (int) floor((abs($decimal) - $d) * 60);
        $s = ((abs($decimal) - $d) * 60 - $m) * 60;
        if (self::LATITUDE === $axis) {
            $h = ($decimal < 0) ? 'S' : 'N';
        } else {
            $h = ($decimal < 0) ? 'W' : 'E';
        }

        return sprintf('%d %d %.3f %s', $d, $m, $s, $h);
    }

    /**
     * {@inheritdoc}
     */
    public function toWire(): string
    {
        return pack('CCCClll',
            0,
            self::numberToExponentValue($this->size),
            self::numberToExponentValue($this->horizontalPrecision),
            self::numberToExponentValue($this->verticalPrecision),
            (int) floor($this->latitude * 3600000),
            (int) floor($this->longitude * 3600000),
            (int) floor($this->altitude)
        );
    }

    private static function numberToExponentValue(float $num): int
    {
        $exponent = (int) floor(log($num, 10));
        $base = (int) ceil($num / (10 ** $exponent));

        return $base * 16 + $exponent;
    }

    private static function exponentValueToNumber(int $val): float
    {
        $base = ($val & 0b11110000) / 16;
        $exponent = ($val & 0b00001111);

        return $base * 10 ** $exponent;
    }

    /**
     * Transform a DMS string to a decimal representation. Used for LOC records.
     *
     * @param int    $deg        Degrees
     * @param int    $min        Minutes
     * @param float  $sec        Seconds
     * @param string $hemisphere Either 'N', 'S', 'E', or 'W'
     *
     * @return float
     */
    public static function dmsToDecimal(int $deg, int $min, float $sec, string $hemisphere): float
    {
        $multiplier = ('S' === $hemisphere || 'W' === $hemisphere) ? -1 : 1;

        return $multiplier * ($deg + ($min / 60) + ($sec / 3600));
    }

    /**
     * {@inheritdoc}
     *
     * @return LOC
     */
    public static function fromText(string $text): RdataInterface
    {
        $rdata = explode(Tokens::SPACE, $text);
        $lat = self::dmsToDecimal((int) array_shift($rdata), (int) array_shift($rdata), (float) array_shift($rdata), (string) array_shift($rdata));
        $lon = self::dmsToDecimal((int) array_shift($rdata), (int) array_shift($rdata), (float) array_shift($rdata), (string) array_shift($rdata));

        return Factory::LOC(
            $lat,
            $lon,
            (float) array_shift($rdata),
            (float) array_shift($rdata),
            (float) array_shift($rdata),
            (float) array_shift($rdata)
        );
    }

    /**
     * {@inheritdoc}
     *
     * @return LOC
     */
    public static function fromWire(string $rdata, int &$offset = 0, ?int $rdLength = null): RdataInterface
    {
        $values = unpack('C<version>/C<size>/C<hp>/C<vp>/l<lat>/l<lon>/l<alt>', $rdata, $offset);
        $offset += 16;
        $loc = new LOC();

        $loc->setSize(self::exponentValueToNumber($values['<size>']));
        $loc->setHorizontalPrecision(self::exponentValueToNumber($values['<hp>']));
        $loc->setVerticalPrecision(self::exponentValueToNumber($values['<vp>']));
        $loc->setLatitude($values['<lat>'] / 3600000);
        $loc->setLongitude($values['<lon>'] / 3600000);
        $loc->setAltitude($values['<alt>']);

        return $loc;
    }
}
