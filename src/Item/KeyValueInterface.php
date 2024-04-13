<?php
declare(strict_types = 1);
namespace mrsatik\TCache\Item;

use Psr\Cache\CacheItemInterface;

interface KeyValueInterface
{
    /**
     * Возвращает время протухания кеша
     * Если работа с кешом выстроена правильно, то тут будет всегда null
     * @return int|NULL
     */
    public function getExpiredTime(): ?int;

    /**
     * Добавлет имя тега
     * @param string $tagName имя тега
     * @return CacheItemInterface
     *  - новый экземпляр класса
     */
    public function addTag(string $tagName): CacheItemInterface;

    /**
     * Массив тегов
     * @param array $tagsNew[]
     */
    public function addTags(array $tagsNew): CacheItemInterface;

    /**
     * Возвращает список тегов
     * @return array
     */
    public function getTags(): array;
}