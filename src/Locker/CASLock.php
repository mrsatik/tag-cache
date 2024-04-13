<?php
declare(strict_types = 1);
namespace mrsatik\TCache\Locker;

use mrsatik\TCache\TCacheHelper;
use Memcached;

abstract class CASLock implements CASLockInterface
{
    /**
     * Префикс для ключей, отвечающих за локи
     *
     * @var string
     */
    private const LOCK_PREFIX = 'lock_';
    /**
     * "Ключ не заблокирован", проставляется, так как текущая реализация MemcachePool не поддерживает delete по CAS
     *
     * @var int
     */
    protected const KEY_UNLOCKED = - 1;

    /**
     * CAS-токен для ключа данных
     *
     * @var int
     */
    protected $dataCas = null;
    /**
     * Версия текущего ключа
     *
     * @var int
     */
    protected $version = null;
    /**
     * CAS-токен для лок-ключа
     *
     * @var int
     */
    protected $lockCas = null;
    /**
     * Время установки лока этим экземпляром класса
     *
     * @var float
     */
    protected $lockTime = null;
    /**
     * Имя ключа
     *
     * @var string $keyName
     */
    protected $keyName;
    /**
     * Статус последней операции
     *
     * @var int
     */
    protected $status;

    /**
     * Блокирует запись в ключ для всех процессов/экземпляров на $timeToRebuild секунд.
     * Возвращает true, если удалось поставить лок или если он уже был поставлен
     * раньше этим экземпляром класса
     *
     * @param float $timeToRebuild Количество секунд, на которые надо поставить лок
     * @return boolean
     */
    protected function lock(string $key, $timeToRebuild): bool
    {
        $this->keyName = $key;
        $emptyFlags = null;
        $lockKey = $this->getTagCache()->get($this->getLockKeyName(), $emptyFlags, Memcached::GET_EXTENDED);
        $lockTime = TCacheHelper::normalizeTime(microtime(true));
        $cas = isset($lockKey['cas']) === false ? null : $lockKey['cas'];

        if ($lockKey === false || $lockKey['value'] === self::KEY_UNLOCKED || $lockKey['value']['EXPIRED'] <= $lockTime) {
            $this->lockCas = null;
            $this->lockTime = null;
            $lockData = [
                'EXPIRED' => (string)TCacheHelper::normalizeTime($lockTime + $timeToRebuild),
                'TOKEN' => \mt_rand()
            ];

            if ($lockKey === false) {
                $result = $this->getTagCache()->add($this->getLockKeyName(), $lockData, (int)ceil($timeToRebuild));
            } else {
                $result = $this->getTagCache()->cas($cas, $this->getLockKeyName(), $lockData, (int)ceil($timeToRebuild));
            }

            if ($result === false) {
                return false;
            }

            $emptyFlags = null;
            $lockKey = $this->getTagCache()->get($this->getLockKeyName(), $emptyFlags, Memcached::GET_EXTENDED);

            if ($lockKey !== false && $lockKey['value'] === $lockData) {
                $this->lockCas = $lockKey['cas'];
                $this->lockTime = $lockTime;
                return true;
            } else {
                return false;
            }
        } else {
            if ($this->lockCas === $cas) {
                return true;
            } else {
                $this->lockCas = null;
                $this->lockTime = null;
                return false;
            }
        }
    }

    /**
     * Снимает блокировку с ключа, если он был заблокирован текущим процессом.
     * При успешном снятии возвращает true
     *
     * {@inheritdoc}
     */
    public function unlock()
    {
        $result = false;

        if ($this->lockCas !== null) {
            $emptyFlags = null;
            $valueData = $this->getTagCache()->get($this->getLockKeyName(), $emptyFlags, Memcached::GET_EXTENDED);
            if (($valueData !== false) && $valueData['cas'] === $this->lockCas) {
                $result = $this->getTagCache()->cas($this->lockCas, $this->getLockKeyName(), self::KEY_UNLOCKED, 1);
            }

            $this->lockCas = null;
            $this->lockTime = null;
            $this->dataCas = null;
            $this->version = null;
            $this->status = TCacheHelper::STATUS_UNKNOWN;
        }
        return $result;
    }


    /**
     * Получает имя для лок-ключа
     *
     * @return string
     */
    protected function getLockKeyName()
    {
        return self::LOCK_PREFIX . md5($this->keyName);
    }

    /**
     * @return Memcached
     */
    abstract protected function getValueCache(): Memcached;

    /**
     * @return Memcached
     */
    abstract protected function getTagCache(): Memcached;

    /**
     * Возвращает статус последней операции
     *
     * {@inheritdoc}
     */
    public function getStatus()
    {
        return $this->status;
    }
}