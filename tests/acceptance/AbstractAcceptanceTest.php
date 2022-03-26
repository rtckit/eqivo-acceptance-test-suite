<?php

declare(strict_types = 1);

namespace RTCKit\Eqivo\Tests\Acceptance;

use RTCKit\Eqivo\{
    App,
    Config,
    HttpClient,
    Inbound,
    Outbound,
    Rest
};

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
    public const DOCKER_IMAGE_REPOSITORY = 'eqivo-slimswitch-test';

    public const DOCKER_IMAGE_TAG = 'v1.10.7';

    public const DOCKER_CONTAINER_NAME = 'eqivo-slimswitch-test';

    public const CONSUMER_SOCKET_ADDR = '127.0.0.1:8099';

    public static DockerContainerInstance $slimSwitch;

    public static App $app;

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

        /* Raise our application */
        self::$app = new App;

        $config = new Config\Set;
        $config->legacyConfigFile = __DIR__ . '/../fixtures/acceptance/default.conf';
        $config->outboundServerAdvertisedIp = self::$hostIp;

        self::$app->setConfig($config);
        self::$app->addConfigResolver(new Config\LegacyConfigFile);
        self::$app->resolveConfig();

        self::$app->setHttpClient(new HttpClient);

        self::$app->setInboundServer(
            (new Inbound\Server)
                ->setApp(self::$app)
                ->setController(new Inbound\Controller)
                ->setElementHandler(new Inbound\Handler\BackgroundJob)
                ->setElementHandler(new Inbound\Handler\CallUpdate)
                ->setElementHandler(new Inbound\Handler\ChannelHangupComplete)
                ->setElementHandler(new Inbound\Handler\ChannelProgress)
                ->setElementHandler(new Inbound\Handler\ChannelProgressMedia)
                ->setElementHandler(new Inbound\Handler\ChannelState)
                ->setElementHandler(new Inbound\Handler\Custom)
                ->setElementHandler(new Inbound\Handler\RecordStop)
                ->setElementHandler(new Inbound\Handler\SessionHeartbeat)
        );

        self::$app->setOutboundServer(
            (new Outbound\Server)
                ->setApp(self::$app)
                ->setController(new Outbound\Controller)
                ->setElementHandler(new Outbound\Conference\Handler)
                ->setElementHandler(new Outbound\Dial\Handler)
                ->setElementHandler(new Outbound\GetDigits\Handler)
                ->setElementHandler(new Outbound\GetSpeech\Handler)
                ->setElementHandler(new Outbound\Hangup\Handler)
                ->setElementHandler(new Outbound\Play\Handler)
                ->setElementHandler(new Outbound\PreAnswer\Handler)
                ->setElementHandler(new Outbound\Record\Handler)
                ->setElementHandler(new Outbound\Redirect\Handler)
                ->setElementHandler(new Outbound\SipTransfer\Handler)
                ->setElementHandler(new Outbound\Speak\Handler)
                ->setElementHandler(new Outbound\Wait\Handler)
        );

        self::$app->setRestServer(
            (new Rest\Server)
                ->setApp(self::$app)
                ->setRouteController('POST', '/v0.1/BulkCall/', new Rest\Controller\V0_1\BulkCall)
                ->setRouteController('POST', '/v0.1/Call/', new Rest\Controller\V0_1\Call)
                ->setRouteController('POST', '/v0.1/CancelScheduledHangup/', new Rest\Controller\V0_1\CancelScheduledHangup)
                ->setRouteController('POST', '/v0.1/ConferenceDeaf/', new Rest\Controller\V0_1\ConferenceDeaf)
                ->setRouteController('POST', '/v0.1/ConferenceHangup/', new Rest\Controller\V0_1\ConferenceHangup)
                ->setRouteController('POST', '/v0.1/ConferenceKick/', new Rest\Controller\V0_1\ConferenceKick)
                ->setRouteController('POST', '/v0.1/ConferenceList/', new Rest\Controller\V0_1\ConferenceList)
                ->setRouteController('POST', '/v0.1/ConferenceListMembers/', new Rest\Controller\V0_1\ConferenceListMembers)
                ->setRouteController('POST', '/v0.1/ConferenceMute/', new Rest\Controller\V0_1\ConferenceMute)
                ->setRouteController('POST', '/v0.1/ConferencePlay/', new Rest\Controller\V0_1\ConferencePlay)
                ->setRouteController('POST', '/v0.1/ConferenceRecordStart/', new Rest\Controller\V0_1\ConferenceRecordStart)
                ->setRouteController('POST', '/v0.1/ConferenceRecordStop/', new Rest\Controller\V0_1\ConferenceRecordStop)
                ->setRouteController('POST', '/v0.1/ConferenceSpeak/', new Rest\Controller\V0_1\ConferenceSpeak)
                ->setRouteController('POST', '/v0.1/ConferenceUndeaf/', new Rest\Controller\V0_1\ConferenceUndeaf)
                ->setRouteController('POST', '/v0.1/ConferenceUnmute/', new Rest\Controller\V0_1\ConferenceUnmute)
                ->setRouteController('POST', '/v0.1/GroupCall/', new Rest\Controller\V0_1\GroupCall)
                ->setRouteController('POST', '/v0.1/HangupAllCalls/', new Rest\Controller\V0_1\HangupAllCalls)
                ->setRouteController('POST', '/v0.1/HangupCall/', new Rest\Controller\V0_1\HangupCall)
                ->setRouteController('POST', '/v0.1/Play/', new Rest\Controller\V0_1\Play)
                ->setRouteController('POST', '/v0.1/PlayStop/', new Rest\Controller\V0_1\PlayStop)
                ->setRouteController('POST', '/v0.1/RecordStart/', new Rest\Controller\V0_1\RecordStart)
                ->setRouteController('POST', '/v0.1/RecordStop/', new Rest\Controller\V0_1\RecordStop)
                ->setRouteController('POST', '/v0.1/ScheduleHangup/', new Rest\Controller\V0_1\ScheduleHangup)
                ->setRouteController('POST', '/v0.1/SendDigits/', new Rest\Controller\V0_1\SendDigits)
                ->setRouteController('POST', '/v0.1/SoundTouch/', new Rest\Controller\V0_1\SoundTouch)
                ->setRouteController('POST', '/v0.1/SoundTouchStop/', new Rest\Controller\V0_1\SoundTouchStop)
                ->setRouteController('POST', '/v0.1/TransferCall/', new Rest\Controller\V0_1\TransferCall)
        );

        self::$app->prepare();

        self::$app->inboundServer->run();
        self::$app->outboundServer->run();
        self::$app->restServer->run();

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
        self::$app->inboundServer->shutdown();
        self::$app->outboundServer->shutdown();
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
