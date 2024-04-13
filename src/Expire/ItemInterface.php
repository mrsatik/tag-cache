<?php
declare(strict_types=1);

namespace mrsatik\TCache\Expire;

interface ItemInterface
{
    /**
     * Объявляет один ключ
     *
     * @param array|string $keyNames имя или имена ключей для "сброса"
     * @param bool $noDelay форсировать немедленный сброс
     * @return bool
     */
    public function expireByName(string $keyName, ?bool $noDelay = false): bool;

    /**
     * Объявляет истекшими ключи по их именам
     *
     * @param array $keyNames имя или имена ключей для "сброса"
     * @param bool $noDelay форсировать немедленный сброс
     * @return bool
     */
    public function expireByNames(array $keyNames, ?bool $noDelay = false): bool;

    /**
     * Время для отложенного сброса кеша
     * @param int $timeInSeconds
     * @return void
     */
    public function setExpireTime(int $timeInSeconds): void;

    /**
     * Возвращает время в дефолтное значение
     */
    public function resetExpireTime(): void;
}