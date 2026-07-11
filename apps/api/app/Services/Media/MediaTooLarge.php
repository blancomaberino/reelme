<?php

namespace App\Services\Media;

use RuntimeException;

/**
 * The source media exceeds a hard cap (500 MB or 15 min). A permanent failure —
 * DownloadMedia fails the share `media_too_large` without retrying.
 */
class MediaTooLarge extends RuntimeException {}
