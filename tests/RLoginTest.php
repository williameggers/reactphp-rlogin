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

use WilliamEggers\React\RLogin\RLogin;

beforeEach(function () {
    $this->validOptions = [
        'host' => '127.0.0.1',
        'port' => 513,
        'clientUsername' => 'user1',
        'serverUsername' => 'user2',
        'terminalType' => 'vt100',
        'terminalSpeed' => 9600,
    ];
});

test('constructing with missing fields throws exception', function () {
    $options = $this->validOptions;
    unset($options['host']);
    new RLogin($options);
})->throws(InvalidArgumentException::class, "Missing required option: 'host'");

test('constructing with invalid field types throws exception', function () {
    $options = $this->validOptions;
    $options['port'] = 'not-a-port';
    new RLogin($options);
})->throws(InvalidArgumentException::class, "Invalid type for 'port': expected integer");

test('setting invalid rows throws exception', function () {
    $rlogin = new RLogin($this->validOptions);
    $rlogin->rows = -1;
})->throws(InvalidArgumentException::class, "Invalid 'rows' setting -1");

test('setting invalid clientEscape throws exception', function () {
    $rlogin = new RLogin($this->validOptions);
    $rlogin->clientEscape = 'too long';
})->throws(InvalidArgumentException::class, "Invalid 'clientEscape' setting too long");

test('setting and getting properties works', function () {
    $rlogin = new RLogin($this->validOptions);

    $rlogin->rows = 30;
    $rlogin->columns = 100;
    $rlogin->pixelsX = 1024;
    $rlogin->pixelsY = 768;
    $rlogin->clientEscape = '!';

    expect($rlogin->rows)->toBe(30);
    expect($rlogin->columns)->toBe(100);
    expect($rlogin->pixelsX)->toBe(1024);
    expect($rlogin->pixelsY)->toBe(768);
    expect($rlogin->clientEscape)->toBe('!');
});

test('setting an invalid property throws exception', function () {
    $rlogin = new RLogin($this->validOptions);
    $rlogin->invalidProperty = 123;
})->throws(InvalidArgumentException::class, "Invalid property: 'invalidProperty'");

test('setting an invalid property on connect', function () {
    $rlogin = new RLogin($this->validOptions);
    $rlogin->connect([
        'rows' => -1,
    ]);
})->throws(InvalidArgumentException::class, "Missing required option: 'columns'");

test('setting an invalid property type on connect', function () {
    $rlogin = new RLogin($this->validOptions);
    $rlogin->connect([
        'rows' => 'string',
    ]);
})->throws(InvalidArgumentException::class, "Invalid type for 'rows'");

test('setting an empty string property on connect', function () {
    $rlogin = new RLogin($this->validOptions);
    $rlogin->connect([
        'rows' => 30,
        'columns' => 100,
        'pixelsX' => 1024,
        'pixelsY' => 768,
        'clientEscape' => '',
    ]);
})->throws(InvalidArgumentException::class, "Invalid type for 'clientEscape'");

test('exception from connect method', function () {
    $rlogin = new RLogin([
        'host' => '-127.0.0.1',
        'port' => 512,
        'clientUsername' => 'user1',
        'serverUsername' => 'user2',
        'terminalType' => 'vt100',
        'terminalSpeed' => 9600,
    ]);
    $rlogin->connect()->catch(function (Throwable $e) {
        expect($e->getMessage())->toBe('Given URI "tcp://-127.0.0.1:512" does not contain a valid host IP (EINVAL)');
    });
});
