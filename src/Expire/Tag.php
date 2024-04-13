<?php
declare(strict_types=1);

namespace mrsatik\TCache\Expire;

use Memcached;
use mrsatik\TCache\TCacheHelper;
use mrsatik\TCache\Exception\InvalidArgumentException;
use mrsatik\TCache\Exception\InvalidConnectionException;

class Tag
{
    /**
     * @var Memcached
     */
    private $cacheInstance;

    /**
     * @var Memcached
     */
    private $tagCache;

    protected $expireTime;

    public function __construct(Memcached $instance, ?Memcached $tagInstance = null)
    {
        $this->cacheInstance = $instance;
        if ($tagInstance !== null) {
            $this->tagCache = $tagInstance;
        } else {
            $this->tagCache = $this->cacheInstance;
        }
    }

    /**
     * Объявляет истекшими все ключи, имеющие хотя бы один из тэгов $tags, соответствующие этим ключам объекты TCache остаются
     *
     * @param array $tags тэги, по которым нужно "сбросить" ключи или один тэг
     * @param bool $noDelay форсировать немедленный сброс
     * @see TCacheHelper::EXPIRE_DELAY
     * @throws \InvalidArgumentException при неверной длине тэга
     */
    public function expireByNames(array $tags, ?bool $noDelay = false): bool
    {
        $noDelay = is_bool($noDelay) === false ? false : $noDelay;
        if (TCacheHelper::checkTagsLength($tags) === false) {
            throw new InvalidArgumentException('Uncorrect tag name lenght');
        }
        return $this->expire(TCacheHelper::prefixTags($tags), $noDelay);
    }

    /**
     * Объявляет истекшими все ключи, имеющие тэг $tag, соответствующие этим ключам объекты TCache остаются
     *
     * @param string $tag
     * @param bool $noDelay
     */
    public function expireByName(string $tag, ?bool $noDelay = false): bool
    {
        return $this->expireByNames([$tag], $noDelay);
    }

    /**
     * Объявляет истекшими тэги или версионные ключи
     *
     * @param \MemcachePool $cacheInstance инстанс кеша для работы с ключами
     * @param array|string $prefixedKeyNames уже подготовленные имена ключей
     * @param bool $noDelay форсировать немедленный сброс
     * @see TCacheHelper::EXPIRE_DELAY
     */
    protected function expire(array $prefixedKeyNames, bool $noDelay)
    {
        $flag = 1;
        if (\is_array($prefixedKeyNames) === false) {
            $prefixedKeyNames = [$prefixedKeyNames];
        }

        if ($noDelay === true) {
            $tagsToSet = \array_fill_keys($prefixedKeyNames, [
                (string) TCacheHelper::normalizeTime(microtime(true))
            ]);

            foreach ($tagsToSet as $key => $value) {
                $flag &= $this->getCacheInstance()->set($key, $value) === true ? 1 : 0;
            }
        } else {
            $result = $this->getCacheInstance()->getMulti($prefixedKeyNames, Memcached::GET_EXTENDED);
            if ($result === false) {
                throw new InvalidConnectionException('Connect to tag memcache is lost');
            }
            $rawData = $this->processTagsQueue($result);
            foreach ($rawData as $key => $keyData) {
                $this->getCacheInstance()->cas($keyData['cas'], $key, $keyData['value'], 0);
            }

            $notExistedTags = \array_diff($prefixedKeyNames, \array_keys($rawData));

            if ($notExistedTags === []) {
                return true;
            }
            $tagsToAdd = \array_fill_keys($notExistedTags, [(string)TCacheHelper::normalizeTime(microtime(true))]);

            foreach ($tagsToAdd as $tagKey => $tagValue) {
                $flag &= $this->tagCache->add($tagKey, $tagValue) === true ? 1 : 0;
            }
        }

        return (bool)$flag;
    }

    /**
     * Производит обработку тэгов, проставляя новые значения и удаляя старые, когда их время уже истекло
     *
     * @param array $tags
     * @return array
     */
    private function processTagsQueue(array $tags): array
    {
        if ($tags === []) {
            return [];
        }
        $currentTime = TCacheHelper::normalizeTime(microtime(true));
        foreach ($tags as &$tag) {
            $reverseTag = \array_reverse($tag['value'], true);
            foreach ($reverseTag as $key => $value) {
                $value = (float)$value;
                if ($value <= $currentTime) {
                    $tag['value'] = \array_slice($tag['value'], $key);
                    break;
                }
            }
            $tag['value'][] = (string)TCacheHelper::normalizeTime($currentTime + $this->getExpireTime());
        }

        return $tags;
    }

    /**
     * @return Memcached
     */
    private function getCacheInstance(): Memcached
    {
        return $this->cacheInstance;
    }

    /**
     * Возвращает время отложенного сброса
     * @return int
     */
    private function getExpireTime(): int
    {
        return $this->expireTime === null || $this->expireTime <= 0 ? TCacheHelper::EXPIRE_DELAY : $this->expireTime;
    }
}