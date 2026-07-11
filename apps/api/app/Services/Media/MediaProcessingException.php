<?php

namespace App\Services\Media;

use RuntimeException;

/**
 * An ffmpeg/ffprobe invocation failed (non-zero exit) or produced no usable
 * output. PrepareMedia lets this propagate so its `failed()` hook maps the share
 * to `ffmpeg_error`.
 */
class MediaProcessingException extends RuntimeException {}
