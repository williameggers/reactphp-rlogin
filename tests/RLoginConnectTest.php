<?php declare(strict_types=1);
/**
 * Copyright (c) 2025, William Eggers
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice, this
 *    list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

use function React\Async\await;

use React\EventLoop\Loop;
use React\Promise\Promise;
use WilliamEggers\React\RLogin\Connection;
use WilliamEggers\React\RLogin\RLogin;

require_once __DIR__ . '/helpers/MockRLoginServer.php';

function delay(float $seconds): Promise
{
    return new Promise(fn ($resolve) => Loop::addTimer($seconds, $resolve));
}

beforeAll(function (): void {
    Loop::run();
});

afterAll(function (): void {
    Loop::stop();
});

test('RLogin connects and emits connect event', function (): void {
    $server = new MockRLoginServer();
    $port = $server->getPort();

    $rlogin = new RLogin([
        'host' => '127.0.0.1',
        'port' => $port,
        'clientUsername' => 'user1',
        'serverUsername' => 'user2',
        'terminalType' => 'vt100',
        'terminalSpeed' => 9600,
    ]);

    $connected = null;
    $dataReceived = null;

    $rlogin->connect()->then(function (Connection $connection) use (&$connected, &$dataReceived): void {
        $connected = true;
        expect($connection->isConnected())->toBeTrue();

        $connection->on('data', function ($data) use (&$dataReceived): void {
            $dataReceived = $data;
        });
    });

    await(delay(0.5)); // Allow event loop to process async

    $nul = chr(0);
    expect($server->getDataReceived()[0])->toBe($nul . 'user1' . $nul . 'user2' . $nul . 'vt100/9600' . $nul);
    expect($connected)->toBeTrue();
    expect($dataReceived)->toContain('Welcome');

    $server->close();
});

test('RLogin disconnects and emits close event', function (): void {
    $server = new MockRLoginServer();
    $port = $server->getPort();

    $rlogin = new RLogin([
        'host' => '127.0.0.1',
        'port' => $port,
        'clientUsername' => 'user1',
        'serverUsername' => 'user2',
        'terminalType' => 'vt100',
        'terminalSpeed' => 9600,
    ]);

    $disconnected = false;

    $rlogin->connect()->then(function (Connection $connection) use (&$disconnected): void {
        $connection->on('close', function () use (&$disconnected): void {
            $disconnected = true;
        });

        $connection->disconnect();
    });

    await(delay(0.5));

    expect($disconnected)->toBeTrue();

    $server->close();
});

test('RLogin connects and triggers sendWCCS', function (): void {
    $server = new MockRLoginServer();
    $port = $server->getPort();

    $rlogin = new RLogin([
        'host' => '127.0.0.1',
        'port' => $port,
        'clientUsername' => 'user1',
        'serverUsername' => 'user2',
        'terminalType' => 'vt100',
        'terminalSpeed' => 9600,
    ]);

    $connected = null;
    $dataReceived = null;

    $rlogin->connect()->then(function (Connection $connection) use (&$connected, &$dataReceived, $server): void {
        $connected = true;
        expect($connection->isConnected())->toBeTrue();
        $connection->on('data', function ($data) use (&$dataReceived): void {
            $dataReceived = $data;
        });
        $server->flushDataReceived();
        $connection->sendWCCS();
    });

    await(delay(0.5)); // Allow event loop to process async

    expect(array_values(unpack('v4*', substr((string) $server->getDataReceived()[0], 4))))->toBe([24, 80, 640, 480]);
    expect($connected)->toBeTrue();
    expect($dataReceived)->toContain('Welcome');

    $server->close();
});

test('RLogin disconnected and triggers sendWCCS', function (): void {
    $server = new MockRLoginServer();
    $port = $server->getPort();

    $rlogin = new RLogin([
        'host' => '127.0.0.1',
        'port' => $port,
        'clientUsername' => 'user1',
        'serverUsername' => 'user2',
        'terminalType' => 'vt100',
        'terminalSpeed' => 9600,
    ]);

    $rlogin->connect()->then(function (Connection $connection): void {
        $connection->disconnect();
        $connection->sendWCCS();
    })->catch(function (Throwable $e): void {
        expect($e->getMessage())->toBe('RLogin client not connected');
    });

    await(delay(0.5));
});

test('RLogin disconnects via setConnected and emits disconnect event', function (): void {
    $server = new MockRLoginServer();
    $port = $server->getPort();

    $rlogin = new RLogin([
        'host' => '127.0.0.1',
        'port' => $port,
        'clientUsername' => 'user1',
        'serverUsername' => 'user2',
        'terminalType' => 'vt100',
        'terminalSpeed' => 9600,
    ]);

    $disconnected = false;
    $rlogin->connect()->then(function (Connection $connection) use (&$disconnected): void {
        $connection->on('close', function () use (&$disconnected): void {
            $disconnected = true;
        });
        $connection->setConnected(false);
    });

    await(delay(0.5));

    expect($disconnected)->toBeTrue();

    $server->close();
});

test('RLogin client sends WCCS packet on request from server', function (): void {
    $server = new MockRLoginServer();
    $port = $server->getPort();
    $server->setDataToSend("\x00\x80");

    $rlogin = new RLogin([
        'host' => '127.0.0.1',
        'port' => $port,
        'clientUsername' => 'user1',
        'serverUsername' => 'user2',
        'terminalType' => 'vt100',
        'terminalSpeed' => 9600,
    ]);

    $rlogin->connect();

    await(delay(0.5));
    expect(array_values(unpack('v4*', substr((string) $server->getDataReceived()[1], 4))))->toBe([24, 80, 640, 480]);
    $server->close();
});

// test('addClientEscape with valid char registers callback', function () {
//     $rlogin = new RLogin($this->validOptions);
//     $called = false;

//     $rlogin->addClientEscape('.', function () use (&$called) {
//         $called = true;
//     });

//     // Use reflection to force state['connected'] = true
//     $refClass = new ReflectionClass($rlogin);
//     $stateProp = $refClass->getProperty('state');
//     $stateProp->setAccessible(true);
//     $state = $stateProp->getValue($rlogin);
//     $state['connected'] = true;
//     $stateProp->setValue($rlogin, $state);

//     // Simulate triggering client escape
//     $method = new ReflectionMethod($rlogin, 'send');
//     $method->setAccessible(true);
//     $method->invoke($rlogin, '~.'); // ~ triggers escape, . should call the callback

//     expect($called)->toBeTrue();
// });

test('addClientEscape with invalid client escape string', function (): void {
    $server = new MockRLoginServer();
    $port = $server->getPort();
    $rlogin = new RLogin([
        'host' => '127.0.0.1',
        'port' => $port,
        'clientUsername' => 'user1',
        'serverUsername' => 'user2',
        'terminalType' => 'vt100',
        'terminalSpeed' => 9600,
    ]);
    $called = false;

    $rlogin->connect()->then(function (Connection $connection) use (&$called): void {
        $connection->addClientEscape('....', function () use (&$called): void {
            $called = true;
        });
    })->catch(function (Throwable $e): void {
        expect($e->getMessage())->toBe('addClientEscape: invalid string argument');
    });

    await(delay(0.5));

    expect($called)->toBeFalse();
});

test('RLogin client sets raw mode', function (): void {
    $server = new MockRLoginServer();
    $port = $server->getPort();
    $server->setDataToSend("\x00Begin\x10Start\x11Stop\x13End");

    $rlogin = new RLogin([
        'host' => '127.0.0.1',
        'port' => $port,
        'clientUsername' => 'user1',
        'serverUsername' => 'user2',
        'terminalType' => 'vt100',
        'terminalSpeed' => 9600,
    ]);

    $isCooked = null;
    $dataReceived = null;
    $rlogin->connect()->then(function (Connection $connection) use (&$isCooked, &$dataReceived): void {
        $connection->on('data', function (string $data) use (&$dataReceived): void {
            $dataReceived .= $data;
        });
        // Delay for a little to allow the server to send the RAW byte
        delay(0.1)->then(function () use ($connection, &$isCooked): void {
            $isCooked = $connection->isCooked();
        });
    });

    await(delay(0.5));
    expect($isCooked)->toBeFalse();
    expect($dataReceived)->toBe("BeginStart\x11Stop\x13End");

    $server->close();
});

test('RLogin client sets cooked mode', function (): void {
    $server = new MockRLoginServer();
    $port = $server->getPort();
    $server->setDataToSend("\x00Begin\x11Start\x13Stop\x11End");

    $rlogin = new RLogin([
        'host' => '127.0.0.1',
        'port' => $port,
        'clientUsername' => 'user1',
        'serverUsername' => 'user2',
        'terminalType' => 'vt100',
        'terminalSpeed' => 9600,
    ]);

    $isCooked = null;
    $dataReceived = null;
    $rlogin->connect()->then(function (Connection $connection) use (&$isCooked, &$dataReceived): void {
        $connection->on('data', function (string $data) use (&$dataReceived): void {
            $dataReceived .= $data;
        });
        // Delay for a little to allow the server to send the RAW byte
        delay(0.1)->then(function () use ($connection, &$isCooked): void {
            $isCooked = $connection->isCooked();
        });
    });

    await(delay(0.5));
    expect($isCooked)->toBeTrue();
    expect($dataReceived)->toBe('BeginStartStopEnd');

    $server->close();
});

test('setting and getting properties works', function (): void {
    $server = new MockRLoginServer();
    $port = $server->getPort();

    $rlogin = new RLogin([
        'host' => '127.0.0.1',
        'port' => $port,
        'clientUsername' => 'user1',
        'serverUsername' => 'user2',
        'terminalType' => 'vt100',
        'terminalSpeed' => 9600,
    ]);

    $rlogin->connect()->then(function (Connection $connection): void {
        $connection->rows = 30;
        $connection->columns = 100;
        $connection->pixelsX = 1024;
        $connection->pixelsY = 768;
        $connection->clientEscape = '!';

        expect($connection->rows)->toBe(30);
        expect($connection->columns)->toBe(100);
        expect($connection->pixelsX)->toBe(1024);
        expect($connection->pixelsY)->toBe(768);
        expect($connection->clientEscape)->toBe('!');
    });

    await(delay(0.5));
});

test('setting invalid properties ', function (): void {
    $server = new MockRLoginServer();
    $port = $server->getPort();

    $rlogin = new RLogin([
        'host' => '127.0.0.1',
        'port' => $port,
        'clientUsername' => 'user1',
        'serverUsername' => 'user2',
        'terminalType' => 'vt100',
        'terminalSpeed' => 9600,
    ]);

    $rlogin->connect()->then(function (Connection $connection): void {
        $connection->invalidProperty = 30;
    })->catch(function (Throwable $e): void {
        expect($e->getMessage())->toBe("Invalid property: 'invalidProperty'");
    });

    await(delay(0.5));
});

test('rlogin write on disconnect', function (): void {
    $server = new MockRLoginServer();
    $port = $server->getPort();

    $rlogin = new RLogin([
        'host' => '127.0.0.1',
        'port' => $port,
        'clientUsername' => 'user1',
        'serverUsername' => 'user2',
        'terminalType' => 'vt100',
        'terminalSpeed' => 9600,
    ]);

    $rlogin->connect()->then(function (Connection $connection): void {
        $connection->disconnect();
        $connection->write('Hello');
    })->catch(function (Throwable $e): void {
        expect($e->getMessage())->toBe('RLogin client not connected');
    });

    await(delay(0.5));
});

test('rlogin test client escape disconnect', function (): void {
    $server = new MockRLoginServer();
    $port = $server->getPort();

    $rlogin = new RLogin([
        'host' => '127.0.0.1',
        'port' => $port,
        'clientUsername' => 'user1',
        'serverUsername' => 'user2',
        'terminalType' => 'vt100',
        'terminalSpeed' => 9600,
    ]);

    $closed = false;
    $rlogin->connect()->then(function (Connection $connection) use (&$closed, $server): void {
        $connection->on('close', function () use (&$closed): void {
            $closed = true;
        });
        $server->flushDataReceived();
        $connection->write('Hello');
        $connection->write("World~\x2E");
    });

    await(delay(0.5));

    expect($server->getDataReceived()[0])->toBe('HelloWorld');
    expect($closed)->toBeTrue();
});

test('addClientEscape with custom client escape string', function (): void {
    $server = new MockRLoginServer();
    $port = $server->getPort();
    $rlogin = new RLogin([
        'host' => '127.0.0.1',
        'port' => $port,
        'clientUsername' => 'user1',
        'serverUsername' => 'user2',
        'terminalType' => 'vt100',
        'terminalSpeed' => 9600,
    ]);
    $called = false;

    $rlogin->connect()->then(function (Connection $connection) use (&$called): void {
        $connection->addClientEscape('*', function () use (&$called): void {
            $called = true;
        });
        $connection->write("Hello~*");
    });

    await(delay(0.5));

    expect($called)->toBeTrue();
});
