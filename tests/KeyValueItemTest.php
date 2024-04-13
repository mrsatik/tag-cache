<?php
declare(strict_types=1);

namespace mrsatik\TCacheTest;

use PHPUnit\Framework\TestCase;
use mrsatik\TCache\Item\KeyValue;
use mrsatik\TCache\Item\KeyValueInterface;
use mrsatik\TCache\Exception\InvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use DateTime;
use DateTimeInterface;
use DateInterval;

class KeyValueItemTest extends TestCase
{
    /**
     * @dataProvider keyItemDataProvider
     */
    public function testCreateItem(string $key, $value)
    {
        $keyObject = new KeyValue($key, $value);

        $this->assertEquals($keyObject->getKey(), $key);
        $this->assertEquals($keyObject->get(), $value);

        $this->assertInstanceOf(KeyValueInterface::class, $keyObject);
        $this->assertInstanceOf(CacheItemInterface::class, $keyObject);
    }

    /**
     * @dataProvider expiredAtDataProvider
     */
    public function testChangeExpired(string $key, $value, ?DateTimeInterface $time)
    {
        $keyObject = new KeyValue($key, $value);

        $this->assertEquals($keyObject->getKey(), $key);
        $this->assertEquals($keyObject->get(), $value);

        $newObj = $keyObject->expiresAt($time);
        if ($time !== null) {
            $this->assertEquals($newObj->getExpiredTime(), (int)$time->format('U'));
        } else {
            $this->assertEquals($newObj->getExpiredTime(), $time);
        }
    }

    /**
     * @dataProvider expiredAtExceptionDataProvider
     */
    public function testChangeExpiredException(string $key, $value, $time)
    {
        $keyObject = new KeyValue($key, $value);

        $this->expectException(InvalidArgumentException::class);
        $keyObject->expiresAt($time);
    }

    /**
     * @dataProvider expiredAfterDataProvider
     */
    public function testChangeExpiredAfter(string $key, $value, $time, $current)
    {
        $keyObject = new KeyValue($key, $value);

        $this->assertEquals($keyObject->getKey(), $key);
        $this->assertEquals($keyObject->get(), $value);

        $newObj = $keyObject->expiresAfter($time);
        $this->assertEqualsWithDelta($newObj->getExpiredTime(), $current, (float)1);
    }

    /**
     * @dataProvider expiredAfterExceptionDataProvider
     */
    public function testChangeExpiredAfterException(string $key, $value, $time)
    {
        $keyObject = new KeyValue($key, $value);

        $this->expectException(InvalidArgumentException::class);
        $keyObject->expiresAfter($time);
    }

    /**
     * @return array
     */
    public function keyItemDataProvider()
    {
        return [
            ['test1', 'test'],
            ['test2', ['test', 'test2']],
            ['test3', false],
            ['test4', (new KeyValue('1', '2'))],
        ];
    }

    /**
     * @return array
     */
    public function expiredAtDataProvider()
    {
        return [
            ['test1', 'test', null],
            ['test2', ['test', 'test2'], new DateTime(date('Y-m-d H:i:s', time() + 300))],
            ['test3', false, new DateTime(date('Y-m-d H:i:s', time() + 300))],
            ['test4', (new KeyValue('1', '2')), new DateTime(date('Y-m-d H:i:s', time() + 300))],
        ];
    }

    /**
     * @return array
     */
    public function expiredAtExceptionDataProvider()
    {
        return [
            ['test1', 'test', time()],
            ['test2', ['test', 'test2'], time()],
            ['test3', false, time()],
            ['test4', (new KeyValue('1', '2')), time()],
            ['test1', 'test', 0],
            ['test2', ['test', 'test2'], 0],
            ['test3', false, time() + 300],
            ['test4', (new KeyValue('1', '2')), (string)time()],
            ['test4', (new KeyValue('1', '2')), [time()]],
        ];
    }

    /**
     * @return array
     */
    public function expiredAfterDataProvider()
    {
        $invertDate = new DateInterval('P1D');
        $invertDate->invert = 1;

        return [
            ['test1', 'test', 1, time() + 1],
            ['test2', ['test', 'test2'], 2, time() + 2],
            ['test3', false, 0, null],
            ['test4', (new KeyValue('1', '2')), -1, null],
            ['test1', 'test', 1000, time() + 1000],
            ['test2', ['test', 'test2'], new DateInterval('P1D'), time() + 86400],
            ['test3', false, $invertDate, null],
            ['test4', (new KeyValue('1', '2')), new DateInterval('PT1S'), time() + 1],
        ];
    }

    /**
     * @return array
     */
    public function expiredAfterExceptionDataProvider()
    {
        return [
            ['test1', 'test', 'test'],
            ['test2', ['test', 'test2'], []],
            ['test3', false, new DateTime(date('Y-m-d H:i:s', time() + 300))],
        ];
    }
}