<?php
declare(strict_types = 1);

namespace mrsatik\TCache;

class TCacheHelper implements TCacheHelperInterface
{
    /** @var int */
    public const STATUS_UNKNOWN = 0;

    /** @var int */
    public const STATUS_ACTUAL = 1;

    /** @var int */
    public const STATUS_EXPIRED = 2;

    /** @var int */
    public const STATUS_EXPIRED_UNDER_CONSTRUCTION = 3;

    /** @var int */
    public const STATUS_NOT_EXIST_UNDER_CONSTRUCTION = 4;

    /** @var int */
    public const STATUS_BUILD_OUTSIDE = 5;

    /**
     * Все ключи по тэгам или по прямому сбросу будут сбрасываться отложенно на это кол-во секунд
     * (если ключи строятся со слейва, тут должно быть установлено максимальное время задержки репликации)
     *
     * @var int
     */
    public const EXPIRE_DELAY = 4;

    /**
     * Префикс для ключей, отвечающих за тэги
     *
     * @var string
     */
    private const TAG_PREFIX = 'tag_';

    /**
     * Префикс для ключей, отвечающих за версию ключа
     *
     * @var string
     */
    private const VERSION_PREFIX = 'ver_';

    /**
     * {@inheritDoc}
     * @see TCacheHelperInterface::checkTagsLength()
     */
    public static function checkTagsLength(array $tags): bool
    {
        $result = true;
        foreach ($tags as $tag) {
            if ($tag === '' || strlen($tag) > 230) {
                $result = false;
                break;
            }
        }
        return $result;
    }

    /**
     * {@inheritDoc}
     * @see TCacheHelperInterface::normalizeTime()
     */
    public static function normalizeTime(float $time): float
    {
        return round((float)$time, 6);
    }

    /**
     * {@inheritDoc}
     * @see TCacheHelperInterface::prefixTags()
     */
    public static function prefixTags($tags)
    {
        return self::prefixKeys($tags, self::TAG_PREFIX);
    }

    /**
     * {@inheritDoc}
     * @see TCacheHelperInterface::prefixVerKeys()
     */
    public static function prefixVerKeys($vKeys)
    {
        return self::prefixKeys($vKeys, self::VERSION_PREFIX);
    }

    /**
     * {@inheritDoc}
     * @see TCacheHelperInterface::unprefixTags()
     */
    public static function unprefixTags($tags)
    {
        return self::unprefixKeys($tags, self::TAG_PREFIX);
    }

    /**
     * Добавляет префикс строке или строкам, переданным в индексированном массиве
     *
     * @param array|string $keys
     * @param string $prefix
     * @return array|string
     */
    private static function prefixKeys($keys, $prefix)
    {
        if (\is_array($keys) === false) {
            $prefixedKeys = $prefix . $keys;
        } else {
            $prefixedKeys = \array_map(function ($tag) use ($prefix) {
                return $prefix . $tag;
            }, $keys);
        }
        return $prefixedKeys;
    }

    /**
     * Удаляет префикс у строки или у строк, переданных в индексированном массиве
     *
     * @param array|string $keys
     * @param string $prefix
     * @return array|string
     */
    private static function unprefixKeys($keys, $prefix)
    {
        if (\is_array($keys) === false) {
            $unprefixedKeys = substr($keys, strlen($prefix));
        } else {
            $unprefixedKeys = \array_map(function ($tag) use ($prefix) {
                return substr($tag, strlen($prefix));
            }, $keys);
        }
        return $unprefixedKeys;
    }
}