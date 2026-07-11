<?php

namespace App\Services\Transcription;

use App\Services\Transcription\Data\TranscriptionResult;
use App\Services\Transcription\Exceptions\TranscriptionFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Hosted fallback over an OpenAI-compatible /v1/audio/transcriptions endpoint
 * (multipart, verbose_json for segments). Sends user audio to a third party, so
 * it is disabled by default and gated on `transcription.hosted.enabled`.
 */
class HostedTranscriber implements Transcriber
{
    public function isAvailable(): bool
    {
        return (bool) config('transcription.hosted.enabled')
            && (string) config('transcription.hosted.api_key') !== '';
    }

    public function transcribe(string $wavPath): TranscriptionResult
    {
        $contents = @file_get_contents($wavPath);
        if ($contents === false) {
            throw new TranscriptionFailed("Cannot read audio at {$wavPath}.");
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('transcription.hosted.base_url'), '/'))
                ->withToken((string) config('transcription.hosted.api_key'))
                ->timeout((int) config('transcription.hosted.timeout', 300))
                ->attach('file', $contents, 'audio.wav')
                ->post('/audio/transcriptions', [
                    'model' => (string) config('transcription.hosted.model', 'whisper-1'),
                    'response_format' => 'verbose_json',
                ]);
        } catch (ConnectionException $e) {
            throw new TranscriptionFailed('Hosted transcription unreachable: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw new TranscriptionFailed('Hosted transcription returned HTTP '.$response->status());
        }

        return $this->parse($response->json() ?? []);
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function parse(array $json): TranscriptionResult
    {
        $text = trim((string) ($json['text'] ?? ''));
        $segments = [];

        foreach ($json['segments'] ?? [] as $seg) {
            $segments[] = [
                'start_ms' => (int) round(((float) ($seg['start'] ?? 0)) * 1000),
                'end_ms' => (int) round(((float) ($seg['end'] ?? 0)) * 1000),
                'text' => trim((string) ($seg['text'] ?? '')),
            ];
        }

        if ($text === '') {
            return TranscriptionResult::empty('hosted');
        }

        return new TranscriptionResult(
            language: isset($json['language']) ? (string) $json['language'] : null,
            text: $text,
            segments: $segments,
            driver: 'hosted',
        );
    }
}
