<?php
/**
 * SentryClient class file.
 * @author Christoffer Niska <christoffer.niska@gmail.com>
 * @copyright Copyright &copy; Christoffer Niska 2013-
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD License
 */

namespace ecom\sentry;

use Yii;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;
use yii\helpers\ArrayHelper;
use yii\log\Logger;

/**
 * Application component that allows to communicate with Sentry.
 *
 * Methods accessible through the 'ComponentBehavior' class:
 * @method createPathAlias($alias, $path)
 * @method import($alias)
 * @method string publishAssets($path, $forceCopy = false)
 * @method void registerCssFile($url, $media = '')
 * @method void registerScriptFile($url, $position = null)
 * @method string resolveScriptVersion($filename, $minified = false)
 * @method CClientScript getClientScript()
 * @method void registerDependencies($dependencies)
 * @method string resolveDependencyPath($name)
 */
class Sentry extends Component
{
    // Sentry constants.
    const MAX_MESSAGE_LENGTH = 2048;
    const MAX_TAG_KEY_LENGTH = 32;
    const MAX_TAG_VALUE_LENGTH = 200;
    const MAX_CULPRIT_LENGTH = 200;

    /**
     * @var string dns to use when connecting to Sentry.
     */
    public $dns;

    /**
     * @var string name of the active environment.
     */
    public $environment = 'dev';

    /**
     * @var array list of names for environments in which data will be sent to Sentry.
     */
    public $enabledEnvironments = ['production', 'staging'];

    /**
     * @var array options to pass to the Raven client with the following structure:
     *   logger: (string) name of the logger
     *   auto_log_stacks: (bool) whether to automatically log stacktraces
     *   name: (string) name of the server
     *   site: (string) name of the installation
     *   tags: (array) key/value pairs that describe the event
     *   trace: (bool) whether to send stacktraces
     *   timeout: (int) timeout when connecting to Sentry (in seconds)
     *   exclude: (array) class names of exceptions to exclude
     *   shift_vars: (bool) whether to shift variables when creating a backtrace
     *   processors: (array) list of data processors
     */
    public $options = [];

    /**
     * @var array extra variables to send with exceptions to Sentry.
     */
    public $extraVariables = [];

    /**
     * Holds all event ids that were reported during this request
     */
    protected $_loggedEventIds = [];

    /**
     * @param mixed $loggedEventIds
     */
    public function setLoggedEventIds($loggedEventIds)
    {
        $this->_loggedEventIds = $loggedEventIds;
    }

    /**
     * @return mixed
     */
    public function getLoggedEventIds()
    {
        return $this->_loggedEventIds;
    }

    /** @var \ecom\sentry\Client */
    private $_client;

    public function __construct(Client $clientProto, $config = [])
    {
        parent::__construct($config);

        $this->_client = $this->createClient(get_class($clientProto));
    }

    /**
     * Logs an exception to Sentry.
     * @param Exception $exception exception to log.
     * @param array $options capture options that can contain the following structure:
     *   culprit: (string) function call that caused the event
     *   extra: (array) additional metadata to store with the event
     * @param string $logger name of the logger.
     * @param mixed $context exception context.
     * @return string|null event id (or null if not captured).
     * @throws Exception if logging the exception fails.
     */
    public function captureException($exception, $options = [], $logger = '', $context = null)
    {
        if (!$this->isEnvironmentEnabled()) {
            return null;
        }
        $this->processOptions($options);
        try {
            $eventId = $this->_client->getIdent(
                $this->_client->captureException($exception, $options, $logger, $context)
            );
        } catch (\Exception $e) {
            if (YII_DEBUG) {
                throw new Exception('SentryClient failed to log exception: ' . $e->getMessage(), (int)$e->getCode());
            } else {
                $this->log($e->getMessage(), Logger::LEVEL_ERROR);
                throw new Exception('SentryClient failed to log exception.', (int)$e->getCode());
            }
        }
        $this->_loggedEventIds[] = $eventId;
        $this->log(sprintf('Exception logged to Sentry with event id: %d', $eventId), Logger::LEVEL_INFO);
        return $eventId;
    }

    /**
     * Logs a message to Sentry.
     * @param string $message message to log.
     * @param array $params message parameters.
     * @param array $options capture options that can contain the following structure:
     *   culprit: (string) function call that caused the event
     *   extra: (array) additional metadata to store with the event
     * @param bool $stack whether to send the stack trace.
     * @param mixed $context message context.
     * @return string|null event id (or null if not captured).
     * @throws Exception if logging the message fails.
     */
    public function captureMessage($message, $params = [], $options = [], $stack = false, $context = null)
    {
        if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
            throw new InvalidParamException(sprintf(
                'SentryClient cannot send messages that contain more than %d characters.',
                self::MAX_MESSAGE_LENGTH
            ));
        }
        if (!$this->isEnvironmentEnabled()) {
            return null;
        }
        $this->processOptions($options);
        try {
            $eventId = $this->_client->getIdent(
                $this->_client->captureMessage($message, $params, $options, $stack, $context)
            );
        } catch (\Exception $e) {
            if (YII_DEBUG) {
                throw new Exception('SentryClient failed to log message: ' . $e->getMessage(), (int)$e->getCode());
            } else {
                $this->log($e->getMessage(), Logger::LEVEL_ERROR);
                throw new Exception('SentryClient failed to log message.', (int)$e->getCode());
            }
        }
        $this->_loggedEventIds[] = $eventId;
        $this->log(sprintf('Message logged to Sentry with event id: %d', $eventId), Logger::LEVEL_INFO);
        return $eventId;
    }

    /**
     * Logs a query to Sentry.
     * @param string $query query to log.
     * @param integer $level log level.
     * @param string $engine name of the sql driver.
     * @return string|null event id (or null if not captured).
     * @throws Exception if logging the query fails.
     */
    public function captureQuery($query, $level = Logger::LEVEL_INFO, $engine = '')
    {
        if (!$this->isEnvironmentEnabled()) {
            return null;
        }
        try {
            $eventId = $this->_client->getIdent(
                $this->_client->captureQuery($query, $level, $engine)
            );
        } catch (\Exception $e) {
            if (YII_DEBUG) {
                throw new Exception('SentryClient failed to log query: ' . $e->getMessage(), (int)$e->getCode());
            } else {
                $this->log($e->getMessage(), Logger::LEVEL_ERROR);
                throw new Exception('SentryClient failed to log query.', (int)$e->getCode());
            }
        }
        $this->_loggedEventIds[] = $eventId;
        $this->log(sprintf('Query logged to Sentry with event id: %d', $eventId), Logger::LEVEL_INFO);
        return $eventId;
    }

    /**
     * Returns whether the active environment is enabled.
     * @return bool the result.
     */
    protected function isEnvironmentEnabled()
    {
        return in_array($this->environment, $this->enabledEnvironments);
    }

    /**
     * Processes the given options.
     * @param array $options the options to process.
     */
    protected function processOptions(&$options)
    {
        if (!isset($options['extra'])) {
            $options['extra'] = [];
        }

        $options['extra'] = ArrayHelper::merge($this->extraVariables, $options['extra']);
    }

    /**
     * Writes a message to the log.
     * @param string $message message to log.
     * @param string $level log level.
     */
    protected function log($message, $level)
    {
        Yii::getLogger()->log($message, $level, 'ecom.sentry');
    }

    /**
     * Creates a Raven client
     * @return \ecom\sentry\Client client instance.
     * @throws Exception if the client could not be created.
     */
    protected function createClient($clientClass)
    {
        $options = ArrayHelper::merge(
            [
                'logger' => 'yii',
                'tags' => [
                    'environment' => $this->environment,
                    'php_version' => phpversion(),
                ],
            ],
            $this->options
        );

        try {
            $this->checkTags($options['tags']);
            return new $clientClass($this->dns, $options);
        } catch (\Exception $e) {
            if (YII_DEBUG) {
                throw new Exception('SentryClient failed to create client: ' . $e->getMessage(), (int)$e->getCode());
            } else {
                $this->log($e->getMessage(), Logger::LEVEL_ERROR);
                throw new Exception('SentryClient failed to create client.', (int)$e->getCode());
            }
        }
    }

    /**
     * Checks that the given tags are valid.
     * @param array $tags tags to check.
     * @throws Exception if a tag is invalid.
     */
    protected function checkTags($tags)
    {
        foreach ($tags as $key => $value) {
            if (strlen($key) > self::MAX_TAG_KEY_LENGTH) {
                throw new InvalidConfigException(sprintf(
                    'SentryClient does not allow tag keys that contain more than %d characters.',
                    self::MAX_TAG_KEY_LENGTH
                ));
            }

            if (strlen($value) > self::MAX_TAG_VALUE_LENGTH) {
                throw new InvalidConfigException(sprintf(
                    'SentryClient does not allow tag values that contain more than %d characters.',
                    self::MAX_TAG_VALUE_LENGTH
                ));
            }
        }
    }
}
