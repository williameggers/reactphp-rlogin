# RLogin client for ReactPHP

[![CI status](https://github.com/williameggers/reactphp-rlogin/workflows/CI/badge.svg)](https://github.com/williameggers/reactphp-rlogin/actions)

An asynchronous [RLogin](https://datatracker.ietf.org/doc/html/rfc1282) client for PHP, built on [ReactPHP](https://reactphp.org/). 
Supports client escape sequences, terminal window resizing, and full event-driven interaction.

## Installation

Install via Composer:

```bash
composer require williameggers/reactphp-rlogin
```

## Usage example

```php
use WilliamEggers\React\RLogin\RLogin;

// All of these options are required
$client = new RLogin([
    'host' => 'rlogin.example.com',
    'port' => 513,
    'clientUsername' => 'localuser',
    'serverUsername' => 'remoteuser',
    'terminalType' => 'vt100',
    'terminalSpeed' => 9600,
]);

// Now that the events will be handled properly, we can connect ...
$client->connect()->then(function (Connection $connection) {
    // If data has been received from the server ...
    $connection->on('data', function ($data) {
        echo "Received: $data";
    });

    // If there was an error ...
    $connection->on('error', function (\Throwable $error) {
        echo 'Error: ' . $error->getMessage() . "\n";
    });

    // If we've been disconnected ...
    $connection->on('close', function () {
        echo "Closed\n";
    });
});
```

## Contributions

Contributions are welcome and encouraged!

To contribute:

1. Fork the repository.
1. Create a new branch for your changes.
1. Submit a pull request with a clear description of what you've done and why.

Please try to follow existing coding style and conventions, and include tests if applicable.
Feel free to open an issue if you'd like to discuss a potential change or need guidance on where to start.

## License

BSD 2-Clause License

Copyright (c) 2025, William Eggers

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this
   list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.