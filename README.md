# ddrv/flysystem-macro-sd

[league/flysystem](https://packagist.org/packages/league/flysystem) adapter for [ddrv/macro-sd](https://packagist.org/packages/ddrv/macro-sd)

# Install

Install this library, your favorite [psr-18](https://packagist.org/providers/psr/http-client-implementation) and [psr-7](https://packagist.org/providers/psr/http-factory-implementation) implementation 

```bash
composer require ddrv/flysystem-macro-sd:^1.0
```

# Usage

```php
<?php

/**
 * @var \Psr\Http\Client\ClientInterface $httpCLient
 * @var \Psr\Http\Message\RequestFactoryInterface $requestFactory
 * @var \Psr\Http\Message\StreamFactoryInterface $streamFactory
 */

$adapter = new \Ddrv\Flysystem\MacroSd\MacroSdAdapter(
    $httpCLient,
    $requestFactory,
    $streamFactory,
    'https://your-macro-sd.host',
    'macro-sd-user',
    'macro-sd-password',
);

$filesystem = new \League\Flysystem\Filesystem($adapter);
```