<?php

namespace App\Providers;

use App\Services\Transcription\HostedTranscriber;
use App\Services\Transcription\Transcriber;
use App\Services\Transcription\TranscriptionManager;
use App\Services\Transcription\WhisperCppTranscriber;
use Illuminate\Support\ServiceProvider;

class TranscriptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HostedTranscriber::class);

        // Primary driver, chosen by config('transcription.driver'). Tests swap
        // this binding for a FakeTranscriber via app()->instance().
        $this->app->singleton(Transcriber::class, function (): Transcriber {
            return match ((string) config('transcription.driver')) {
                default => new WhisperCppTranscriber,
            };
        });

        $this->app->singleton(TranscriptionManager::class, fn ($app) => new TranscriptionManager(
            primary: $app->make(Transcriber::class),
            hosted: $app->make(HostedTranscriber::class),
        ));
    }
}
