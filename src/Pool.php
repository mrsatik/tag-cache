<?php
declare(strict_types=1);

namespace mrsatik\TCache;

use Exception;
use Memcached;
use mrsatik\Settings\Settings;
use mrsatik\Servers\ServersCollectionInterface;
use mrsatik\Servers\ServerInterface;
use mrsatik\TCache\Exception\EmptyValueServerList;
use mrsatik\TCache\Exception\EmptyTagServerList;
use mrsatik\TCache\Exception\InvalidArgumentException;
use mrsatik\TCache\TCacheHelper;
use mrsatik\TCache\Locker\CASLock;
use Psr\Cache\CacheItemInterface;
use mrsatik\TCache\Item\KeyValue;
use Psr\Cache\CacheItemPoolInterface;
use mrsatik\TCache\Exception\SimpleCacheException;
use mrsatik\TCache\Expire\Item;
use mrsatik\TCache\Expire\Tag;
use mrsatik\NullCache\PoolInterface;
use mrsatik\TCache\Exception\InvalidConnectionException;
use PHP_CodeSniffer\Util\Cache;
use mrsatik\NullCache\CachePool;

class Pool extends CASLock implements PoolInterface, CacheItemPoolInterface
{
    /**
     * @var Memcached
     */
    private $valueCache;

    /**
     * @var Memcached
     */
    private $tagsCache;

    /**
     * @var PoolInterface
     */
    private static $memcacheConnection;

    /**
     * @var string
     */
    protected const SETTING_VALUE_NAME = 'memcache.servers.value';

    /**
     * @var string
     */
    protected const SETTING_TAG_NAME = 'memcache.servers.tags';

    /**
     * Хранит количество секунд по умолчанию, в течение которых будет считаться, что ключ не требует ревалидации (0 - бессрочно)
     *
     * @var int
     */
    private const DEFAULT_EXPIRE_TTL = 0;

    /**
     * Хранит, сколько по умолчанию раз мы будем проверять готовность ключа
     *
     * @var int
     */
    public const DEFAULT_REBUILD_CHECK_COUNT = 0;

    /**
     * Хранит количество секунд по умолчанию, в течение которых ключ будет храниться в мемкеше (0 - бессрочно)
     *
     * @var int
     */
    private const DEFAULT_STORAGE_TTL = 0;

    /**
     * Хранит, сколько по умолчанию будет предположительно длиться ревалидация ключа
     *
     * @var int
     */
    public const DEFAULT_TIME_TO_REBUILD = 2;

    /**
     * @var string
     */
    private const TEST_KEY_NAME = 'test_key';

    private $timeToRebuild;

    private $countToRebuild;

    private $rebuildCheckPeriod;

    /**
     * Массив, содержащий тэги, которые надо установить текущему ключу при set в формате array(tagname:string => true)
     *
     * @var array
     */
    private $tags = [];

    /**
     * Текущий строящийся ключ
     * @var string|null
     */
    private $buildKey = null;

    private function __construct()
    {
        $this->connect();
    }

    /**
     * @see PoolInterface::getInstance()
     */
    public static function getInstance(): PoolInterface
    {
        if (self::$memcacheConnection === null) {
            try {
                self::$memcacheConnection = new static();
            } catch (InvalidConnectionException $e) {
                self::$memcacheConnection = new CachePool();
            } catch (Exception $e) {
                throw $e;
            }
        }

        return self::$memcacheConnection;
    }

    private function getSettingOfValue(): string
    {
        return static::SETTING_VALUE_NAME;
    }

    private function getSettingOfTag(): string
    {
        return static::SETTING_TAG_NAME;
    }

    public function getItem($key): ?CacheItemInterface
    {
        if (\is_string($key) === false) {
            throw new InvalidArgumentException('Cache key must be string');
        }

        if ($key === '' || $key === self::TEST_KEY_NAME) {
            throw new InvalidArgumentException('Empty cache key');
        }

        if ($this->lockCas !== null) {
            $this->status = TCacheHelper::STATUS_BUILD_OUTSIDE;
            return null;
        }

        $key = $this->stripKeyName($key);

        $timeToRebuild = (float)$this->getTimeToRebuild();
        $versionKeyName = TCacheHelper::prefixVerKeys($key);
        $dataKeys = [
            $key,
            $versionKeyName,
        ];

        $emptyFlags = null;
        for ($i = 0; $i <= 1; $i++) {
            $fetchData = $this->valueCache->getMulti($dataKeys, Memcached::GET_EXTENDED);
            $rawData =  \is_array($fetchData) === true ? ($fetchData + \array_fill_keys($dataKeys, null)) : false;

            if(\is_array($rawData) === true) {
                break;
            } elseif ($rawData === false && $i === 0) {
                $this->connect();
            } elseif($rawData === false && $i > 0) {
                self::$memcacheConnection = new CachePool();
                return null;
            }
        }

        $data = $rawData[$key];
        $this->dataCas = isset($data['cas']) === true ? $data['cas'] : null;
        $this->version = $this->getCurrentVersion(isset($rawData[$versionKeyName]) ? $rawData[$versionKeyName] : null);

        $rebuildCheckCount = $this->getCountToRebuild();
        $rebuildCheckPeriod = $this->getRebuildCheckPeriod();

        if ($data === null) {
            //ключ запишет только тот, кто получил лок, поэтому сначала пытаемся его получить
            if ($this->lock($key, $timeToRebuild) === true || $rebuildCheckCount === 0) {
                $this->status = TCacheHelper::STATUS_NOT_EXIST_UNDER_CONSTRUCTION;
                $this->buildKey = $key;
                $this->setCountToRebuild(null);
                $this->setTimeToRebuild(self::DEFAULT_TIME_TO_REBUILD);
                $this->setRebuildCheckPeriod(null);
                return null;
            }

            if ($rebuildCheckPeriod === null) {
                $this->setRebuildCheckPeriod((float)$timeToRebuild / $rebuildCheckCount);
                $rebuildCheckPeriod = $this->getRebuildCheckPeriod();
            }

            // другой способ паузы выполнения скрипта - time_sleep_until ( $unlockTime );
            usleep(ceil($rebuildCheckPeriod * 1000000));
            $this->setCountToRebuild($rebuildCheckCount - 1);
            return $this->getItem($key);
        } else {
            $tryToLock = $this->version === null
                || $this->version > (float)$data['value']['VERSION']
                || $this->tagsIsValid($data['value']['TAGS']) === false;

            if ($tryToLock === true && $this->lock($key, $timeToRebuild) === true) {
                $this->status = TCacheHelper::STATUS_EXPIRED_UNDER_CONSTRUCTION;
                $this->buildKey = $key;
                return null;
            }

            if ($tryToLock === true) {
                $this->status = TCacheHelper::STATUS_EXPIRED;
            } else {
                $this->status = TCacheHelper::STATUS_ACTUAL;
            }

            if ($data['value']['TAGS'] !== []) {
                return new KeyValue($key, $data['value']['DATA'], \array_keys($data['value']['TAGS']), null, true);
            } else {
                return new KeyValue($key, $data['value']['DATA'], [], null, true);
            }
        }
    }

    public function save(CacheItemInterface $item): bool
    {
        if ($item->get() === null) {
            throw new InvalidArgumentException(\sprintf('Set null as value for key: %s', $item->getKey()));
        }

        if ($item->getKey() === self::TEST_KEY_NAME) {
            throw new InvalidArgumentException(\sprintf('Name of key can not bee: %s', self::TEST_KEY_NAME));
        }

        if ($this->buildKey !== $item->getKey()) {
            return false;
        }

        $tags = $item->getTags();
        $this->addTags($tags);

        $result = false;
        if ($this->lockCas !== null) {
            if ($this->version === null) {
                $version = (string)TCacheHelper::normalizeTime (microtime(true));
                $versionResult = $this->getValueCache()->add(
                    TCacheHelper::prefixVerKeys($this->keyName),
                    [
                        $version
                    ],
                    $item->getExpiredTime() === null || $item->getExpiredTime() <= 0 ? 0 : $item->getExpiredTime());
            } else {
                $version = $this->version;
                $versionResult = true;
            }

            if ($versionResult === true) {
                $tags = [];
                if ($this->tags !== []) {
                    $tags = $this->createAndGetTags(\array_keys($this->tags), $this->lockTime);
                    foreach ($tags as $tagTime) {
                        if ((double)$tagTime > (double)$this->lockTime && (string)$tagTime != (string)$this->lockTime) {
                            $this->setCountToRebuild(null);
                            $this->setTimeToRebuild(self::DEFAULT_TIME_TO_REBUILD);
                            $this->setRebuildCheckPeriod(null);
                            $this->unlock();
                            return false;
                        }
                    }
                }
                $keyData = [
                    'VERSION' => $version,
                    'TAGS' => $tags,
                    'DATA' => $item->get(),
                ];

                if ($this->dataCas === null) {
                    $expiredTime = self::DEFAULT_STORAGE_TTL;
                    if ($item->getExpiredTime() !== null) {
                        $expiredTime = $item->getExpiredTime();
                    }

                    $result = $this->getValueCache()->add($this->keyName, $keyData, $expiredTime);
                } else {
                    $result = $this->getValueCache()->cas($this->dataCas, $this->keyName, $keyData, self::DEFAULT_STORAGE_TTL);
                }
            }

            $this->setCountToRebuild(null);
            $this->setTimeToRebuild(self::DEFAULT_TIME_TO_REBUILD);
            $this->setRebuildCheckPeriod(null);
            $this->unlock();
        } else {
            $this->buildKey = null;
        }

        return $result;
    }

    /**
     * @return boolean
     */
    public function clear(): bool
    {
        $this->unlock();
        return true;
    }

    /**
     * {@inheritDoc}
     * @see CacheItemPoolInterface::deleteItem()
     */
    public function deleteItem($key)
    {
        $expireItem = new Item($this->getValueCache(), $this->getTagCache());
        return $expireItem->expireByName($key, true);
    }

    /**
     * {@inheritDoc}
     * @see CacheItemPoolInterface::deleteItems()
     */
    public function deleteItems(array $keys)
    {
        $expireItem = new Item($this->getValueCache(), $this->getTagCache());
        return $expireItem->expireByNames($keys, true);
    }

    /**
     * {@inheritDoc}
     * @see PoolInterface::deleteItemDelay()
     */
    public function deleteItemDelay(string $key, int $timeInSeconds = TCacheHelper::EXPIRE_DELAY): bool
    {
        $expireItem = new Item($this->getValueCache(), $this->getTagCache());
        $expireItem->setExpireTime($timeInSeconds);
        $result = $expireItem->expireByName($key);
        $expireItem->resetExpireTime();
        return $result;
    }

    public function deleteByTag(string $tag): bool
    {
        try {
            return (new Tag($this->getTagCache()))->expireByName($tag);
        } catch (InvalidConnectionException $e) {
            $this->connect();
            return (new Tag($this->getTagCache()))->expireByName($tag);
        }
    }

    public function deleteByTags(array $tags): bool
    {
        try {
            return (new Tag($this->getTagCache()))->expireByNames($tags);
        } catch (InvalidConnectionException $e) {
            $this->connect();
            return (new Tag($this->getTagCache()))->expireByNames($tags);
        }
    }

    public function hasItem($key): bool
    {
        return $this->getItem($key) !== null;
    }

    public function getTimeToRebuild(): int
    {
        if ($this->timeToRebuild === null) {
            $this->setTimeToRebuild();
        }
        return $this->timeToRebuild;
    }

    public function setTimeToRebuild(?int $time = self::DEFAULT_TIME_TO_REBUILD): void
    {
        $this->timeToRebuild = $time !== null && $time > 0 ? $time : self::DEFAULT_TIME_TO_REBUILD;
    }

    public function getCountToRebuild(): int
    {
        if ($this->countToRebuild === null) {
            $this->setCountToRebuild();
        }
        return $this->countToRebuild;
    }

    public function setCountToRebuild(?int $count = self::DEFAULT_REBUILD_CHECK_COUNT): void
    {
        $this->countToRebuild = $count > 0 && $count !== null ? $count : self::DEFAULT_REBUILD_CHECK_COUNT;
    }

    public function getRebuildCheckPeriod(): ?float
    {
        return $this->rebuildCheckPeriod;
    }

    public function setRebuildCheckPeriod(?float $period = null): void
    {
        $this->rebuildCheckPeriod = $period > 0 && $period !== null ? $period : null;
    }

    /**
     * {@inheritDoc}
     * @see CacheItemPoolInterface::getItems()
     */
    public function getItems(array $keys = [])
    {
        throw new SimpleCacheException('Not supported yet');
    }

    /**
     * {@inheritDoc}
     * @see CacheItemPoolInterface::commit()
     */
    public function commit()
    {
        throw new SimpleCacheException('Not supported yet');
    }

    /**
     * {@inheritDoc}
     * @see CacheItemPoolInterface::saveDeferred()
     */
    public function saveDeferred(CacheItemInterface $item)
    {
        throw new SimpleCacheException('Not supported yet');
    }

    /**
     * {@inheritDoc}
     * @see CASLock::getValueCache()
     */
    protected function getValueCache(): Memcached
    {
        return $this->valueCache;
    }

    /**
     * {@inheritDoc}
     * @see CASLock::getTagCache()
     */
    protected function getTagCache(): Memcached
    {
        return $this->tagsCache;
    }

    /**
     * Из версионных данных извлекает текущее значение версии ключа
     *
     * @param array $versionData
     * @return string|null
     */
    private function getCurrentVersion(?array $versionData): ?float
    {
        if (\is_array($versionData['value']) === true) {
            foreach ($versionData['value'] as $key => $value) {
                $versionData['value'][$key] = (float)$value;
            }
            $result = max($versionData['value']);
        } else {
            $result = $versionData['value'];
        }
        return $result;
    }

    private function stripKeyName(string $keyName): string
    {
        $forbiddenChars = array(
            "\x00", "\x01", "\x02", "\x03", "\x04",
            "\x05", "\x06", "\x07", "\x08", "\x09",
            "\x0a", "\x0b", "\x0c", "\x0d", "\x0e",
            "\x0f", "\x10", "\x11", "\x12", "\x13",
            "\x14", "\x15", "\x16", "\x17", "\x18",
            "\x19", "\x1a", "\x1b", "\x1c", "\x1d",
            "\x1e", "\x1f", "\x20", "\x7f",
        );
        $keyName = str_replace($forbiddenChars, '', $keyName);
        if (strlen($keyName) > 230) {
            $keyName = substr($keyName, 0, 198) . md5($keyName);
        }
        return $keyName;
    }

    /**
     * Принимает набор тэгов и возвращает false, если есть хоть один истекший тэг
     *
     * @param array $tags Массив тэгов в формате array(tagname:string => tagmicrotime:float)
     * @return boolean
     */
    private function tagsIsValid(array $tags = []): bool
    {
        if ($tags === []) {
            return true;
        }
        $currentTags = $this->getKeyTags(\array_keys($tags));
        foreach ($tags as $tagName => $tagTime) {
            if (isset($currentTags[$tagName]) === false || (float)$currentTags[$tagName] > (float)$tagTime) {
                return false;
            }
        }
        return true;
    }

    /**
     * Возвращает значения timestamp по тэгам, перечисленным в $tags в формате array(tagname:string => tagmicrotime:float)
     * Если такого тэга нет в мемкеше, в выходном массиве он будет отсутствовать
     *
     * @param array $tags Индексированный массив имён тэгов
     * @return array
     */
    private function getKeyTags(array $tags = []): array
    {
        $tagsData = $this->getTagCache()->getMulti(TCacheHelper::prefixTags($tags));
        $result = [];
        foreach ($tagsData as $key => $versionData) {
            foreach ($versionData as $keyOfVersion => $value) {
                $versionData[$key] = (float)$value;
            }
            $result[$key] = max($versionData);
        }

        if (sizeof($result) > 0) {
            $result = \array_combine(TCacheHelper::unprefixTags(\array_keys($tagsData)), $result);
            if ($result === false) {
                $result = [];
            }
        }
        return $result;
    }

    /**
     * Добавляет тэг(и) к текущему ключу.
     * Тэги будут добавлены в memcached при вызове set.
     *
     * @param array $tags
     */
    private function addTags(array $tags = [])
    {
        if (TCacheHelper::checkTagsLength($tags) === []) {
            throw new InvalidArgumentException ('Not a correct tag lenght');
        }
        $this->tags = \array_fill_keys($tags, true);
    }

    /**
     * Возвращает текущие значения тэгов в формате array(tagname:string => tagmicrotime:float) и создает несуществующие
     *
     * @param array $tags Индексированный массив имён тэгов
     * @return array
     */
    private function createAndGetTags(array $tags = [], ?float $lockTime = null): array
    {
        do {
            $this->createTags($tags, $lockTime);
            $result = $this->getKeyTags($tags);
        } while (sizeof($result) !== sizeof($tags));
        return $result;
    }

    /**
     * Создаёт несуществующие тэги
     *
     * @param array $tags Индексированный массив имён тэгов
     * @param float|null $lockTime
     */
    private function createTags(array $tags = [], ?float $lockTime = null): bool
    {
        if ($lockTime !== null) {
            $currentTime = [(string)$lockTime];
        } else {
            $currentTime = [(string)TCacheHelper::normalizeTime(microtime(true))];
        }
        $flag = 1;
        foreach (TCacheHelper::prefixTags($tags) as $tagKey) {
            $flag &= $this->getTagCache()->add($tagKey, $currentTime) === true ? 1 : 0;
        }

        return $flag === 0 ? false : true;
    }

    /**
     * Коннект к мемкешу
     * @throws EmptyValueServerList
     * @throws EmptyTagServerList
     * @throws InvalidConnectionException
     */
    private function connect(): void
    {
        $this->valueCache = new Memcached();
        $this->tagsCache  = new Memcached();

        /** @var ServersCollectionInterface $valueServers */
        $valueServers = Settings::getInstance()->getNullableValue($this->getSettingOfValue());
        /** @var ServersCollectionInterface $tagsServers */
        $tagsServers  = Settings::getInstance()->getNullableValue($this->getSettingOfTag());

        if (
            $valueServers === null
            || isset($valueServers['params']) === false
            || $valueServers['params'] === ''
        ) {
            throw new EmptyValueServerList('Empty params of servers');
        }

        if (
            $tagsServers === null
            || isset($tagsServers['params']) === false
            || $tagsServers['params'] === ''
        ) {
            throw new EmptyTagServerList('Empty params of servers');
        }

        $valueServers = $valueServers['params'];
        $tagsServers = $tagsServers['params'];

        $countOfValueServers = count($valueServers);
        $countOfTagsServers  = count($tagsServers);

        if ($countOfValueServers === 0) {
            throw new EmptyValueServerList('Empty servers list');
        }

        if ($countOfTagsServers === 0) {
            throw new EmptyTagServerList('Empty servers list');
        }

        $percentPerServersOfValues = (int)round(100 / $countOfValueServers, 0, PHP_ROUND_HALF_DOWN);
        $percentPerServersOfTags   = (int)round(100 / $countOfTagsServers, 0, PHP_ROUND_HALF_DOWN);

        /** @var ServerInterface $item */
        foreach ($valueServers as $items) {
            $this->getValueCache()->addServer($items->getHost(), (int)$items->getPort(), $percentPerServersOfValues);
        }

        foreach ($tagsServers as $items) {
            $this->getTagCache()->addServer($items->getHost(), (int)$items->getPort(), $percentPerServersOfTags);
        }

        $checkCache = (int)$this->getTagCache()->set(self::TEST_KEY_NAME, microtime(), 1);
        $checkCache &= (int)$this->getValueCache()->set(self::TEST_KEY_NAME, microtime(), 1);

        if ((bool)$checkCache === false) {
            throw new InvalidConnectionException('Can not connected to memcache');
        }
    }
}
