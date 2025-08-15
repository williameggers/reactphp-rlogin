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

namespace WilliamEggers\React\RLogin;

use Evenement\EventEmitterTrait;
use React\Socket\ConnectionInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;

final class Connection implements ConnectionInterface
{
    use EventEmitterTrait;

    private const CAN = 0x18;

    private const CR = 0x0D;

    // START
    private const DC1 = 0x11;

    // STOP
    private const DC3 = 0x13;

    private const DOT = 0x2E;

    private const EOM = 0x19;

    private const EOT = 0x04;

    private const LF = 0x0A;

    private const SUB = 0x1A;

    // A control byte of hex 02 causes the client to discard all buffered
    // data received from the server that has not yet been written to the
    // client user's screen.
    private const DISCARD = 0x02;

    // A control byte of hex 10 commands the client to switch to "raw"
    // mode, where the START and STOP characters are no longer handled by
    // the client, but are instead treated as plain data.
    private const RAW = 0x10;

    // A control byte of hex 20 commands the client to resume interception
    // and local processing of START and STOP flow control characters.
    private const COOKED = 0x20;

    // The client responds by sending the current window size.
    private const WINDOW = 0x80;

    /** @var array<string, bool> */
    private array $state = [
        'connected' => false,
        // Initially, the client begins operation in "cooked" (as opposed to to "raw") mode.
        'cooked' => true,
        'suspendInput' => false,
        'suspendOutput' => false,
        'watchForClientEscape' => true,
        'clientHasEscaped' => false,
    ];

    /** @var array<int, callable> */
    private array $clientEscapes = [];

    /**
     * RLogin Connection.
     *
     * @param array<string,float|int|string> $properties
     */
    public function __construct(private ConnectionInterface $connection, private array $properties)
    {
        // Client escape handlers
        // As suggested by RFC1282
        $this->clientEscapes = [
            self::DOT => fn () => $this->disconnect(),
            self::EOT => fn () => $this->disconnect(),
            self::SUB => function (): void {
                $this->state['suspendInput'] = ! $this->state['suspendInput'];
                $this->state['suspendOutput'] = $this->state['suspendInput'];
            },
            self::EOM => function (): void {
                $this->state['suspendInput'] = ! $this->state['suspendInput'];
                $this->state['suspendOutput'] = false;
            },
        ];

        $this->connection->on('data', fn (string $data) => $this->handleData($data));
        $this->connection->on('error', fn (\Throwable $e) => $this->emit('error', [$e]));
        $this->connection->on('close', fn () => $this->handleDisconnect());
        $this->connection->on('end', fn () => $this->handleDisconnect());
    }

    public function __get(string $name): mixed
    {
        return $this->properties[$name] ?? null;
    }

    public function __set(string $name, float|int|string $value): void
    {
        if (! in_array($name, ['rows', 'columns', 'pixelsX', 'pixelsY', 'clientEscape'])) {
            throw new \InvalidArgumentException(sprintf("Invalid property: '%s'", $name));
        }

        if (in_array($name, ['rows', 'columns', 'pixelsX', 'pixelsY'], true)) {
            if (! is_int($value) || $value <= 0) {
                throw new \InvalidArgumentException(sprintf("Invalid '%s' setting %s", $name, $value));
            }
        } elseif ('clientEscape' === $name) {
            if (! is_string($value) || 1 !== strlen($value)) {
                throw new \InvalidArgumentException('Invalid \'clientEscape\' setting ' . $value);
            }
        }

        $this->properties[$name] = $value;
    }

    /**
     * Returns the full remote address (URI) where this connection has been established with.
     *
     * ```php
     * $address = $connection->getRemoteAddress();
     * echo 'Connection with ' . $address . PHP_EOL;
     * ```
     *
     * If the remote address can not be determined or is unknown at this time (such as
     * after the connection has been closed), it MAY return a `NULL` value instead.
     *
     * Otherwise, it will return the full address (URI) as a string value, such
     * as `tcp://127.0.0.1:8080`, `tcp://[::1]:80`, `tls://127.0.0.1:443`,
     * `unix://example.sock` or `unix:///path/to/example.sock`.
     * Note that individual URI components are application specific and depend
     * on the underlying transport protocol.
     *
     * If this is a TCP/IP based connection and you only want the remote IP, you may
     * use something like this:
     *
     * ```php
     * $address = $connection->getRemoteAddress();
     * $ip = trim(parse_url($address, PHP_URL_HOST), '[]');
     * echo 'Connection with ' . $ip . PHP_EOL;
     * ```
     *
     * @return ?string remote address (URI) or null if unknown
     */
    public function getRemoteAddress(): ?string
    {
        return $this->connection->getRemoteAddress();
    }

    /**
     * Returns the full local address (full URI with scheme, IP and port) where this connection has been established with.
     *
     * ```php
     * $address = $connection->getLocalAddress();
     * echo 'Connection with ' . $address . PHP_EOL;
     * ```
     *
     * If the local address can not be determined or is unknown at this time (such as
     * after the connection has been closed), it MAY return a `NULL` value instead.
     *
     * Otherwise, it will return the full address (URI) as a string value, such
     * as `tcp://127.0.0.1:8080`, `tcp://[::1]:80`, `tls://127.0.0.1:443`,
     * `unix://example.sock` or `unix:///path/to/example.sock`.
     * Note that individual URI components are application specific and depend
     * on the underlying transport protocol.
     *
     * This method complements the [`getRemoteAddress()`](#getremoteaddress) method,
     * so they should not be confused.
     *
     * If your `TcpServer` instance is listening on multiple interfaces (e.g. using
     * the address `0.0.0.0`), you can use this method to find out which interface
     * actually accepted this connection (such as a public or local interface).
     *
     * If your system has multiple interfaces (e.g. a WAN and a LAN interface),
     * you can use this method to find out which interface was actually
     * used for this connection.
     *
     * @return ?string local address (URI) or null if unknown
     *
     * @see self::getRemoteAddress()
     */
    public function getLocalAddress(): ?string
    {
        return $this->connection->getLocalAddress();
    }

    public function isReadable(): bool
    {
        return $this->connection->isReadable() && ! $this->state['suspendOutput'];
    }

    public function isWritable(): bool
    {
        return $this->connection->isWritable() && ! $this->state['suspendInput'];
    }

    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        return Util::pipe($this, $dest, $options);
    }

    public function pause(): void
    {
        $this->connection->pause();
    }

    public function resume(): void
    {
        $this->connection->resume();
    }

    public function end(mixed $data = null): void
    {
        $this->send($data, 'end');
    }

    public function close(): void
    {
        $this->disconnect();
    }

    public function isConnected(): bool
    {
        return $this->state['connected'] ?? false;
    }

    public function isCooked(): bool
    {
        return $this->state['cooked'] ?? false;
    }

    public function addClientEscape(int|string $ch, callable $callback): self
    {
        if (is_string($ch)) {
            if (1 !== strlen($ch)) {
                throw new \InvalidArgumentException('addClientEscape: invalid string argument');
            }

            $ch = ord($ch);
        }

        $this->clientEscapes[$ch] = $callback;

        return $this;
    }

    public function sendWCCS(): self
    {
        if (! $this->state['connected']) {
            throw new \RuntimeException('RLogin client not connected');
        }

        $cookie = "\xFF\xFF\x73\x73";
        $rcxy = pack(
            'v4',
            $this->properties['rows'],
            $this->properties['columns'],
            $this->properties['pixelsX'],
            $this->properties['pixelsY']
        );

        $this->connection->write($cookie . $rcxy);

        return $this;
    }

    public function disconnect(): void
    {
        $this->connection->end();
        $this->handleDisconnect();
    }

    public function rawWrite(mixed $data): bool
    {
        return $this->connection->write($data);
    }

    public function write(mixed $data): bool
    {
        return $this->send($data);
    }

    public function setConnected(bool $value): void
    {
        if (! $value) {
            $this->disconnect();
        }
    }

    private function send(mixed $data, string $mode = 'write'): bool
    {
        if (! $this->state['connected']) {
            throw new \RuntimeException('RLogin client not connected');
        }

        if ($this->state['suspendInput']) {
            throw new \RuntimeException('RLogin.send: input has been suspended.');
        }

        if (! is_scalar($data) && ! (\is_object($data) && method_exists($data, '__toString'))) {
            throw new \InvalidArgumentException('Data must be stringable');
        }

        $bytes = array_map('ord', str_split((string) $data));
        $temp = [];

        foreach ($bytes as $i => $byte) {
            if ($this->state['watchForClientEscape'] && $byte === ord((string) $this->properties['clientEscape'])) {
                $this->state['watchForClientEscape'] = false;
                $this->state['clientHasEscaped'] = true;

                continue;
            }

            if ($this->state['clientHasEscaped']) {
                $this->state['clientHasEscaped'] = false;
                if (isset($this->clientEscapes[$byte])) {
                    if (! $this->state['suspendInput']) {
                        $this->connection->{$mode}(implode('', $temp));
                    }

                    ($this->clientEscapes[$byte])();
                }

                continue;
            }

            if ($this->state['cooked'] && (self::DC1 === $byte || self::DC3 === $byte)) {
                $this->state['suspendOutput'] = (self::DC3 === $byte);

                continue;
            }

            if (($i > 0 && self::CR === $bytes[$i - 1] && self::LF === $byte) || self::CAN === $byte) {
                $this->state['watchForClientEscape'] = true;
            }

            $temp[] = chr($byte);
        }

        if (! $this->state['suspendInput']) {
            $this->connection->{$mode}(implode('', $temp));
        }

        return true;
    }

    private function handleData(string $data): void
    {
        /**
         * Initial connection negotiation (first byte = 0x00 = successful connect).
         *
         * From RFC-1282:
         *
         *   "Upon connection establishment, the client sends four null-terminated
         *.   strings to the server."
         *
         *   "The server returns a zero byte to indicate that it has received these
         *    strings and is now in data transfer mode.  Window size negotiation
         *    may follow this initial exchange"
         */
        if (! $this->state['connected']) {
            if (0 === ord($data[0])) {
                // Indicate we're now in data transfer mode
                $this->state['connected'] = true;
                $this->emit('connection-established');

                if (strlen($data) > 1) {
                    $data = substr($data, 1);
                } else {
                    return;
                }
            } else {
                $this->disconnect();

                return;
            }
        }

        /**
         * Byte-by-byte scan and processing.
         *
         * @var array<int> $bytes
         */
        $bytes = array_values(unpack('C*', $data) ?: []);
        $temp = [];

        for ($i = 0, $len = count($bytes); $i < $len; ++$i) {
            $byte = $bytes[$i];

            // Simulate TCP "urgent" byte interpretation
            switch ($byte) {
                case self::DISCARD: // 0x02
                    $temp = [];

                    continue 2;

                case self::RAW: // 0x10
                    if ($this->state['cooked']) {
                        $this->state['cooked'] = false;
                        $this->state['suspendOutput'] = false;

                        continue 2;
                    }

                    break;

                case self::COOKED: // 0x20
                    if (! $this->state['cooked']) {
                        $this->state['cooked'] = true;

                        continue 2;
                    }

                    break;

                case self::WINDOW: // 0x80
                    $this->sendWCCS();

                    continue 2;
            }

            // Client escape handling (~)
            if (
                $this->state['watchForClientEscape']
                && $byte === ord((string) $this->properties['clientEscape'])
            ) {
                $this->state['watchForClientEscape'] = false;
                $this->state['clientHasEscaped'] = true;

                continue;
            }

            if ($this->state['clientHasEscaped']) {
                $this->state['clientHasEscaped'] = false;

                if (isset($this->clientEscapes[$byte])) {
                    ($this->clientEscapes[$byte])(); // Call mapped escape function
                }

                continue;
            }

            // XON/XOFF cooked mode filtering
            if ($this->state['cooked'] && (self::DC1 === $byte || self::DC3 === $byte)) {
                $this->state['suspendOutput'] = (self::DC3 === $byte);

                continue;
            }

            // Reset escape watch mode if we see newline or cancel
            if (
                ($i > 0 && self::CR === $bytes[$i - 1] && self::LF === $byte)
                || self::CAN === $byte
            ) {
                $this->state['watchForClientEscape'] = true;
            }

            $temp[] = chr($byte);
        }

        if (! $this->state['suspendOutput']) {
            $this->emit('data', [implode('', $temp)]);
        }
    }

    private function handleDisconnect(): void
    {
        if (! $this->state['connected']) {
            return;
        }

        $this->state['connected'] = false;
        $this->emit('close');
    }
}
