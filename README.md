# Guzzle Mock Handler

[![Packagist Version](https://img.shields.io/packagist/v/verdet/guzzle-mock)](https://packagist.org/packages/verdet/guzzle-mock)
[![CI](https://github.com/verdet23/guzzle-mock/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/verdet23/guzzle-mock/actions/workflows/ci.yml)
[![Coverage Status](https://coveralls.io/repos/github/verdet23/guzzle-mock/badge.svg)](https://coveralls.io/github/verdet23/guzzle-mock)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen)](https://raw.githubusercontent.com/verdet23/guzzle-mock/master/phpstan.neon)
[![Coding Style](https://img.shields.io/badge/Coding%20Style-PSR--12-yellowgreen)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/verdet23/guzzle-mock?color=blue)](https://raw.githubusercontent.com/verdet23/guzzle-mock/master/LICENSE)
![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/verdet/guzzle-mock)

## Description
Inspired by [guzzle/guzzle](https://github.com/guzzle/guzzle) MockHandler and [alekseytupichenkov/GuzzleStub](https://github.com/alekseytupichenkov/GuzzleStub) library.

Mock Handler functional same as default `GuzzleHttp\Handler\MockHandler` except filling queue. Argument `$queue` expected to be array of Request and Response objects.
When you pass Request to MockHanlder it will try to find suitable Request in queue and return paired Response.

## Prerequisite

php >= 8.0  
guzzlehttp/guzzle >= 7.0

## Installation

Use the package manager [composer](https://getcomposer.org/) to install.

```bash
composer require --dev verdet/guzzle-mock
```

## Basic usage

```php
// Create a mock and queue three pairs of request and responses.
$mock = new MockHandler([
            [
                new Request('GET', 'https://example.com'),
                new Response(200, ['X-Foo' => 'Bar'], 'Hello, World')
            ],
            [
                new Request('GET', 'https://example.com/latest'),
                new Response(202, ['Content-Length' => '0'])],
            [
                new Request('POST', 'https://example.com/foo'),
                new RequestException('Error Communicating with Server', new Request('POST', 'https://example.com/foo'))
            ]
        ]);
```

Rest of usage same as https://docs.guzzlephp.org/en/stable/testing.html
