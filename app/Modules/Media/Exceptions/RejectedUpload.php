<?php

declare(strict_types=1);

namespace App\Modules\Media\Exceptions;

use RuntimeException;

/**
 * An upload the store refused.
 *
 * A distinct type so a controller can turn it into a validation message
 * for the person uploading, rather than a 500 that tells them nothing.
 */
final class RejectedUpload extends RuntimeException {}
