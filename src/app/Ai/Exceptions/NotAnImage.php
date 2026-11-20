<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/** The provider returned bytes that are not an image: the icon fails and the emoji stays, instead of storing a broken file. */
class NotAnImage extends RuntimeException {}
