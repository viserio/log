<?php
declare(strict_types=1);
namespace Viserio\Component\Log;

use InvalidArgumentException;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Monolog\Logger as Monolog;
use Viserio\Component\Contract\Events\Traits\EventManagerAwareTrait;
use Viserio\Component\Log\Traits\ParseLevelTrait;
use Viserio\Component\Support\AbstractManager;
use Viserio\Component\Contract\OptionsResolver\ProvidesDefaultOptions as ProvidesDefaultOptionsContract;

class LogManager extends AbstractManager implements
    LoggerInterface,
    ProvidesDefaultOptionsContract
{
    use LoggerTrait;
    use ParseLevelTrait;
    use EventManagerAwareTrait;

    /**
     * {@inheritdoc}
     */
    protected const DRIVERS_CONFIG_LIST_NAME = 'channels';

    /**
     * {@inheritdoc}
     */
    public static function getDefaultOptions(): iterable
    {
        return [
            'default'   => 'single',
            'env'       => 'production',
            'channels' => [
                'aggregate' => [
                    'driver' => 'aggregate',
                    'channels' => ['single', 'daily'],
                ],
                'single' => [
                    'driver' => 'single',
                    'level' => 'debug',
                ],
                'daily' => [
                    'driver' => 'daily',
                    'level' => 'debug',
                    'days' => 3,
                ],
                'syslog' => [
                    'driver' => 'syslog',
                    'level' => 'debug',
                ],
                'errorlog' => [
                    'driver' => 'errorlog',
                    'level' => 'debug',
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function getMandatoryOptions(): iterable
    {
        return array_merge(
            parent::getMandatoryOptions(),
            [
                'path',
                'name'
            ]
        );
    }

    /**
     * Get a log channel instance.
     *
     * @param null|string $channel
     *
     * @return mixed
     */
    public function getChannel(?string $channel = null)
    {
        return $this->getDriver($channel);
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = []): void
    {
        $this->getDriver()->log($level, $message, $context);
    }

    /**
     * Create a aggregate log driver instance.
     *
     * @var array $config
     *
     * @return \Psr\Log\LoggerInterface
     */
    protected function createAggregateDriver(array $config): LoggerInterface
    {
        $handlers = [];

        foreach ((array) $config['channels'] as $channel) {
            $handlers[] = $this->getDriver($channel)->getHandlers();
        }

        return new Monolog($this->parseChannel($config), $handlers);
    }

    /**
     * Create an emergency log handler to avoid white screens of death.
     *
     * @param array $config
     *
     * @throws \InvalidArgumentException
     *
     * @return \Psr\Log\LoggerInterface
     */
    protected function createEmergencyLogger($config): LoggerInterface
    {
        $handler = new StreamHandler(
            $config['path'] . '/' . $this->resolvedOptions['name'] . '.log',
            self::parseLevel('debug')
        );
        $handler->setFormatter($this->getConfiguratedLineFormatter());

        return new Monolog($this->resolvedOptions['name'], [$handler]);
    }

    /**
     * Create an instance of the single file log driver.
     *
     * @param array $config
     *
     * @throws \InvalidArgumentException
     *
     * @return \Psr\Log\LoggerInterface
     */
    protected function createSingleDriver(array $config): LoggerInterface
    {
        $handler = new StreamHandler(
            $config['path'] . '/' . $this->resolvedOptions['name'] . '.log',
            self::parseLevel($config['level'] ?? 'debug')
        );
        $handler->setFormatter($this->getConfiguratedLineFormatter());

        return new Monolog($this->parseChannel($config), [$handler]);
    }

    /**
     * Create an instance of the daily file log driver.
     *
     * @param array $config
     *
     * @throws \InvalidArgumentException
     *
     * @return \Psr\Log\LoggerInterface
     */
    protected function createDailyDriver(array $config): LoggerInterface
    {
        $handler = new RotatingFileHandler(
            $config['path'],
            $config['days'] ?? 7,
            self::parseLevel($config['level'] ?? 'debug')
        );
        $handler->setFormatter($this->getConfiguratedLineFormatter());

        return new Monolog($this->parseChannel($config), [$handler]);
    }

    /**
     * Create an instance of the syslog log driver.
     *
     * @param array $config
     *
     * @throws \InvalidArgumentException
     *
     * @return \Psr\Log\LoggerInterface
     */
    protected function createSyslogDriver(array $config): LoggerInterface
    {
        $handler = new SyslogHandler(
            $this->resolvedOptions['name'],
            $config['facility'] ?? LOG_USER,
            self::parseLevel($config['level'] ?? 'debug')
        );
        $handler->setFormatter($this->getConfiguratedLineFormatter());

        return new Monolog($this->parseChannel($config), [$handler]);
    }

    /**
     * Create an instance of the "error log" log driver.
     *
     * @param array $config
     *
     * @throws \InvalidArgumentException
     *
     * @return \Psr\Log\LoggerInterface
     */
    protected function createErrorlogDriver(array $config): LoggerInterface
    {
        $handler = new ErrorLogHandler(
            $config['type'] ?? ErrorLogHandler::OPERATING_SYSTEM,
            self::parseLevel($config['level'] ?? 'debug')
        );
        $handler->setFormatter($this->getConfiguratedLineFormatter());

        return new Monolog($this->parseChannel($config), [$handler]);
    }

    /**
     * {@inheritdoc}
     */
    public function createDriver(array $config): LoggerInterface
    {
        try {
            $driver = parent::createDriver($config);
        } catch (InvalidArgumentException $exception) {
            $driver = $this->createEmergencyLogger($config);
            $driver->emergency(
                'Unable to create configured logger. Using emergency logger.',
                ['exception' => $exception]
            );
        }

        $logger = new Logger($driver);

        if ($this->eventManager !== null) {
            $logger->setEventManager($this->eventManager);
        }

        return $logger;
    }

    /**
     * Returns a line formatter with included stacktraces.
     *
     * @return \Monolog\Formatter\LineFormatter
     */
    protected function getConfiguratedLineFormatter(): LineFormatter
    {
        $formatter = new LineFormatter(
            self::getLineFormatterSettings(),
            null,
            true,
            true
        );

        $formatter->includeStacktraces();

        return $formatter;
    }

    /**
     * Layout for LineFormatter.
     *
     * @return string
     */
    private static function getLineFormatterSettings(): string
    {
        $options = [
            'gray'   => "\033[37m",
            'green'  => "\033[32m",
            'yellow' => "\033[93m",
            'blue'   => "\033[94m",
            'purple' => "\033[95m",
            'white'  => "\033[97m",
            'bold'   => "\033[1m",
            'reset'  => "\033[0m",
        ];

        $width     = \getenv('COLUMNS') ?: 60; // Console width from env, or 60 chars.
        $separator = \str_repeat('━', (int) $width); // A nice separator line

        $format = $options['bold'];
        $format .= $options['green'] . '[%datetime%]';
        $format .= $options['white'] . '[%channel%.';
        $format .= $options['yellow'] . '%level_name%';
        $format .= \sprintf('%s]', $options['white']);
        $format .= $options['blue'] . '[UID:%extra.uid%]';
        $format .= $options['purple'] . '[PID:%extra.process_id%]';
        $format .= \sprintf('%s:%s', $options['reset'], PHP_EOL);
        $format .= '%message%' . PHP_EOL;
        $format .= \sprintf('%s%s%s%s', $options['gray'], $separator, $options['reset'], PHP_EOL);

        return $format;
    }

    /**
     * Extract the log channel from the given configuration.
     *
     * @param array $config
     *
     * @return string
     */
    private function parseChannel(array $config): string
    {
        return $config['channel'] ?? $this->resolvedOptions['env'];
    }

    /**
     * {@inheritdoc}
     */
    protected static function getConfigName(): string
    {
        return 'logging';
    }
}
