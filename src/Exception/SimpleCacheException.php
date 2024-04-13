<?php
declare(strict_types=1);

namespace mrsatik\TCache\Exception;

use Exception;
use Psr\SimpleCache\CacheException;

class SimpleCacheException extends Exception implements CacheException {}
