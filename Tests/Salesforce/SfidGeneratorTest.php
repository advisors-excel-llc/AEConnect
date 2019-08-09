<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 4/18/19
 * Time: 11:57 AM
 */

namespace AE\ConnectBundle\Tests\Salesforce;

use PHPUnit\Framework\TestCase;

class SfidGeneratorTest extends TestCase
{
    public function testConversion()
    {
        $this->assertEquals('01tf40000024Na9AAE', SfidGenerator::convertFifteenToEighteen('01tf40000024Na9'));
        $this->assertEquals('01t0x000002XP9yAAG', SfidGenerator::convertFifteenToEighteen('01t0x000002XP9y'));
        $this->assertEquals('a1p0x000000h3M9AAI', SfidGenerator::convertFifteenToEighteen('a1p0x000000h3M9'));
    }

    public function testFifteen()
    {
        $rand = SfidGenerator::generate(false);
        $this->assertRegExp('/^[a-zA-Z0-9]{15}$/', $rand);
    }

    public function testEighteen()
    {
        $rand = SfidGenerator::generate();
        $this->assertRegExp('/^[a-zA-Z0-9]{15}[A-Z0-5]{3}$/', $rand);
    }

    public function testUnique()
    {
        $sfid1 = SfidGenerator::generate();
        $sfid2 = SfidGenerator::generate();

        $this->assertNotEquals($sfid1, $sfid2);
    }
}
