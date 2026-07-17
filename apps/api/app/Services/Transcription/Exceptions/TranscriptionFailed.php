<?php

namespace App\Services\Transcription\Exceptions;

use RuntimeException;

/**
 * A transcriber could not produce a transcript (binary/host unavailable at call
 * time, non-zero exit, or an unparseable response). The manager tries the next
 * driver; when all are exhausted TranscribeAudio degrades to an empty transcript
 * and continues (transcription is best-effort). `transcribe_error` now marks only
 * a genuine infra failure of the transcribe stage, not an engine miss.
 */
class TranscriptionFailed extends RuntimeException {}
