<?php
declare(strict_types = 1);

namespace mrsatik\TCache;

interface TCacheHelperInterface
{
    /**
     * Проверяет, что переданные тэги имеют корректную длину
     *
     * @param array $tags
     * @return bool
     */
    public static function checkTagsLength(array $tags): bool;

    /**
     * Округляет время в микросекундах, чтобы нивилировать ошибки при работе с float
     *
     * @param float $time время тэга
     * @return float
     */
    public static function normalizeTime(float $time): float;

    /**
     * Добавляет префикс к тэгам, переданным в индексированном массиве
     *
     * @param array|string $tags
     * @return array|string
     */
    public static function prefixTags($tags);

    /**
     * Добавляет префикс к версионным ключам, переданным в индексированном массиве
     *
     * @param array|string $vKeys
     * @return array|string
     */
    public static function prefixVerKeys($vKeys);

    /**
     * Удаляет префикс у тэгов, переданных в индексированном массиве
     *
     * @param array|string $tags
     * @return array|string
     */
    public static function unprefixTags($tags);
}