<?php

use App\Services\CloudflareTunnelService;

const SSH_RULE = ['hostname' => 'ssh.example.com', 'service' => 'ssh://localhost:22'];

it('adds the ssh rule first and keeps the catch-all last', function () {
    expect(CloudflareTunnelService::ingressWith([
        ['hostname' => 'app.example.com', 'service' => 'http://localhost:3000'],
        ['service' => 'http_status:404'],
    ], SSH_RULE))->toBe([
        SSH_RULE,
        ['hostname' => 'app.example.com', 'service' => 'http://localhost:3000'],
        ['service' => 'http_status:404'],
    ]);
});

it('adds a catch-all when the tunnel has none', function () {
    expect(CloudflareTunnelService::ingressWith([], SSH_RULE))->toBe([SSH_RULE, ['service' => 'http_status:404']]);
});

it('keeps path-only rules in the middle and replaces an older rule for the same hostname', function () {
    expect(CloudflareTunnelService::ingressWith([
        ['hostname' => 'ssh.example.com', 'service' => 'ssh://localhost:2222'],
        ['path' => '/api', 'service' => 'http://localhost:8080'],
        'junk',
        ['hostname' => 'ssh.example.com', 'service' => 'ssh://localhost:22'],
        ['service' => 'http://localhost:80'],
    ], SSH_RULE))->toBe([
        SSH_RULE,
        ['path' => '/api', 'service' => 'http://localhost:8080'],
        ['service' => 'http://localhost:80'],
    ]);
});

it('removes only the given hostnames when leaving a tunnel', function () {
    expect(CloudflareTunnelService::ingressWithout([
        ['hostname' => 'ssh.example.com', 'service' => 'ssh://localhost:22'],
        ['hostname' => 'app.example.com', 'service' => 'http://localhost:3000'],
        ['service' => 'http_status:404'],
    ], ['ssh.example.com']))->toBe([
        ['hostname' => 'app.example.com', 'service' => 'http://localhost:3000'],
        ['service' => 'http_status:404'],
    ]);
});
