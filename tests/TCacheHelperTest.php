<?php
declare(strict_types=1);

namespace mrsatik\TCacheTest;

use PHPUnit\Framework\TestCase;
use mrsatik\TCache\TCacheHelper;
use ReflectionObject;
use mrsatik\TCache\TCacheHelperInterface;

class TCacheHelperTest extends TestCase
{
    public function testCheckTagsLength()
    {
        $arrayKeys = [];
        $result = TCacheHelper::checkTagsLength($arrayKeys);

        $this->assertTrue($result);

        $arrayKeys = [
            $this->randomString(1),
            $this->randomString(2),
            $this->randomString(10),
        ];
        $result = TCacheHelper::checkTagsLength($arrayKeys);
        $this->assertTrue($result);

        $arrayKeys = [
            $this->randomString(1),
            $this->randomString(0),
            $this->randomString(10),
        ];
        $result = TCacheHelper::checkTagsLength($arrayKeys);
        $this->assertFalse($result);

        $arrayKeys = [
            $this->randomString(1),
            $this->randomString(231),
            $this->randomString(10),
        ];
        $result = TCacheHelper::checkTagsLength($arrayKeys);
        $this->assertFalse($result);

        $arrayKeys = [
            $this->randomString(1),
            $this->randomString(0),
            $this->randomString(232),
        ];
        $result = TCacheHelper::checkTagsLength($arrayKeys);
        $this->assertFalse($result);

        $arrayKeys = [
            $this->randomString(232),
            $this->randomString(0),
        ];
        $result = TCacheHelper::checkTagsLength($arrayKeys);
        $this->assertFalse($result);

        $arrayKeys = [
            $this->randomString(0),
        ];
        $result = TCacheHelper::checkTagsLength($arrayKeys);
        $this->assertFalse($result);

        $arrayKeys = [
            $this->randomString(230),
        ];
        $result = TCacheHelper::checkTagsLength($arrayKeys);
        $this->assertFalse($result);
    }

    public function testTimeNormalazer()
    {
        $time = TCacheHelper::normalizeTime((float)microtime());
        $this->assertTrue(is_numeric($time));
        $this->assertTrue(is_float($time));
    }

    public function testPrefix()
    {
        $obj = new TCacheHelper();
        $this->assertInstanceOf(TCacheHelperInterface::class, $obj);
        $reclectionObject = new ReflectionObject($obj);
        $constTag = $reclectionObject->getConstant('TAG_PREFIX');
        $constVer = $reclectionObject->getConstant('VERSION_PREFIX');

        $tags = '';
        $var = TCacheHelper::prefixTags($tags);
        $this->assertEquals($constTag, $var);

        $tags = '1234';
        $var = TCacheHelper::prefixTags($tags);
        $this->assertEquals($constTag . $tags, $var);

        $tags = [
            '',
            '35',
            'test',
        ];

        $vars = TCacheHelper::prefixTags($tags);
        foreach ($vars as $k => $var) {
            $this->assertEquals($constTag . $tags[$k], $var);
        }

        $key = '';
        $var = TCacheHelper::prefixVerKeys($key);
        $this->assertEquals($constVer, $var);

        $key = 'key_1234';
        $var = TCacheHelper::prefixVerKeys($key);
        $this->assertEquals($constVer . $key, $var);

        $keys = [
            '',
            'key_35',
            'key_test',
        ];

        $vars = TCacheHelper::prefixVerKeys($keys);
        foreach ($vars as $k => $var) {
            $this->assertEquals($constVer . $keys[$k], $var);
        }
    }

    public function testUnprefix()
    {
        $obj = new TCacheHelper();
        $this->assertInstanceOf(TCacheHelperInterface::class, $obj);
        $reclectionObject = new ReflectionObject($obj);
        $constTag = $reclectionObject->getConstant('TAG_PREFIX');

        $tags = '';
        $var = TCacheHelper::prefixTags($tags);
        $this->assertEquals($constTag, $var);
        $unprefixVar = TCacheHelper::unprefixTags($tags);
        $this->assertEquals($tags, $unprefixVar);


        $tags = '1234';
        $var = TCacheHelper::prefixTags($tags);
        $this->assertEquals($constTag . $tags, $var);
        $unprefixVar = TCacheHelper::unprefixTags($var);
        $this->assertEquals($tags, $unprefixVar);

        $tags = [
            '',
            '35',
            'test',
        ];

        $vars = TCacheHelper::prefixTags($tags);
        foreach ($vars as $k => $var) {
            $this->assertEquals($constTag . $tags[$k], $var);
        }

        $unprefixVars = TCacheHelper::unprefixTags($vars);
        foreach ($unprefixVars as $k => $var) {
            $this->assertEquals($tags[$k], $var);
        }
    }

    private function randomString(int $length)
    {
        if((int)$length < 0 ){
            $length = 1;
        } elseif ($length === 0) {
            return '';
        }

        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($length));
        }

        if (function_exists('mcrypt_create_iv')) {
            return bin2hex(mcrypt_create_iv($length, MCRYPT_DEV_URANDOM));
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes($length));
        }
    }
}
