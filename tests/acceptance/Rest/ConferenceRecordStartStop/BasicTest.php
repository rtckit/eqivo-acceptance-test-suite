<?php

declare(strict_types = 1);

namespace RTCKit\Eqivo\Tests\Acceptance\Rest\ConferenceRecordStartStop;

use RTCKit\Eqivo\Tests\Acceptance\AbstractAcceptanceTest;

use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Promise\{
    Deferred,
    PromiseInterface
};
use RTCKit\ESL;
use function React\Promise\{
    all,
    resolve
};
use function Clue\React\Block\await;

/**
 *
 */
class BasicTest extends AbstractAcceptanceTest
{
    public static array $deferred = [];

    public static array $members = [];

    public function testConferenceRecordStartStop(): void
    {
        $promise = self::$browser
            ->post(
                '/v0.1/Call/',
                [
                    'Authorization' => self::$authHeader,
                    'Content-type' => 'application/x-www-form-urlencoded',
                ],
                http_build_query([
                    'From' => 'Alice',
                    'To' => 'Bob',
                    'Gateways' => 'sofia/gateway/a/',
                    'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceRecordStartStop/answer',
                    'HangupUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceRecordStartStop/hangup',
                ])
            )
            ->then(function ($response): PromiseInterface {
                $body = json_decode((string)$response->getBody());
                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertTrue($body->Success);

                self::$deferred['default/answer'] = new Deferred;

                return self::$deferred['default/answer']->promise();
            })
            ->then(function ($args): PromiseInterface {
                $this->assertIsArray($args);
                $this->assertEquals(Uuid::UUID_TYPE_RANDOM, Uuid::fromString($args['CallUUID'])->getVersion());
                $this->assertEquals('Alice', $args['From']);
                $this->assertEquals('Bob', $args['To']);
                $this->assertEquals('inbound', $args['Direction']);
                $this->assertEquals('ringing', $args['CallStatus']);

                self::$deferred['testConferenceRecordStartStop/answer'] = new Deferred;

                return self::$deferred['testConferenceRecordStartStop/answer']->promise();
            })
            ->then(function ($args): PromiseInterface {
                $this->assertIsArray($args);
                $this->assertEquals(Uuid::UUID_TYPE_RANDOM, Uuid::fromString($args['CallUUID'])->getVersion());
                $this->assertEquals('Alice', $args['From']);
                $this->assertEquals('Bob', $args['To']);
                $this->assertEquals('outbound', $args['Direction']);
                $this->assertEquals('in-progress', $args['CallStatus']);

                self::$deferred['testConferenceRecordStartStop/enter'] = new Deferred;

                return self::$deferred['testConferenceRecordStartStop/enter']->promise();
            })
            ->then(function ($args): PromiseInterface {
                return self::$browser->post(
                    '/v0.1/ConferenceRecordStart/',
                    [
                        'Authorization' => self::$authHeader,
                        'Content-type' => 'application/x-www-form-urlencoded',
                    ],
                    http_build_query([
                        'ConferenceName' => 'conf',
                        'FileFormat' => 'wav',
                        'FilePath' => '/tmp',
                        'FileName' => 'test',
                    ])
                );
            })
            ->then(function ($response): PromiseInterface {
                $body = json_decode((string)$response->getBody());

                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertTrue($body->Success);
                $this->assertEquals('/tmp/test.wav', $body->RecordFile);

                /* Not quite reliable ... sometimes it takes FS a second to update the recording status */
                sleep(3);

                return self::$esl->api((new ESL\Request\Api)->setParameters("conference conf xml_list"));
            })
            ->then(function ($response): PromiseInterface {
                $list = simplexml_load_string($response->getBody());

                $this->assertNotNull($list);
                $this->assertEquals('true', (string)$list->conference->attributes()['recording']);

                $hasRecordingNode = false;

                foreach ($list->conference->members->member as $member) {
                    $attrs = $member->attributes();

                    if (!isset($attrs['type']) || ((string)$attrs['type'] !== 'recording_node')) {
                        continue;
                    }

                    $hasRecordingNode = true;

                    $this->assertEquals('/tmp/test.wav', (string)$member->record_path);
                }

                $this->assertTrue($hasRecordingNode);

                $deferred = new Deferred();
                self::$deferred['default/record'] = new Deferred();

                Loop::addTimer(3, function () use ($deferred) {
                    self::$browser->post(
                        '/v0.1/ConferenceRecordStop/',
                        [
                            'Authorization' => self::$authHeader,
                            'Content-type' => 'application/x-www-form-urlencoded',
                        ],
                        http_build_query([
                            'ConferenceName' => 'conf',
                            'RecordFile' => '/tmp/test.wav',
                        ])
                    )
                        ->then(function ($response) use ($deferred) {
                            $deferred->resolve($response);
                        });
                });

                return all([
                    'response' => $deferred->promise(),
                    'notify' => self::$deferred['default/record']->promise(),
                ]);
            })
            ->then(function ($args): PromiseInterface {
                $this->assertEquals('conf', $args['notify']['ConferenceName']);
                $this->assertEquals('/tmp/test.wav', $args['notify']['RecordFile']);
                $this->assertGreaterThanOrEqual(3000, (int)$args['notify']['RecordDuration']);

                $body = json_decode((string)$args['response']->getBody());

                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertTrue($body->Success);

                return self::$esl->api((new ESL\Request\Api)->setParameters("conference conf xml_list"));
            })
            ->then(function ($response): PromiseInterface {
                $list = simplexml_load_string($response->getBody());
                $this->assertNotNull($list);
                $this->assertArrayNotHasKey('recording', (array)$list->conference->attributes());

                $hasRecordingNode = false;

                foreach ($list->conference->members->member as $member) {
                    $attrs = $member->attributes();

                    if (!isset($attrs['type']) || ((string)$attrs['type'] !== 'recording_node')) {
                        continue;
                    }
                }

                $this->assertFalse($hasRecordingNode);

                self::$deferred['testConferenceRecordStartStop/hangup'] = new Deferred;
                self::$deferred['default/hangup'] = new Deferred;
                self::$deferred['testConferenceRecordStartStop/exit'] = new Deferred;

                return all([
                    'aliceHangup' => self::$deferred['testConferenceRecordStartStop/hangup']->promise(),
                    'bobHangup' => self::$deferred['default/hangup']->promise(),
                    'exit' => self::$deferred['testConferenceRecordStartStop/exit']->promise(),
                    'hangup' => self::$esl->api((new ESL\Request\Api)->setParameters("fsctl hupall")),
                ]);
            })
            ->then(function ($args): PromiseInterface {
                $this->assertIsArray($args['aliceHangup']);
                $this->assertEquals(Uuid::UUID_TYPE_RANDOM, Uuid::fromString($args['aliceHangup']['CallUUID'])->getVersion());
                $this->assertEquals('Alice', $args['aliceHangup']['From']);
                $this->assertEquals('Bob', $args['aliceHangup']['To']);
                $this->assertEquals('outbound', $args['aliceHangup']['Direction']);
                $this->assertEquals('completed', $args['aliceHangup']['CallStatus']);
                $this->assertEquals('MANAGER_REQUEST', $args['aliceHangup']['HangupCause']);

                $this->assertIsArray($args['bobHangup']);
                $this->assertEquals(Uuid::UUID_TYPE_RANDOM, Uuid::fromString($args['bobHangup']['CallUUID'])->getVersion());
                $this->assertEquals('Alice', $args['bobHangup']['From']);
                $this->assertEquals('Bob', $args['bobHangup']['To']);
                $this->assertEquals('inbound', $args['bobHangup']['Direction']);
                $this->assertEquals('completed', $args['bobHangup']['CallStatus']);
                $this->assertEquals('MANAGER_REQUEST', $args['bobHangup']['HangupCause']);

                return resolve();
            });

        await($promise);
    }

    public static function onRestXmlRequest(ServerRequestInterface $request)
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $args = $request->getParsedBody() ?? [];

        switch ($path) {
            case '/default/answer':
                self::$deferred['default/answer']->resolve($args);

                return new Response(
                    200, ['Content-Type' => 'application/xml'],
                    '<?xml version="1.0" encoding="UTF-8"?><Response>' .
                    '<Conference callbackUrl="http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceRecordStartStop/conference">conf</Conference>' .
                    '</Response>'
                );

            case '/testConferenceRecordStartStop/answer':
                self::$deferred['testConferenceRecordStartStop/answer']->resolve($args);

                return new Response(
                    200, ['Content-Type' => 'application/xml'],
                    '<?xml version="1.0" encoding="UTF-8"?><Response>' .
                    '<Conference callbackUrl="http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceRecordStartStop/conference">conf</Conference>' .
                    '</Response>'
                );

            case '/default/hangup':
                self::$deferred['default/hangup']->resolve($args);
                break;

            case '/testConferenceRecordStartStop/hangup':
                self::$deferred['testConferenceRecordStartStop/hangup']->resolve($args);
                break;

            case '/testConferenceRecordStartStop/conference':
                switch ($args['ConferenceAction']) {
                    case 'enter':
                        self::$members[] = $args['ConferenceMemberID'];

                        if (count(self::$members) === 2) {
                            self::$deferred['testConferenceRecordStartStop/enter']->resolve($args);
                        }
                        break;

                    case 'exit':
                        array_pop(self::$members);

                        if (count(self::$members) === 0) {
                            self::$deferred['testConferenceRecordStartStop/exit']->resolve($args);
                        }
                        break;
                }
                break;

            case '/default/record':
                self::$deferred['default/record']->resolve($args);
                break;
        }

        return new Response(200, ['Content-Type' => 'text/plain'], 'OK');
    }
}
