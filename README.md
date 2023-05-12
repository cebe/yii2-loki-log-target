# yii2-loki-log-target

Grafana Loki Log Target for Yii2.

## Requirements

- PHP 7.1 or higher (works with PHP 8)
- Yii 2

## Install

    composer require cebe/yii2-loki-log-target

## Usage

Add the log target to your application config:

```php
// ...
'components' => [
    // ...
    'log' => [
        // ...
        'targets' => [
            [
                'class' => \cebe\lokilogtarget\LokiLogTarget::class,
                //'enabled' => YII_ENV_PROD,

                'lokiPushUrl' => 'https://loki.example.com/loki/api/v1/push',
                'lokiAuthUser' => 'loki', // HTTP Basic Auth User
                'lokiAuthPassword' => '...', // HTTP Basic Auth Password

                'levels' => ['error', 'warning', 'info'],

                // optionally exclude categories
                'except' => [
                    'yii\db\Connection::open',
                    'yii\db\Command::execute',
                    'yii\httpclient\StreamTransport::send',
                ],

                // optionally re-map log level for certain categories
                'levelMap' => [
                    // yii category
                    'yii\web\HttpException:404' => [
                        // yii level => loki level
                        '*' => 'info',
                    ],
                    'yii\web\HttpException:401' => [
                        // yii level => loki level
                        '*' => 'warning',
                    ],
                ],

            ],
        ],
    ],
]
```

See also <https://www.yiiframework.com/doc/guide/2.0/en/runtime-logging#log-targets>.

