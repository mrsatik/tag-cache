<?php
declare(strict_types = 1);
namespace mrsatik\TCache\Item;

use Psr\Cache\CacheItemInterface;
use mrsatik\TCache\Exception\InvalidArgumentException;
use DateTimeInterface;
use DateTime;
use DateInterval;
use phpDocumentor\Reflection\Types\Integer;

class KeyValue implements CacheItemInterface, KeyValueInterface
{
    /**
     * @var string
     */
    private $key;

    /**
     * @var mixed
     */
    private $value;

    /**
     * @var int|null
     */
    private $expiredTime;

    /**
     * @var bool
     */
    private $isHit;

    /**
     * @var array
     */
    private $tags;

    public function __construct(string $key, $value, ?array $tags = [], ?int $expired = null, bool $isHit = false)
    {
        $this->key = $key;
        $this->validateValue($value);
        $this->value = $value;
        $this->expiredTime = $expired === null
            || \is_integer($expired) === false
            || $expired <= 0 ?
            null :
            ($expired + time());

        $this->isHit = (bool)$isHit;
        $this->tags = [];
        if($tags !== null) {
            foreach ($tags as $item) {
                if (
                    \is_string($item) === false
                    && \is_numeric($item) === false
                ) {
                    throw new InvalidArgumentException(\sprintf('Error tag name for cache key %s', $key));
                }
                $this->tags[] = $item;
            }
        }
    }

    public final function getKey(): string
    {
        return $this->key;
    }

    /**
     * {@inheritDoc}
     * @see \Psr\Cache\CacheItemInterface::get()
     */
    public final function get()
    {
        return $this->value;
    }

    /**
     * {@inheritDoc}
     * @see \Psr\Cache\CacheItemInterface::isHit()
     * @todo: доделать - если объект содержится в коллекции то тогда true, нет - false
     */
    public final function isHit()
    {
        $this->isHit;
    }

    /**
     * {@inheritDoc}
     * @see CacheItemInterface::set()
     */
    public final function set($value): CacheItemInterface
    {
        return new self($this->getKey(), $value, $this->getTags(), $this->expiredTime, false);
    }

    /**
     * {@inheritDoc}
     * @see CacheItemInterface::expiresAt()
     * @deprecated
     *  - если у вас есть желание вызвать этот метод, то или код с которым вы работаете неправильный, или вы написали говнокод
     */
    public final function expiresAt($expiration): CacheItemInterface
    {
        if ($expiration instanceof DateTimeInterface) {
            return new self($this->getKey(), $this->get(), $this->getTags(), (int)$expiration->format('U') - time(), false);
        } elseif ($expiration === null) {
            return new self($this->getKey(), $this->get(), $this->getTags(), null, false);
        }

        $class = \get_class($this);
        $type  = \gettype($expiration);
        $error = \sprintf('Argument 1 passed to %s::expiresAt()  must be an '.
            'instance of DateTime or DateTimeImmutable, %s given', $class, $type);

        throw new InvalidArgumentException($error);
    }

    /**
     * {@inheritDoc}
     * @see CacheItemInterface::expiresAfter()
     * @deprecated
     *  - если у вас есть желание вызвать этот метод, то или код с которым вы работаете неправильный, или вы написали говнокод
     */
    public final function expiresAfter($time): CacheItemInterface
    {
        if ($time instanceof DateInterval) {
            $expire = new DateTime();
            $expire->add($time);
            return new self($this->getKey(), $this->get(), $this->getTags(), (int)$expire->format('U') - time(), false);
        } elseif (\is_int($time) === true) {
            return new self($this->getKey(), $this->get(), $this->getTags(), $time, false);
        } elseif (\is_null($time) === true) {
            return new self($this->getKey(), $this->get(), $this->getTags(), null, false);
        }

        throw new InvalidArgumentException(
            'Invalid time: ' . serialize($time) . '. Must be integer or '.
            'instance of DateInterval.'
        );
    }

    /**
     * {@inheritDoc}
     * @see KeyValueInterface::getExpiredTime()
     */
    public final function getExpiredTime(): ?int
    {
        return $this->expiredTime;
    }

    public function addTag(string $tagName): CacheItemInterface
    {
        $tags = $this->getTags();
        $tags[] = $tagName;
        return new self($this->getKey(), $this->get(), $tags, $this->getExpiredTime(), false);
    }

    public function addTags(array $tagsNew): CacheItemInterface
    {
        $tags = $this->getTags();
        $tags = $tags + $tagsNew;
        return new self($this->getKey(), $this->get(), $tags, $this->getExpiredTime(), false);
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param mixed $value
     * @throws InvalidArgumentException
     */
    private function validateValue($value): void
    {
        if ($value === null) {
            throw new InvalidArgumentException('Value of cache can not be null');
        }
    }
}