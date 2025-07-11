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

use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

class MockRLoginServer
{
    private SocketServer $server;
    private int $port;
    private array $dataReceived = [];
    private string $dataToSend = "\x00Welcome!\n";

    public function __construct(?int $port = null)
    {
        $port ??= random_int(20000, 40000);
        $this->port = $port;

        $this->server = new SocketServer("127.0.0.1:{$port}", [], Loop::get());

        $this->server->on('connection', function (ConnectionInterface $conn) {
            $conn->on('data', function ($data) use ($conn) {
                $this->dataReceived[] = $data;

                // Send null byte at start of server response to indicate successful login
                $conn->write($this->dataToSend);
                $this->dataToSend = '';
            });
        });
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function close(): void
    {
        $this->server->close();
    }

    public function setDataToSend(string $dataToSend): void
    {
        $this->dataToSend = $dataToSend;
    }

    public function flushDataReceived(): void
    {
        $this->dataReceived = [];
    }

    public function getDataReceived(): array
    {
        return $this->dataReceived;
    }
}
