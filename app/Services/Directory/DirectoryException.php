<?php

namespace App\Services\Directory;

/** A problem talking to the directory bridge; the message is safe to show to an administrator. */
class DirectoryException extends \RuntimeException {}
