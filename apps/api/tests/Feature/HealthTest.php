<?php

it('returns ok from the health endpoint in the API envelope', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJson([
            'data' => ['status' => 'ok'],
            'meta' => [],
        ]);

    expect($response->json('data.db'))->toBeBool();
});

it('renders unknown API routes as the error envelope', function () {
    $response = $this->getJson('/api/v1/nope');

    $response->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    expect($response->json('error.request_id'))->toBeString()
        ->and($response->json('error'))->toHaveKeys(['code', 'message', 'details', 'request_id']);
});
