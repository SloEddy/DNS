<?php

/*
 * This file is part of Badcow DNS Library.
 *
 * (c) Samuel Williams <sam@badcow.co>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Badcow\DNS\Tests\Parser;

use Badcow\DNS\AlignedBuilder;
use Badcow\DNS\Classes;
use Badcow\DNS\Parser\ParseException;
use Badcow\DNS\Parser\Parser;
use Badcow\DNS\Parser\RDataTypes;
use Badcow\DNS\Rdata\A;
use Badcow\DNS\Rdata\AAAA;
use Badcow\DNS\Rdata\APL;
use Badcow\DNS\Rdata\CAA;
use Badcow\DNS\Rdata\CNAME;
use Badcow\DNS\Rdata\Factory;
use Badcow\DNS\Rdata\TXT;
use Badcow\DNS\ResourceRecord;
use Badcow\DNS\Zone;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    /**
     * Build a test zone.
     *
     * @return Zone
     */
    private function getTestZone(): Zone
    {
        $zone = new Zone('example.com.');
        $zone->setDefaultTtl(3600);

        $soa = new ResourceRecord();
        $soa->setName('@');
        $soa->setRdata(Factory::Soa(
            'example.com.',
            'post.example.com.',
            2014110501,
            3600,
            14400,
            604800,
            3600
        ));

        $ns1 = new ResourceRecord();
        $ns1->setName('@');
        $ns1->setRdata(Factory::Ns('ns1.nameserver.com.'));

        $ns2 = new ResourceRecord();
        $ns2->setName('@');
        $ns2->setRdata(Factory::Ns('ns2.nameserver.com.'));

        $a = new ResourceRecord();
        $a->setName('sub.domain');
        $a->setRdata(Factory::A('192.168.1.42'));
        $a->setComment('This is a local ip.');

        $a6 = new ResourceRecord();
        $a6->setName('ipv6.domain');
        $a6->setRdata(Factory::Aaaa('::1'));
        $a6->setComment('This is an IPv6 domain.');

        $mx1 = new ResourceRecord();
        $mx1->setName('@');
        $mx1->setRdata(Factory::Mx(10, 'mail-gw1.example.net.'));

        $mx2 = new ResourceRecord();
        $mx2->setName('@');
        $mx2->setRdata(Factory::Mx(20, 'mail-gw2.example.net.'));

        $mx3 = new ResourceRecord();
        $mx3->setName('@');
        $mx3->setRdata(Factory::Mx(30, 'mail-gw3.example.net.'));

        $dname = new ResourceRecord('hq', Factory::Dname('syd.example.com.'));

        $loc = new ResourceRecord();
        $loc->setName('canberra');
        $loc->setRdata(Factory::Loc(
            -35.3075,   //Lat
            149.1244,   //Lon
            500,        //Alt
            20.12,      //Size
            200.3,      //HP
            300.1       //VP
        ));
        $loc->setComment('This is Canberra');

        $zone->addResourceRecord($soa);
        $zone->addResourceRecord($ns1);
        $zone->addResourceRecord($ns2);
        $zone->addResourceRecord($a);
        $zone->addResourceRecord($a6);
        $zone->addResourceRecord($dname);
        $zone->addResourceRecord($mx1);
        $zone->addResourceRecord($mx2);
        $zone->addResourceRecord($mx3);
        $zone->addResourceRecord($loc);

        return $zone;
    }

    /**
     * Parser creates valid dns object.
     *
     * @throws ParseException
     */
    public function testParserCreatesValidDnsObject(): void
    {
        $zoneBuilder = new AlignedBuilder();
        $zone = $zoneBuilder->build($this->getTestZone());

        $expectation = $this->getTestZone();
        foreach ($expectation->getResourceRecords() as $rr) {
            $rr->setComment('');
        }

        $this->assertEquals($expectation, Parser::parse('example.com.', $zone));
    }

    /**
     * Parser ignores control entries other than TTL.
     *
     * @throws ParseException|\Exception
     */
    public function testParserIgnoresControlEntriesOtherThanTtl(): void
    {
        $file = NormaliserTest::readFile(__DIR__.'/Resources/testCollapseMultilines_sample.txt');
        $zone = Parser::parse('example.com.', $file);

        $this->assertEquals('example.com.', $zone->getName());
        $this->assertEquals('::1', self::findRecord('ipv6.domain', $zone)[0]->getRdata()->getAddress());
        $this->assertEquals(1337, $zone->getDefaultTtl());
    }

    /**
     * Parser can handle convoluted zone record.
     *
     * @throws ParseException|\Exception
     */
    public function testParserCanHandleConvolutedZoneRecord(): void
    {
        $file = NormaliserTest::readFile(__DIR__.'/Resources/testConvolutedZone_sample.txt');
        $zone = Parser::parse('example.com.', $file);
        $this->assertEquals(3600, $zone->getDefaultTtl());
        $this->assertCount(28, $zone->getResourceRecords());

        $txt = new ResourceRecord(
            'testtxt',
            Factory::txt('v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBg'.
                'QDZKI3U+9acu3NfEy0NJHIPydxnPLPpnAJ7k2JdrsLqAK1uouMudHI20pgE8RMldB/TeW'.
                'KXYoRidcGCZWXleUzldDTwZAMDQNpdH1uuxym0VhoZpPbI1RXwpgHRTbCk49VqlC'),
            600,
            Classes::INTERNET
        );

        $txt2 = 'Some text another Some text';

        $this->assertEquals($txt, self::findRecord($txt->getName(), $zone)[0]);
        $this->assertEquals($txt2, self::findRecord('test', $zone)[0]->getRdata()->getText());
        $this->assertCount(1, self::findRecord('xn----7sbfndkfpirgcajeli2a4pnc.xn----7sbbfcqfo2cfcagacemif0ap5q', $zone));
        $this->assertCount(4, self::findRecord('testmx', $zone));
    }

    /**
     * @throws ParseException
     */
    public function testCanHandlePolymorphicRdata(): void
    {
        RDataTypes::$names[] = 'XX'; //Trick parser into using polymorphic type.

        $string = 'example.com. 7200 IN XX 2001:acad::1337; This is invalid.';
        $zone = Parser::parse('example.com.', $string);
        $rr = $zone->getResourceRecords()[0];

        $rdata = $rr->getRdata();

        $this->assertNotNull($rdata);

        if (null === $rdata) {
            return;
        }

        $this->assertEquals('XX', $rdata->getType());
        $this->assertEquals('2001:acad::1337', $rdata->output());
    }

    /**
     * @throws ParseException|\Exception
     */
    public function testParserCanHandleAplRecords(): void
    {
        $file = NormaliserTest::readFile(__DIR__.'/Resources/testCollapseMultilines_sample.txt');
        $zone = Parser::parse('example.com.', $file);

        /** @var APL $apl */
        $apl = self::findRecord('multicast', $zone)[0]->getRdata();
        $this->assertCount(2, $apl->getIncludedAddressRanges());
        $this->assertCount(2, $apl->getExcludedAddressRanges());

        $this->assertEquals('192.168.0.0/23', (string) $apl->getIncludedAddressRanges()[0]);
        $this->assertEquals('2001:acad:1::8/128', (string) $apl->getExcludedAddressRanges()[1]);
    }

    /**
     * @throws ParseException
     */
    public function testParserCanHandleCaaRecords(): void
    {
        $text = <<<'TXT'
$ORIGIN EXAMPLE.COM.
$TTL 3600
@ 10800 IN CAA 0 issue "letsencrypt.org"
TXT;

        $zone = Parser::parse('example.com.', $text);
        $this->assertCount(1, $zone);
        /** @var CAA $caa */
        $caa = $zone->getResourceRecords()[0]->getRdata();

        $this->assertEquals('CAA', $caa->getType());
        $this->assertEquals(0, $caa->getFlag());
        $this->assertEquals('issue', $caa->getTag());
        $this->assertEquals('letsencrypt.org', $caa->getValue());
    }

    /**
     * @throws ParseException
     */
    public function testParserCanHandleSshfpRecords(): void
    {
        $txt = 'host.example. IN SSHFP 2 1 123456789abcdef67890123456789abcdef67890';
        $zone = Parser::parse('example.', $txt);

        $rrs = self::findRecord('host.example.', $zone, 'SSHFP');
        $sshfp = $rrs[0]->getRdata();

        $this->assertEquals(2, $sshfp->getAlgorithm());
        $this->assertEquals(1, $sshfp->getFingerprintType());
        $this->assertEquals('123456789abcdef67890123456789abcdef67890', $sshfp->getFingerprint());
    }

    /**
     * @throws ParseException
     */
    public function testParserCanHandleUriRecords(): void
    {
        $txt = '   _ftp._tcp    IN URI 10 1 "ftp://ftp1.example.com/public data"';
        $zone = Parser::parse('example.com.', $txt);

        $rrs = self::findRecord('_ftp._tcp', $zone, 'URI');
        $uri = $rrs[0]->getRdata();

        $this->assertEquals(10, $uri->getPriority());
        $this->assertEquals(1, $uri->getWeight());
        $this->assertEquals('ftp://ftp1.example.com/public%20data', $uri->getTarget());
    }

    /**
     * @throws ParseException
     */
    public function testMalformedAplRecordThrowsException1(): void
    {
        $zone = 'multicast 3600 IN APL 3:192.168.0.64/30';

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('"3:192.168.0.64/30" is not a valid IP range.');

        Parser::parse('example.com.', $zone);
    }

    /**
     * @throws ParseException
     */
    public function testUnknownRdataTypeThrowsException(): void
    {
        $zone = 'resource 3600 IN A6 f080:3024:a::1';

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Could not parse entry "resource 3600 IN A6 f080:3024:a::1".');

        Parser::parse('acme.com.', $zone);
    }

    /**
     * @throws ParseException
     */
    public function testMalformedAplRecordThrowsException2(): void
    {
        $zone = 'multicast 3600 IN APL !1-192.168.0.64/30';

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('"!1-192.168.0.64/30" is not a valid IP range.');

        Parser::parse('example.com.', $zone);
    }

    /**
     * @throws \Exception|ParseException
     */
    public function testAmbiguousRecordsParse(): void
    {
        $file = NormaliserTest::readFile(__DIR__.'/Resources/ambiguous.acme.org.txt');
        $zone = Parser::parse('ambiguous.acme.org.', $file);
        $mxRecords = self::findRecord('mx', $zone);
        $a4Records = self::findRecord('aaaa', $zone);

        $this->assertCount(3, $mxRecords);
        $this->assertCount(2, $a4Records);
        foreach ($mxRecords as $rr) {
            switch ($rr->getType()) {
                case A::TYPE:
                    $this->assertEquals(900, $rr->getTtl());
                    $this->assertEquals('200.100.50.35', $rr->getRdata()->getAddress());
                    break;
                case CNAME::TYPE:
                    $this->assertEquals(3600, $rr->getTtl());
                    $this->assertEquals('aaaa', $rr->getRdata()->getTarget());
                    break;
                case TXT::TYPE:
                    $this->assertEquals(3600, $rr->getTtl());
                    $this->assertEquals('Mail Exchange IPv6 Address', $rr->getRdata()->getText());
                    break;
            }
        }

        foreach ($a4Records as $rr) {
            switch ($rr->getType()) {
                case AAAA::TYPE:
                    $this->assertEquals(900, $rr->getTtl());
                    $this->assertEquals('2001:acdc:5889::35', $rr->getRdata()->getAddress());
                    break;
                case TXT::TYPE:
                    $this->assertEquals(3600, $rr->getTtl());
                    $this->assertEquals('This name is silly.', $rr->getRdata()->getText());
                    break;
            }
        }
    }

    /**
     * @throws ParseException
     */
    public function testAmbiguousRecord(): void
    {
        $record = 'mx cname aaaa';
        $zone = Parser::parse('acme.com.', $record);
        $mx = $zone->getResourceRecords()[0];

        $this->assertEquals(CNAME::TYPE, $mx->getType());
        $this->assertEquals('mx', $mx->getName());
        $this->assertEquals('aaaa', $mx->getRdata()->getTarget());
    }

    /**
     * Find all records in a Zone named $name.
     *
     * @param string|null $name
     * @param Zone        $zone
     * @param string|null $type
     *
     * @return ResourceRecord[]
     */
    public static function findRecord(?string $name, Zone $zone, ?string $type = 'ANY'): array
    {
        $records = [];

        foreach ($zone->getResourceRecords() as $resourceRecord) {
            if ($name === $resourceRecord->getName() && ('ANY' === $type || $type === $resourceRecord->getType())) {
                $records[] = $resourceRecord;
            }
        }

        return $records;
    }
}
