<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/** An error whose message is written for the person using the app. Any other exception is shown as a plain "try again". */
class UserFacingException extends RuntimeException {}
