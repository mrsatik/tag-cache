<?php
declare(strict_types=1);

namespace mrsatik\TCache\Exception;

use Exception;
use Psr\Cache\InvalidArgumentException as IInvalidArgumentException;

class InvalidArgumentException extends Exception implements IInvalidArgumentException {}
