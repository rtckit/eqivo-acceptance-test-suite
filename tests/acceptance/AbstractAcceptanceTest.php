<?php

declare(strict_types = 1);

namespace RTCKit\Eqivo\Tests\Acceptance;


use RTCKit\Eqivo as Eqivo;
use RTCKit\FiCore\{
    Plan,
    Command,
    Signal,
};
use RTCKit\FiCore\Switch\ESL as FiESL;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Http\Middleware\{
    LimitConcurrentRequestsMiddleware,
    RequestBodyBufferMiddleware,
    RequestBodyParserMiddleware,
    StreamingRequestMiddleware
};
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Socket\Server as SocketServer;
use RTCKit\React\ESL\InboundClient;
use RTCKit\ESL;
use Spatie\Docker\{
    DockerContainer,
    DockerContainerInstance
};
use function Clue\React\Block\await;

/**
 * Abstract Acceptance Test Class
 */
abstract class AbstractAcceptanceTest extends TestCase
{
    public const DOCKER_IMAGE_REPOSITORY = 'rtckit/eqivo-freeswitch';

    public const DOCKER_IMAGE_TAG = 'v1.10.8';

    public const DOCKER_CONTAINER_NAME = 'eqivo-slimswitch-test';

    public const CONSUMER_SOCKET_ADDR = '127.0.0.1:8099';

    public static DockerContainerInstance $slimSwitch;

    public static Eqivo\App $app;

    public static Browser $browser;

    public static SocketServer $consumerSocket;

    public static InboundClient $esl;

    public static string $authHeader;

    public static string $hostIp;

    public static function setUpBeforeClass(): void
    {
        /* Guess the Docker host IP address */
        $ifs = net_get_interfaces();

        if (!isset($ifs['docker0'])) {
            $this->fail('Cannot find Docker network interface');
        }

        foreach ($ifs['docker0']['unicast'] as $config) {
            if (isset($config['family']) && ($config['family'] === 2)) {
                self::$hostIp = $config['address'];
                break;
            }
        }

        if (!isset(self::$hostIp)) {
            $this->fail('Cannot find Docker host IP address');
        }

        /* Setup FreeSWITCH first */
        self::$slimSwitch = DockerContainer::create(self::DOCKER_IMAGE_REPOSITORY . ':' . self::DOCKER_IMAGE_TAG)
            ->name(self::DOCKER_CONTAINER_NAME)
            ->setVolume(__DIR__ . '/../fixtures/acceptance/freeswitch.xml', '/etc/freeswitch/freeswitch.xml')
            ->mapPort(8021, 8021)
            ->stopOnDestruct()
            ->start();

        $now = time();

        /* Wait for FreeSWITCH to come online (or bail) */
        while (true) {
            usleep(100000);

            if ((time() - $now >= 60)) {
                self::fail('Cannot reliably setup the acceptance test environment');
            }

            $fp = stream_socket_client('tcp://127.0.0.1:8021', $errno, $errstr, 0);

            if ($fp) {
                $response = fgets($fp);

                if (empty($response)) {
                    continue;
                }

                if (strpos($response, 'Content-Type: auth/request') === 0) {
                    break;
                }

                fclose($fp);
            }
        }

        libxml_use_internal_errors(true);

        /* Raise our application */
        self::$app = new Eqivo\App;

        $config = new Eqivo\Config\Set;
        $config->legacyConfigFile = __DIR__ . '/../fixtures/acceptance/default.conf';
        $config->eslServerAdvertisedIp = self::$hostIp;

        self::$app->setConfig($config);
        self::$app->addConfigResolver(new Eqivo\Config\LegacyConfigFile);
        self::$app->resolveConfig();

        self::$app->setHttpClient(new Eqivo\HttpClient);

        self::$app
            ->setEventConsumer(new FiESL\Event\Consumer)
            ->setPlanConsumer(
                (new Eqivo\Plan\Consumer)
                    ->setApp(self::$app)
                    ->setElementHandler(Plan\CaptureSpeech\Element::class, new Plan\CaptureSpeech\Handler)
                    ->setElementHandler(Plan\CaptureTones\Element::class, new Plan\CaptureTones\Handler)
                    ->setElementHandler(Plan\Conference\Element::class, new Plan\Conference\Handler)
                    ->setElementHandler(Plan\Hangup\Element::class, new Plan\Hangup\Handler)
                    ->setElementHandler(Plan\Playback\Element::class, new Plan\Playback\Handler)
                    ->setElementHandler(Plan\Record\Element::class, new Plan\Record\Handler)
                    ->setElementHandler(Plan\Redirect\Element::class, new Plan\Redirect\Handler)
                    ->setElementHandler(Plan\Silence\Element::class, new Plan\Silence\Handler)
                    ->setElementHandler(Plan\Speak\Element::class, new Plan\Speak\Handler)
                    ->setElementHandler(Eqivo\Plan\Dial\Element::class, new Eqivo\Plan\Dial\Handler)
                    ->setElementHandler(Eqivo\Plan\PreAnswer\Element::class, new Eqivo\Plan\PreAnswer\Handler)
                    ->setElementHandler(Eqivo\Plan\SipTransfer\Element::class, new Eqivo\Plan\SipTransfer\Handler)
            )
            ->setPlanProducer(
                (new Eqivo\Plan\Producer)
                    ->setApp(self::$app)
                    ->setRestXmlElementParser(new Eqivo\Plan\Parser\Conference)
                    ->setRestXmlElementParser(new Eqivo\Plan\Parser\Dial)
                    ->setRestXmlElementParser(new Eqivo\Plan\Parser\GetDigits)
                    ->setRestXmlElementParser(new Eqivo\Plan\Parser\GetSpeech)
                    ->setRestXmlElementParser(new Eqivo\Plan\Parser\Hangup)
                    ->setRestXmlElementParser(new Eqivo\Plan\Parser\Play)
                    ->setRestXmlElementParser(new Eqivo\Plan\Parser\PreAnswer)
                    ->setRestXmlElementParser(new Eqivo\Plan\Parser\Record)
                    ->setRestXmlElementParser(new Eqivo\Plan\Parser\Redirect)
                    ->setRestXmlElementParser(new Eqivo\Plan\Parser\SipTransfer)
                    ->setRestXmlElementParser(new Eqivo\Plan\Parser\Speak)
                    ->setRestXmlElementParser(new Eqivo\Plan\Parser\Wait)
            );

        self::$app->setSignalProducer(
            (new Eqivo\Signal\Producer)
                ->setApp(self::$app)
                ->setSignalHandler(Signal\Channel\DTMF::class, new Eqivo\Signal\Handler\Channel\DTMF)
                ->setSignalHandler(Signal\Channel\Hangup::class, new Eqivo\Signal\Handler\Channel\Hangup)
                ->setSignalHandler(Signal\Channel\Heartbeat::class, new Eqivo\Signal\Handler\Channel\Heartbeat)
                ->setSignalHandler(Signal\Channel\MachineDetection::class, new Eqivo\Signal\Handler\Channel\MachineDetection)
                ->setSignalHandler(Signal\Channel\Progress::class, new Eqivo\Signal\Handler\Channel\Progress)
                ->setSignalHandler(Signal\Channel\Recording::class, new Eqivo\Signal\Handler\Channel\Recording)
                ->setSignalHandler(Signal\Channel\Speech::class, new Eqivo\Signal\Handler\Channel\Speech)
                ->setSignalHandler(Signal\Conference\DTMF::class, new Eqivo\Signal\Handler\Conference\DTMF)
                ->setSignalHandler(Signal\Conference\Enter::class, new Eqivo\Signal\Handler\Conference\Enter)
                ->setSignalHandler(Signal\Conference\Floor::class, new Eqivo\Signal\Handler\Conference\Floor)
                ->setSignalHandler(Signal\Conference\Leave::class, new Eqivo\Signal\Handler\Conference\Leave)
                ->setSignalHandler(Signal\Conference\Recording::class, new Eqivo\Signal\Handler\Conference\Recording)
                ->setSignalHandler(Eqivo\Signal\Channel\Bridge::class, new Eqivo\Signal\Handler\Channel\Bridge)
                ->setSignalHandler(Eqivo\Signal\Channel\DTMF::class, new Eqivo\Signal\Handler\Channel\DTMF)
        );

        self::$app->setEslClient(
            (new FiESL\Client)
                ->setApp(self::$app)
                ->setEventHandler(new FiESL\Event\BackgroundJob)
                ->setEventHandler(new FiESL\Event\ChannelHangupComplete)
                ->setEventHandler(new FiESL\Event\ChannelProgress)
                ->setEventHandler(new FiESL\Event\ChannelProgressMedia)
                ->setEventHandler(new FiESL\Event\ChannelState)
                ->setEventHandler(new FiESL\Event\Custom)
                ->setEventHandler(new FiESL\Event\RecordStop)
                ->setEventHandler(new FiESL\Event\SessionHeartbeat)
                ->setEventHandler(new Eqivo\Switch\ESL\Event\CallUpdate)
        );

        self::$app->setCommandConsumer(
            (new Command\Consumer)
                ->setApp(self::$app)
                ->setMethodHandler(Command\Channel\DTMF\Request::class, new Command\Channel\DTMF\Handler)
                ->setMethodHandler(Command\Channel\Hangup\Request::class, new Command\Channel\Hangup\Handler)
                ->setMethodHandler(Command\Channel\Originate\Request::class, new Command\Channel\Originate\Handler)
                ->setMethodHandler(Command\Channel\Playback\Request::class, new Command\Channel\Playback\Handler)
                ->setMethodHandler(Command\Channel\Record\Request::class, new Command\Channel\Record\Handler)
                ->setMethodHandler(Command\Channel\Redirect\Request::class, new Command\Channel\Redirect\Handler)
                ->setMethodHandler(Command\Conference\Member\Request::class, new Command\Conference\Member\Handler)
                ->setMethodHandler(Command\Conference\Playback\Request::class, new Command\Conference\Playback\Handler)
                ->setMethodHandler(Command\Conference\Record\Request::class, new Command\Conference\Record\Handler)
                ->setMethodHandler(Command\Conference\Speak\Request::class, new Command\Conference\Speak\Handler)
                ->setMethodHandler(Eqivo\Command\Channel\SoundTouch\Request::class, new Eqivo\Command\Channel\SoundTouch\Handler)
                ->setMethodHandler(Eqivo\Command\Conference\Query\Request::class, new Eqivo\Command\Conference\Query\Handler)
        );

        self::$app->setRestServer(
            (new Eqivo\Rest\Server)
                ->setApp(self::$app)
                ->setRouteController('POST', '/v0.1/BulkCall/', new Eqivo\Rest\Controller\V0_1\BulkCall)
                ->setRouteController('POST', '/v0.1/Call/', new Eqivo\Rest\Controller\V0_1\Call)
                ->setRouteController('POST', '/v0.1/CancelScheduledHangup/', new Eqivo\Rest\Controller\V0_1\CancelScheduledHangup)
                ->setRouteController('POST', '/v0.1/CancelScheduledPlay/', new Eqivo\Rest\Controller\V0_1\CancelScheduledPlay)
                ->setRouteController('POST', '/v0.1/ConferenceDeaf/', new Eqivo\Rest\Controller\V0_1\ConferenceDeaf)
                ->setRouteController('POST', '/v0.1/ConferenceHangup/', new Eqivo\Rest\Controller\V0_1\ConferenceHangup)
                ->setRouteController('POST', '/v0.1/ConferenceKick/', new Eqivo\Rest\Controller\V0_1\ConferenceKick)
                ->setRouteController('POST', '/v0.1/ConferenceList/', new Eqivo\Rest\Controller\V0_1\ConferenceList)
                ->setRouteController('POST', '/v0.1/ConferenceListMembers/', new Eqivo\Rest\Controller\V0_1\ConferenceListMembers)
                ->setRouteController('POST', '/v0.1/ConferenceMute/', new Eqivo\Rest\Controller\V0_1\ConferenceMute)
                ->setRouteController('POST', '/v0.1/ConferencePlay/', new Eqivo\Rest\Controller\V0_1\ConferencePlay)
                ->setRouteController('POST', '/v0.1/ConferenceRecordStart/', new Eqivo\Rest\Controller\V0_1\ConferenceRecordStart)
                ->setRouteController('POST', '/v0.1/ConferenceRecordStop/', new Eqivo\Rest\Controller\V0_1\ConferenceRecordStop)
                ->setRouteController('POST', '/v0.1/ConferenceSpeak/', new Eqivo\Rest\Controller\V0_1\ConferenceSpeak)
                ->setRouteController('POST', '/v0.1/ConferenceUndeaf/', new Eqivo\Rest\Controller\V0_1\ConferenceUndeaf)
                ->setRouteController('POST', '/v0.1/ConferenceUnmute/', new Eqivo\Rest\Controller\V0_1\ConferenceUnmute)
                ->setRouteController('POST', '/v0.1/GroupCall/', new Eqivo\Rest\Controller\V0_1\GroupCall)
                ->setRouteController('POST', '/v0.1/HangupAllCalls/', new Eqivo\Rest\Controller\V0_1\HangupAllCalls)
                ->setRouteController('POST', '/v0.1/HangupCall/', new Eqivo\Rest\Controller\V0_1\HangupCall)
                ->setRouteController('POST', '/v0.1/Play/', new Eqivo\Rest\Controller\V0_1\Play)
                ->setRouteController('POST', '/v0.1/PlayStop/', new Eqivo\Rest\Controller\V0_1\PlayStop)
                ->setRouteController('POST', '/v0.1/RecordStart/', new Eqivo\Rest\Controller\V0_1\RecordStart)
                ->setRouteController('POST', '/v0.1/RecordStop/', new Eqivo\Rest\Controller\V0_1\RecordStop)
                ->setRouteController('POST', '/v0.1/ScheduleHangup/', new Eqivo\Rest\Controller\V0_1\ScheduleHangup)
                ->setRouteController('POST', '/v0.1/SchedulePlay/', new Eqivo\Rest\Controller\V0_1\SchedulePlay)
                ->setRouteController('POST', '/v0.1/SendDigits/', new Eqivo\Rest\Controller\V0_1\SendDigits)
                ->setRouteController('POST', '/v0.1/SoundTouch/', new Eqivo\Rest\Controller\V0_1\SoundTouch)
                ->setRouteController('POST', '/v0.1/SoundTouchStop/', new Eqivo\Rest\Controller\V0_1\SoundTouchStop)
                ->setRouteController('POST', '/v0.1/TransferCall/', new Eqivo\Rest\Controller\V0_1\TransferCall)
        );

        self::$app->setEslServer(new FiESL\Server);

        self::$app->prepare();
        self::$app->run();

        /* Prepare API requestor */
        self::$browser = (new Browser)
            ->withBase('http://' . $config->restServerAdvertisedHost . ':' . $config->restServerBindPort);

        /* Precalculate Authorization header */
        self::$authHeader = 'Basic ' . base64_encode(self::$app->config->restAuthId . ':' . self::$app->config->restAuthToken);

        /* Raise Consumer HTTP server */
        $consumer = new HttpServer(
            new StreamingRequestMiddleware,
            new LimitConcurrentRequestsMiddleware($config->restServerMaxHandlers),
            new RequestBodyBufferMiddleware($config->restServerMaxRequestSize),
            new RequestBodyParserMiddleware,
            static::class . '::onRestXmlRequest'
        );

        self::$consumerSocket = new SocketServer(self::CONSUMER_SOCKET_ADDR);

        $consumer->listen(self::$consumerSocket);
        $consumer->on('error', function (Throwable $t) {
            $t = $t->getPrevious() ?: $t;
            self::fail('RestXML server exception: ' . $t->getMessage());
        });

        /* Wait for inbound service to connect to FreeSWITCH */
        $deferred = new Deferred;

        $timer = null;
        $timer = Loop::addPeriodicTimer(0.01, function () use (&$timer, $deferred) {
            if (count(self::$app->getAllCores())) {
                Loop::cancelTimer($timer);
                $deferred->resolve();
            }
        });

        await($deferred->promise());

        /* Launch an ESL client */
        self::$esl = new InboundClient('127.0.0.1', 8021, self::$app->config->cores[0]->eslPassword);
        self::$esl->connect();
    }

    public static function tearDownAfterClass(): void
    {
        /* Stop our application */
        self::$app->eslClient->shutdown();
        self::$app->eslServer->shutdown();
        self::$app->restServer->shutdown();

        /* Stop the Consumer server */
        self::$consumerSocket->close();

        /* Flush event floop */
        Loop::futureTick(function() {
            Loop::stop();
        });

        /* Retrieve FreeSWITCH logs */
        // system('docker logs ' . self::DOCKER_CONTAINER_NAME);

        /* Tell FreeSWITCH it's time to shutdown */
        $deferred = new Deferred;

        self::$esl->on('disconnect', function () use ($deferred) {
            $deferred->resolve();
        });

        self::$esl->api((new ESL\Request\Api)->setParameters('fsctl shutdown asap'));

        await($deferred->promise());

        /* Wait for FreeSWITCH to go offline */
        while (true) {
            usleep(100000);

            exec('docker inspect ' . self::DOCKER_CONTAINER_NAME . ' 2> /dev/null', $output, $code);

            if ($code === 1) {
                break;
            }
        }
    }

    abstract public static function onRestXmlRequest(ServerRequestInterface $request);

    public static function getArgCombinations(array $args): array
    {
        $ret = [[]];

        foreach ($args as $key => $value) {
            foreach ($ret as $comb) {
                $comb[$key] = $value;

                array_push($ret, $comb);
            }
        }

        return $ret;
    }
}
