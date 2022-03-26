<?php

declare(strict_types = 1);

namespace RTCKit\Eqivo\Tests\Acceptance\Rest\ConferenceDeafUndeaf;

use RTCKit\Eqivo\Tests\Acceptance\AbstractAcceptanceTest;

use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;
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

    public function testConferenceDeafUndeaf(): void
    {
        $this->doConferenceDeafUndeaf();
    }

    public function testPartlyBogusConferenceDeafUndeaf(): void
    {
        $this->doConferenceDeafUndeaf(',not,here');
    }

    public function doConferenceDeafUndeaf(string $bogusMembers = ''): void
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
                    'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceDeafUndeaf/answer',
                    'HangupUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceDeafUndeaf/hangup',
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

                self::$deferred['testConferenceDeafUndeaf/answer'] = new Deferred;

                return self::$deferred['testConferenceDeafUndeaf/answer']->promise();
            })
            ->then(function ($args): PromiseInterface {
                $this->assertIsArray($args);
                $this->assertEquals(Uuid::UUID_TYPE_RANDOM, Uuid::fromString($args['CallUUID'])->getVersion());
                $this->assertEquals('Alice', $args['From']);
                $this->assertEquals('Bob', $args['To']);
                $this->assertEquals('outbound', $args['Direction']);
                $this->assertEquals('in-progress', $args['CallStatus']);

                self::$deferred['testConferenceDeafUndeaf/enter'] = new Deferred;

                return self::$deferred['testConferenceDeafUndeaf/enter']->promise();
            })
            ->then(function ($args) use ($bogusMembers): PromiseInterface {
                return self::$browser->post(
                    '/v0.1/ConferenceDeaf/',
                    [
                        'Authorization' => self::$authHeader,
                        'Content-type' => 'application/x-www-form-urlencoded',
                    ],
                    http_build_query([
                        'ConferenceName' => 'conf',
                        'MemberID' => implode(',', self::$members) . $bogusMembers,
                    ])
                );
            })
            ->then(function ($response): PromiseInterface {
                $body = json_decode((string)$response->getBody());

                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertTrue($body->Success);
                $this->assertEquals(self::$members, $body->Members);

                return self::$esl->api((new ESL\Request\Api)->setParameters("conference conf xml_list"));
            })
            ->then(function ($response) use ($bogusMembers): PromiseInterface {
                $list = simplexml_load_string($response->getBody());
                $this->assertNotNull($list);

                foreach ($list->conference->members->member as $member) {
                    $attrs = $member->attributes();

                    if (!isset($attrs['type']) || ((string)$attrs['type'] !== 'caller')) {
                        continue;
                    }

                    $this->assertEquals('false', (string)$member->flags->can_hear);
                }

                return self::$browser->post(
                    '/v0.1/ConferenceUndeaf/',
                    [
                        'Authorization' => self::$authHeader,
                        'Content-type' => 'application/x-www-form-urlencoded',
                    ],
                    http_build_query([
                        'ConferenceName' => 'conf',
                        'MemberID' => implode(',', self::$members) . $bogusMembers,
                    ])
                );
            })
            ->then(function ($response): PromiseInterface {
                $body = json_decode((string)$response->getBody());

                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertTrue($body->Success);
                $this->assertEquals(self::$members, $body->Members);

                return self::$esl->api((new ESL\Request\Api)->setParameters("conference conf xml_list"));
            })
            ->then(function ($response): PromiseInterface {
                $list = simplexml_load_string($response->getBody());
                $this->assertNotNull($list);

                foreach ($list->conference->members->member as $member) {
                    $attrs = $member->attributes();

                    if (!isset($attrs['type']) || ((string)$attrs['type'] !== 'caller')) {
                        continue;
                    }

                    $this->assertEquals('true', (string)$member->flags->can_hear);
                }

                self::$deferred['testConferenceDeafUndeaf/hangup'] = new Deferred;
                self::$deferred['default/hangup'] = new Deferred;
                self::$deferred['testConferenceDeafUndeaf/exit'] = new Deferred;

                return all([
                    'aliceHangup' => self::$deferred['testConferenceDeafUndeaf/hangup']->promise(),
                    'bobHangup' => self::$deferred['default/hangup']->promise(),
                    'exit' => self::$deferred['testConferenceDeafUndeaf/exit']->promise(),
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
                    '<Conference callbackUrl="http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceDeafUndeaf/conference">conf</Conference>' .
                    '</Response>'
                );

            case '/testConferenceDeafUndeaf/answer':
                self::$deferred['testConferenceDeafUndeaf/answer']->resolve($args);

                return new Response(
                    200, ['Content-Type' => 'application/xml'],
                    '<?xml version="1.0" encoding="UTF-8"?><Response>' .
                    '<Conference callbackUrl="http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceDeafUndeaf/conference">conf</Conference>' .
                    '</Response>'
                );

            case '/default/hangup':
                self::$deferred['default/hangup']->resolve($args);
                break;

            case '/testConferenceDeafUndeaf/hangup':
                self::$deferred['testConferenceDeafUndeaf/hangup']->resolve($args);
                break;

            case '/testConferenceDeafUndeaf/conference':
                switch ($args['ConferenceAction']) {
                    case 'enter':
                        self::$members[] = $args['ConferenceMemberID'];

                        if (count(self::$members) === 2) {
                            self::$deferred['testConferenceDeafUndeaf/enter']->resolve($args);
                        }
                        break;

                    case 'exit':
                        array_pop(self::$members);

                        if (count(self::$members) === 0) {
                            self::$deferred['testConferenceDeafUndeaf/exit']->resolve($args);
                        }
                        break;
                }
                break;
        }

        return new Response(200, ['Content-Type' => 'text/plain'], 'OK');
    }
}
