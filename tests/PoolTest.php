<?php
declare(strict_types=1);

namespace mrsatik\TCacheTest;

use PHPUnit\Framework\TestCase;
use mrsatik\TCache\Pool;
use mrsatik\NullCache\PoolInterface;
use mrsatik\TCache\Exception\EmptyValueServerList;
use mrsatik\TCache\Exception\EmptyTagServerList;
use mrsatik\TCache\Exception\InvalidArgumentException;
use mrsatik\TCache\Item\KeyValue;
use mrsatik\TCache\Exception\SimpleCacheException;
use mrsatik\TCache\TCacheHelper;

class PoolEmptyValue extends Pool
{
    protected const SETTING_VALUE_NAME = 'memcacheemptyvalue.servers.value';
}

class PoolEmptyTag extends Pool
{
    protected const SETTING_TAG_NAME = 'memcacheemptytag.servers.tags';
}

class PoolTest extends TestCase
{
    public function setUp(): void
    {
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();

        $reflection = new \ReflectionClass($instance);
        $property = $reflection->getProperty('memcacheConnection');
        $property->setAccessible(true);
        $property->setValue($instance, null);
    }

    public function testGetInstance()
    {
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $this->assertInstanceOf(PoolInterface::class, $instance);
    }

    public function testExceptionEmptyValue()
    {
        $this->expectException(EmptyValueServerList::class);
        /** @var PoolInterface $instance */
        $instance = PoolEmptyValue::getInstance();
    }

    public function testExceptionEmptyTag()
    {
        $this->expectException(EmptyTagServerList::class);
        /** @var PoolInterface $instance */
        $instance = PoolEmptyTag::getInstance();
    }

    public function testExceptionEmptyKey()
    {
        $this->expectException(InvalidArgumentException::class);
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $instance->getItem('');
    }

    public function testExceptionWrongKey()
    {
        $this->expectException(InvalidArgumentException::class);
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $reflection = new \ReflectionClass($instance);
        $wrongKey = $reflection->getConstant('TEST_KEY_NAME');

        $instance->getItem($wrongKey);
    }

    public function testWrongKeySave()
    {
        $this->expectException(InvalidArgumentException::class);
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $reflection = new \ReflectionClass($instance);
        $wrongKey = $reflection->getConstant('TEST_KEY_NAME');

        $cacheItemObject = new KeyValue($wrongKey, $wrongKey);
        $instance->save($cacheItemObject);
    }

    public function testGetKeyOrLock()
    {
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $key = md5(microtime());
        $data = $instance->getItem($key);
        $this->assertNull($data);

        $dataCasLock = $instance->getItem($key);
        $this->assertNull($dataCasLock);
    }

    public function testHasKey()
    {
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $key = md5(microtime());
        $data = $instance->hasItem($key);
        $this->assertFalse($data);
    }

    public function testKeySave()
    {
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $key = md5(microtime());

        $cacheItemObject = new KeyValue($key, $key);
        $result = $instance->save($cacheItemObject);
        $this->assertFalse($result);

        $key = md5(microtime());
        if ($instance->getItem($key) === null) {
            $cacheItemObject = new KeyValue($key, $key . '_key');
            $result = $instance->save($cacheItemObject);
            $this->assertTrue($result);
        } else {
            $exception = true;
            $this->assertFalse($exception);
        }

        $resultGet = $instance->getItem($key);
        $this->assertEquals($key . '_key', $resultGet->get());
        $this->assertEquals($key, $resultGet->getKey());
    }

    /**
     * @dataProvider getTimeToRebuildDataProvider
     */
    public function testGetTimeToRebuild(?int $time, $isDefault = false, $isNegatove = false)
    {
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $reflection = new \ReflectionClass($instance);
        $defaultTimeToRebuild = $reflection->getConstant('DEFAULT_TIME_TO_REBUILD');

        if ($isDefault === true) {
            $timeToRebuild = $instance->getTimeToRebuild();
            $this->assertEquals($timeToRebuild, $defaultTimeToRebuild);
        } elseif ($isDefault === false && $isNegatove === false) {
            $instance->setTimeToRebuild($time);
            $timeToRebuild = $instance->getTimeToRebuild();
            $this->assertNotEquals($timeToRebuild, $defaultTimeToRebuild);
            $this->assertEquals($timeToRebuild, $time);
        } else {
            $instance->setTimeToRebuild($time);
            $timeToRebuild = $instance->getTimeToRebuild();
            $this->assertNotEquals($timeToRebuild, $time);
            $this->assertEquals($timeToRebuild, $defaultTimeToRebuild);
        }

        $key = md5(microtime());
        $value = 'value';
        if ($instance->getItem($key) === null) {
            $testCache = new KeyValue($key, $value);
            $instance->save($testCache);
        }

        $this->assertEquals($defaultTimeToRebuild, $instance->getTimeToRebuild());
    }

    /**
     * @dataProvider getCountToRebuildDataProvider
     */
    public function testRebuildCount(?int $count, $isDefault = false, $isNegatove = false)
    {
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $reflection = new \ReflectionClass($instance);
        $defaultRebuildCount = $reflection->getConstant('DEFAULT_REBUILD_CHECK_COUNT');

        if ($isDefault === true) {
            $countToRebuild = $instance->getCountToRebuild();
            $this->assertEquals($countToRebuild, $defaultRebuildCount);
        } elseif ($isDefault === false && $isNegatove === false) {
            $instance->setCountToRebuild($count);
            $countToRebuild = $instance->getCountToRebuild();
            $this->assertEquals($countToRebuild, $count);
        } else {
            $instance->setCountToRebuild($count);
            $countToRebuild = $instance->getCountToRebuild();
            $this->assertNotEquals($countToRebuild, $count);
            $this->assertEquals($countToRebuild, $defaultRebuildCount);
        }

        $key = md5(microtime());
        $value = 'value';
        if ($instance->getItem($key) === null) {
            $testCache = new KeyValue($key, $value);
            $instance->save($testCache);
        }

        $this->assertEquals($defaultRebuildCount, $instance->getCountToRebuild());
    }

    /**
     * @dataProvider getRebuildCheckPeriodDataProvider
     */
    public function testRebuildCheckPeriod($setData, $currentData)
    {
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();

        if ($setData === '') {
            $rebuildPeriod = $instance->getRebuildCheckPeriod();
            $this->assertEquals($rebuildPeriod, $currentData);
        } elseif ($setData === null) {
            $instance->setRebuildCheckPeriod($setData);
            $rebuildPeriod = $instance->getRebuildCheckPeriod();
            $this->assertEquals($rebuildPeriod, $currentData);
        } elseif(\is_numeric($setData) === true) {
            $instance->setRebuildCheckPeriod((float)$setData);
            $rebuildPeriod = $instance->getRebuildCheckPeriod();
            if ($currentData === null) {
                $this->assertEquals($rebuildPeriod, $currentData);
            } else {
                $this->assertEquals($rebuildPeriod, (float)$currentData);
            }
        }

        $key = md5(microtime());
        $value = 'value';
        if ($instance->getItem($key) === null) {
            $testCache = new KeyValue($key, $value);
            $instance->save($testCache);
        }

        $this->assertEquals(null, $instance->getRebuildCheckPeriod());
    }

    public function testCommit()
    {
        $this->expectException(SimpleCacheException::class);
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $instance->commit();
    }

    public function testGetItems()
    {
        $this->expectException(SimpleCacheException::class);
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $instance->getItems(['test', 'test']);
    }

    public function testSaveDeferred()
    {
        $this->expectException(SimpleCacheException::class);
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $testCache = new KeyValue('test', 'test');
        $instance->saveDeferred($testCache);
    }

    public function testDeleteItems()
    {
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $key = md5(microtime());
        $keyTwo = $key . '_test';

        $result = $instance->deleteItems([$key, $keyTwo]);
        $this->assertTrue($result);

        if ($instance->getItem($key) === null) {
            $testCache = new KeyValue($key, 'value');
            $instance->save($testCache);
        }

        if ($instance->getItem($keyTwo) === null) {
            $testCache = new KeyValue($keyTwo, 'value');
            $instance->save($testCache);
        }

        $result = false;
        if ($instance->getItem($key) !== null) {
            $result = $instance->deleteItem($key);
        }
        $this->assertTrue($result);

        $result = false;
        if ($instance->getItem($keyTwo) !== null) {
            $result = $instance->deleteItem($keyTwo);
        }
        $this->assertTrue($result);

        $this->assertEquals($instance->getItem($key), null);
        $this->assertEquals($instance->getItem($keyTwo), null);
    }

    public function testClear()
    {
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $result = $instance->clear();
        $this->assertTrue($result);
    }

    public function testDeleteItem()
    {
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $key = md5(microtime());
        $result = $instance->deleteItem($key);
        $this->assertTrue($result);

        if ($instance->getItem($key) === null) {
            $testCache = new KeyValue($key, 'value');
            $instance->save($testCache);
        }

        $result = false;
        if ($instance->getItem($key) !== null) {
            $result = $instance->deleteItem($key);
        }
        $this->assertTrue($result);

        $this->assertEquals($instance->getItem($key), null);
    }

    public function testDeleteDelayItem()
    {
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $key = md5(microtime());
        $result = $instance->deleteItem($key);
        $this->assertTrue($result);

        if ($instance->getItem($key) === null) {
            $testCache = new KeyValue($key, 'value');
            $instance->save($testCache);
        }

        $result = false;
        if ($instance->getItem($key) !== null) {
            $result = $instance->deleteItemDelay($key, 10);
        }

        $this->assertTrue($result);
        $this->assertEquals($instance->getItem($key), null);
    }

    public function testAddTags()
    {
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $key = md5(microtime());
        $value = 'value';
        if ($instance->getItem($key) === null) {
            $testCache = new KeyValue($key, $value, ['test_tag', 'test_tag2']);
            $instance->save($testCache);
        }

        $result = $instance->getItem($key);
        $this->assertEquals($result->get(), $testCache->get());
        $this->assertEquals($result->getTags(), $testCache->getTags());
        $this->assertEquals($result->getKey(), $testCache->getKey());
    }

    public function testExpireByTag()
    {
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $key = md5(microtime());
        $value = 'value';
        if ($instance->getItem($key) === null) {
            $testCache = new KeyValue($key, $value, [ $key . '_test', $key . '_test_2']);
            $result = $instance->save($testCache);
        }

        $result = $instance->getItem($key);
        $this->assertEquals($result->get(), $testCache->get());
        $this->assertEquals($result->getTags(), $testCache->getTags());
        $this->assertEquals($result->getKey(), $testCache->getKey());

        $result = $instance->deleteByTag($key . '_test');
        $this->assertTrue($result);

        $result = $instance->getItem($key);
        $this->assertEquals($result, null);

        $key = md5(microtime()) . '_key_';
        $result = $instance->getItem($key);
        if ($result === null) {
            $testCache = new KeyValue($key, $value . 'test_3', [$key . '_test_3', $key . '_test_4']);
            $instance->save($testCache);
        }

        $result = $instance->deleteByTag($key . '_test_4');
        $this->assertTrue($result);

        $result = $instance->getItem($key);
        $this->assertEquals($result, null);
    }

    public function testStatus()
    {
        /** @var PoolInterface $instance */
        $instance = Pool::getInstance();
        $key = md5(microtime());
        $value = 'value';
        $this->assertEquals($instance->getStatus(), TCacheHelper::STATUS_UNKNOWN);
        if ($instance->getItem($key) === null) {
            $this->assertEquals($instance->getStatus(), TCacheHelper::STATUS_NOT_EXIST_UNDER_CONSTRUCTION);
            $testCache = new KeyValue($key, $value, [ $key . '_test', $key . '_test_2']);
            $instance->save($testCache);
        }

        $instance->getItem($key);
        $this->assertEquals($instance->getStatus(), TCacheHelper::STATUS_ACTUAL);
        $instance->deleteItem($key);
        $key2 = md5(microtime());
        $key3 = md5(microtime() . microtime());
        if ($instance->getItem($key) === null) {
            $this->assertEquals($instance->getStatus(), TCacheHelper::STATUS_EXPIRED_UNDER_CONSTRUCTION);
            $instance->getItem($key2);

            $this->assertEquals($instance->getStatus(), TCacheHelper::STATUS_BUILD_OUTSIDE);
            $testCache2 = new KeyValue($key2, $value, [ $key2 . '_test', $key2 . '_test_2']);
            $result = $instance->save($testCache2);
            $this->assertFalse($result);

            $instance->getItem($key3);
            $this->assertEquals($instance->getStatus(), TCacheHelper::STATUS_BUILD_OUTSIDE);
            $testCache3 = new KeyValue($key3, $value, [ $key3 . '_test', $key3 . '_test_2']);
            $result = $instance->save($testCache3);
            $this->assertFalse($result);

            $testCache = new KeyValue($key, $value, [ $key . '_test', $key . '_test_2']);
            $result = $instance->save($testCache);
            $this->assertTrue($result);
            $this->assertEquals($instance->getStatus(), TCacheHelper::STATUS_UNKNOWN);
        }
    }

    /**
     * @return array
     */
    public function getTimeToRebuildDataProvider()
    {
        return [
            [null, true, false],
            [3, false, false],
            [null, false, true],
            [0, false, true],
            [-1, false, true],
        ];
    }

    /**
     * @return array
     */
    public function getCountToRebuildDataProvider()
    {
        return [
            [null, true, false],
            [3, false, false],
            [null, false, false],
            [0, false, false],
            [-1, false, true],
        ];
    }

    public function getRebuildCheckPeriodDataProvider()
    {
        return [
            ['', null],
            [0, null],
            [1, 1],
            [3, 3],
            [null, null],
        ];
    }
}
