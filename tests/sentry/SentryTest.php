<?php

function bootstrapApp () {

    $config = [
        'id' => 'test',
        'basePath' => __DIR__,
        'components' => [
            'sentry' => [
                'class' => ecom\sentry\Sentry::class,
                'environment' => 'test',
                'enabledEnvironments' => ['test'],
            ],
            'errorHandler' => [
                'class' => ecom\sentry\ErrorHandler::class,
            ],
        ],
    ];

    Yii::$container->set('ecom\sentry\Client', 'DummyClient');

    return new yii\web\Application($config);
}

class DummyClient extends ecom\sentry\Client
{
    CONST SUCCESS = 'oookkk';

    public function capture($data, $stack = null, $vars = null)
    {
        return self::SUCCESS;
    }
}

class SentryTest extends PHPUnit\Framework\TestCase
{

    private $_app;

    public function setUp()
    {
        $this->_app = bootstrapApp();
    }

    public function tearDown()
    {
        $this->_app = null;
    }

    public function testCaptureException()
    {
        $this->assertEquals(
            $this->_app->sentry->captureException(new Exception('bang bang bang')),
            DummyClient::SUCCESS
        );
    }
}

