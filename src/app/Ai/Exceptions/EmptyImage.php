<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/** The provider answered without an image: that is an error, never a success. */
class EmptyImage extends RuntimeException {}
