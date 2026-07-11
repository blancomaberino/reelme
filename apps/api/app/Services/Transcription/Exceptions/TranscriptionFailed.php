<?php

namespace App\Services\Transcription\Exceptions;

use RuntimeException;

/**
 * A transcriber could not produce a transcript (binary/host unavailable at call
 * time, non-zero exit, or an unparseable response). The manager tries the next
 * driver; when all are exhausted the job maps this to `transcribe_error`.
 */
class TranscriptionFailed extends RuntimeException {}
