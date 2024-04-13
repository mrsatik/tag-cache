<?php
declare(strict_types = 1);
namespace mrsatik\TCache\Locker;

interface CASLockInterface
{
    /**
     * @return bool
     */
    public function unlock();

    /**
     * @return int
     */
    public function getStatus();
}