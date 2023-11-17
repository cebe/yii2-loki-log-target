<?php

namespace cebe\lokilogtarget;

use Yii;
use yii\base\Exception;
use yii\helpers\Json;
use yii\helpers\VarDumper;
use yii\httpclient\Client;
use yii\httpclient\Request;
use yii\log\Logger;
use yii\log\Target;

/**
 * LokiLogTarget sends logs to Grafana Loki.
 */
class LokiLogTarget extends Target
{
    /**
     * @var string Push API URL for loki, e.g. https://loki.example.com/loki/api/v1/push
     */
    public $lokiPushUrl;
    /**
     * @var string Loki Basic Auth User
     */
    public $lokiAuthUser;
    /**
     * @var string Loki Basic Auth Password
     */
    public $lokiAuthPassword;

    /**
     * @var array labels to send to loki. If not set, defaults to [
     *     'host' => gethostname(),
     *     'environment' => YII_ENV,
     *     'service' => 'yii2',
     *     'app' => Yii::$app->id,
     * ]
     * The 'level' label is attached automatically. You can customize the name of the level label by setting the [[$levelLabel]] property.
     */
    public $labels;
    /**
     * @var string name of the label to use for the log level. No label will be created when empty.
     * @see https://grafana.com/docs/grafana/latest/explore/logs-integration/
     */
    public $levelLabel = 'level';
    /**
     * @var array map specific categories and levels from yii to a different level in loki.
     * Example:
     * [
     *      // yii category
     *      'yii\web\HttpException:404' => [
     *          // yii level => loki level
     *          'error' => 'warning',
     *      ],
     * ]
     */
    public $levelMap = [];
    /**
     * @var array|null only send context information for the specified log levels.
     * If null, context info is sent for all levels. Set this to an empty array if you do not want to send context info on any level.
     */
    public $contextLevels = null;

    public function init()
    {
        parent::init();

        if ($this->labels === null) {
            $this->labels = [
                'host' => gethostname(),
                'environment' => YII_ENV,
                'service' => 'yii2',
                'app' => Yii::$app->id,
            ];
        }
    }

    private $_client;

    protected function getClient()
    {
        return new Client([
            'requestConfig' => [
                'class' => Request::class,
                'url' => $this->lokiPushUrl,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($this->lokiAuthUser . ':' .$this->lokiAuthPassword),
                ],
            ],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function export()
    {
        $lokiMessages = [];
        foreach ($this->messages as $key => $message) {
            $lokiMessage = $this->formatMessage($message);
            if ($lokiMessage) {
                $lokiMessages[] = $lokiMessage;
            }
        }

        // https://grafana.com/docs/loki/latest/api/#push-log-entries-to-loki
        $lokiRequestData = [
            'streams' => $lokiMessages
        ];

        $headers = [];
        $data = Json::encode($lokiRequestData, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (function_exists('gzencode')) {
            $data = gzencode($data);
            $headers['Content-Encoding'] = 'gzip';
        }

        $response = $this->getClient()->post($this->lokiPushUrl, $data, $headers)->send();
        if (!$response->isOk) {
            throw new Exception('Unable to send request to Loki! Status ' . $response->getStatusCode() . ' - ' . $response->getContent());
        }
    }

    public function formatMessage($message)
    {
        list($text, $level, $category, $timestamp) = $message;
        $level = Logger::getLevelName($level);
        $level = $this->remapLevel($level, $category);
        if ($level === false) {
            return false;
        }
        if (!is_string($text)) {
            // exceptions may not be serializable if in the call stack somewhere is a Closure
            if ($text instanceof \Exception || $text instanceof \Throwable) {
                $text = (string) $text;
            } else {
                $text = VarDumper::export($text);
            }
        }
        $traces = [];
        if (isset($message[4])) {
            foreach ($message[4] as $trace) {
                $traces[] = "in {$trace['file']}:{$trace['line']}";
            }
        }

        $prefix = $this->getMessagePrefix($message);

        $labels = $this->labels;
        if ($this->levelLabel) {
            $labels[$this->levelLabel] = $level;
        }
        $lokiMessage = "{$prefix}[$level][$category] $text"
            . (empty($traces) ? '' : "\n    " . implode("\n    ", $traces))
            . ($this->contextLevels === null || in_array($level, $this->contextLevels, true) ? "\n\n" . parent::getContextMessage() : '');
        return [
            'stream' => $labels,
            'values' => [
                [(string)$this->getNanoTime($timestamp), $lokiMessage]
            ]
        ];
    }

    /**
     * Generates the context information to be logged.
     * The default implementation will dump user information, system variables, etc.
     * @return string the context information. If an empty string, it means no context information.
     */
    protected function getContextMessage()
    {
        // avoid sending context info as a separate message
        // in loki we add relevant context info to all messages
        return '';
    }

    /**
     * return timestamp in nanoseconds
     * @param float $timestamp
     * @return int
     */
    protected function getNanoTime($timestamp)
    {
        $timestamp *= 1000000000;
        return (int)$timestamp;
    }

    protected function remapLevel($level, $category)
    {
        if (isset($this->levelMap[$category]['*'])) {
            return $this->levelMap[$category]['*'];
        }
        if (isset($this->levelMap[$category][$level])) {
            return $this->levelMap[$category][$level];
        }
        return $level;
    }
}
