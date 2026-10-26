<?php

declare(strict_types=1);

namespace raspberry\world\format\io;

use RuntimeException;

/**
 * A saved chunk could not be read: damaged file, wrong format version or wrong position.
 */
class CorruptChunkException extends RuntimeException{

}