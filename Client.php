<?php

namespace ecom\sentry;

use Yii;

/**
 * Client class file.
 * @Author haoliang
 * @Date 31.01.2018 16:07
 */
class Client extends \Raven_Client
{

    final public function install()
    {
        /*
         * keep nothing here.
         *
         * Since yii project have it's own error handler,
         * - Client must not register another one;
         * - and the protected property, error_handler, do not have to hold an error handler instance.
         */
    }

}

