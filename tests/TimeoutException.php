<?php

namespace ProjectSaturnStudios\Forte\Tests;

use RuntimeException;

/** await() ran the loop past its deadline without the promise settling. */
final class TimeoutException extends RuntimeException {}
