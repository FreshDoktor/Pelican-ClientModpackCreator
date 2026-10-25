<?php

namespace FreshDoktor\MinecraftClientModpack\Exceptions;

use Exception;

/**
 * Thrown inside a build once it got cancelled or replaced by a newer one.
 */
class BuildCancelledException extends Exception {}
