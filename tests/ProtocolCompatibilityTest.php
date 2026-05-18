<?php

declare(strict_types=1);

require __DIR__ . '/../src/MCP/Protocol.php';

use Yangmingzhi\ThinkphpBoost\MCP\Protocol;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

foreach (Protocol::SUPPORTED_PROTOCOL_VERSIONS as $version) {
    $response = Protocol::buildInitializeResponse(1, '', $version);
    assertSameValue($version, $response['result']['protocolVersion'], "initialize negotiates {$version}");
}

$fallback = Protocol::buildInitializeResponse(1, '', '2099-01-01');
assertSameValue(Protocol::PROTOCOL_VERSION, $fallback['result']['protocolVersion'], 'unknown protocol falls back to latest supported version');

$request = Protocol::decode('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25"}}');
assertSameValue(false, $request['isNotification'], 'initialize request is not a notification');
assertSameValue(1, $request['id'], 'initialize request id is preserved');

$initialized = Protocol::decode('{"jsonrpc":"2.0","method":"notifications/initialized"}');
assertSameValue(true, $initialized['isNotification'], 'initialized message is a notification');
assertSameValue(null, $initialized['id'], 'notification id is null when omitted');

$cancelled = Protocol::decode('{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":1,"reason":"test"}}');
assertSameValue(Protocol::METHOD_CANCEL, $cancelled['method'], 'new cancellation notification is recognized');
assertSameValue(true, $cancelled['isNotification'], 'new cancellation is a notification');

$legacyCancel = Protocol::decode('{"jsonrpc":"2.0","method":"$/cancelRequest","params":{"id":1}}');
assertSameValue(Protocol::METHOD_LEGACY_CANCEL, $legacyCancel['method'], 'legacy cancellation notification is preserved');
assertSameValue(true, $legacyCancel['isNotification'], 'legacy cancellation is a notification');

echo "Protocol compatibility checks passed.\n";
