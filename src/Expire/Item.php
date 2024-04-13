<?php
declare(strict_types=1);

namespace mrsatik\TCache\Expire;

use mrsatik\TCache\TCacheHelper;

class Item extends Tag implements ItemInterface
{
    /**
     * {@inheritdoc}
     * @see ItemInterface::expireByName()
     */
    public function expireByName(string $keyName, ?bool $noDelay = false): bool
    {
        return $this->expireByNames([$keyName], $noDelay);
    }

    /**
     * {@inheritdoc}
     * @see ItemInterface::expireByNames()
     */
    public function expireByNames(array $keyNames, ?bool $noDelay = false): bool
    {
        $noDelay = is_bool($noDelay) === false ? false : $noDelay;
        return $this->expire(TCacheHelper::prefixVerKeys($keyNames), $noDelay);
    }

    /**
     * {@inheritDoc}
     * @see ItemInterface::setExpireTime()
     */
    public function setExpireTime(int $timeInSeconds): void
    {
        $this->expireTime = $timeInSeconds <= 0 ? TCacheHelper::EXPIRE_DELAY : $timeInSeconds;
    }

    /**
     * {@inheritDoc}
     * @see ItemInterface::resetExpireTime()
     */
    public function resetExpireTime(): void
    {
        $this->expireTime = null;
    }
}