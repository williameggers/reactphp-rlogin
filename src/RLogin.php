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

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\Timer\timeout;

use React\Socket\ConnectionInterface;
use React\Socket\TcpConnector;

/**
 * RLogin client implementation for ReactPHP.
 *
 * This class provides an asynchronous client for connecting to RLogin servers,
 * following the RLogin protocol (RFC1282). It supports client escape sequences,
 * terminal window size negotiation, and stream suspension controls.
 *
 * Usage:
 * $client = new RLogin([
 *     'host' => 'example.com',
 *     'port' => 513,
 *     'clientUsername' => 'localuser',
 *     'serverUsername' => 'remoteuser',
 *     'terminalType' => 'vt100',
 *     'terminalSpeed' => 9600,
 * ]);
 *
 * $client->on('connect', function (bool $success) {
 *     echo $success ? "Connected!\n" : "Failed to connect.\n";
 * });
 *
 * $client->connect();
 */
final class RLogin implements EventEmitterInterface
{
    use EventEmitterTrait;

    private TcpConnector $connector;

    /** @var array{host:string,port:int,clientUsername:string,serverUsername:string,terminalType:string,terminalSpeed:int} */
    private array $options;

    /** @var array<string,float|int|string> */
    private array $properties;

    /**
     * RLogin client constructor.
     *
     * Initializes the client with RLogin protocol options and sets up the event loop and socket context.
     *
     * @param array{
     *     host: string,
     *     port: int,
     *     clientUsername: string,
     *     serverUsername: string,
     *     terminalType: string,
     *     terminalSpeed: int
     * } $options   Configuration options required to initiate the RLogin connection
     * @param null|LoopInterface $loop    Optional ReactPHP event loop instance. If not provided, the default loop is used.
     * @param array              $context Optional context array passed to the underlying TcpConnector (e.g., TLS options).
     */
    public function __construct(array $options, ?LoopInterface $loop = null, array $context = [])
    {
        $requiredFields = [
            'host' => 'string',
            'port' => 'integer',
            'clientUsername' => 'string',
            'serverUsername' => 'string',
            'terminalType' => 'string',
            'terminalSpeed' => 'integer',
        ];

        foreach ($requiredFields as $field => $type) {
            if (! array_key_exists($field, $options)) {
                throw new \InvalidArgumentException(sprintf("Missing required option: '%s'", $field));
            }

            $value = $options[$field];
            $isValid = match ($type) {
                'integer' => is_int($value),
                'string' => is_string($value) && '' !== $value,
            };

            if (! $isValid) {
                throw new \InvalidArgumentException(sprintf("Invalid type for '%s': expected %s", $field, $type));
            }
        }

        $this->options = $options;
        $this->connector = new TcpConnector($loop, $context);
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
     * Connect to RLogin server.
     *
     * @param ?array{rows:int,columns:int,pixelsX:int,pixelsY:int,clientEscape:string} $properties
     *
     * @return PromiseInterface<ConnectionInterface>
     */
    public function connect(?array $properties = null): PromiseInterface
    {
        if (! is_null($properties)) {
            $requiredPropertyFields = [
                'rows' => 'integer',
                'columns' => 'integer',
                'pixelsX' => 'integer',
                'pixelsY' => 'integer',
                'clientEscape' => 'string',
            ];

            foreach ($requiredPropertyFields as $field => $type) {
                if (! array_key_exists($field, $properties)) {
                    throw new \InvalidArgumentException(sprintf("Missing required option: '%s'", $field));
                }

                $value = $properties[$field];
                $isValid = match ($type) {
                    'integer' => is_int($value),
                    'string' => is_string($value) && '' !== $value,
                };

                if (! $isValid) {
                    throw new \InvalidArgumentException(sprintf("Invalid type for '%s': expected %s", $field, $type));
                }
            }
        }

        $deferred = new Deferred();
        $this->connector->connect(sprintf('tcp://%s:%d', $this->options['host'], $this->options['port']))->then(
            function (ConnectionInterface $socketConnection) use ($deferred, $properties): void {
                /**
                 * These defaults can be adjusted via this.rows, this.columns, etc.
                 * They are used when sending a Window Change Control Sequence to the
                 * server.
                 */
                $defaultProperties = [
                    'rows' => $this->properties['rows'] ?? 24,
                    'columns' => $this->properties['columns'] ?? 80,
                    'pixelsX' => $this->properties['pixelsX'] ?? 640,
                    'pixelsY' => $this->properties['pixelsY'] ?? 480,
                    'clientEscape' => $this->properties['clientEscape'] ?? '~',
                ];

                $connection = new Connection($socketConnection, $properties ?? $defaultProperties);

                /**
                 * Upon connection establishment, the client sends four null-terminated
                 * strings to the server.  The first is an empty string (i.e., it
                 * consists solely of a single zero byte), followed by three non-null
                 * strings: the client username, the server username, and the terminal
                 * type and speed.
                 */
                $nul = chr(0);
                $msg = $nul
                    . $this->options['clientUsername'] . $nul
                    . $this->options['serverUsername'] . $nul
                    . $this->options['terminalType'] . '/' . $this->options['terminalSpeed'] . $nul;

                $connection->rawWrite($msg);

                /**
                 * The server has returned a zero byte to indicate that it has received these
                 * strings and is now in data transfer mode.
                 */
                $connection->on('connection-established', function () use ($deferred, $connection): void {
                    $deferred->resolve($connection);
                });
            },
            function (\Throwable $e) use ($deferred): void {
                $deferred->reject($e);
            }
        );

        /** @var PromiseInterface<ConnectionInterface> */
        return timeout($deferred->promise(), 10.0);
    }
}
