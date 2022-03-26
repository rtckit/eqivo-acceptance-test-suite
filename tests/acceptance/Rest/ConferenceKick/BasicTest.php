<?php

declare(strict_types = 1);

namespace RTCKit\Eqivo\Tests\Acceptance\Rest\ConferenceKick;

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

    public static string $kicked;

    public function testConferenceKick(): void
    {
        $this->doConferenceKick();
    }

    public function testPartlyBogusConferenceKick(): void
    {
        $this->doConferenceKick(',not,here');
    }

    public function doConferenceKick(string $bogusMembers = ''): void
    {
        self::$members = [];

        $promise = all([
            self::$browser
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
                        'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceKick/answer',
                        'HangupUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceKick/hangup',
                    ])
                ),
            self::$browser
                ->post(
                    '/v0.1/Call/',
                    [
                        'Authorization' => self::$authHeader,
                        'Content-type' => 'application/x-www-form-urlencoded',
                    ],
                    http_build_query([
                        'From' => 'Alice',
                        'To' => 'Carol',
                        'Gateways' => 'sofia/gateway/a/',
                        'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceKick/answer',
                        'HangupUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceKick/hangup',
                    ])
                ),
        ])
            ->then(function ($response): PromiseInterface {
                self::$deferred['default/answer'] = new Deferred;

                return self::$deferred['default/answer']->promise();
            })
            ->then(function ($args): PromiseInterface {
                self::$deferred['testConferenceKick/answer'] = new Deferred;

                return self::$deferred['testConferenceKick/answer']->promise();
            })
            ->then(function ($args): PromiseInterface {
                self::$deferred['testConferenceKick/enter'] = new Deferred;

                return self::$deferred['testConferenceKick/enter']->promise();
            })
            ->then(function ($args) use ($bogusMembers): PromiseInterface {
                self::$kicked = end(self::$members);

                return self::$browser->post(
                    '/v0.1/ConferenceKick/',
                    [
                        'Authorization' => self::$authHeader,
                        'Content-type' => 'application/x-www-form-urlencoded',
                    ],
                    http_build_query([
                        'ConferenceName' => 'conf',
                        'MemberID' => self::$kicked . $bogusMembers,
                    ])
                );
            })
            ->then(function ($response): PromiseInterface {
                $body = json_decode((string)$response->getBody());
                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertTrue($body->Success);
                $this->assertEquals([self::$kicked], $body->Members);

                $deferred = new Deferred();

                Loop::addTimer(3, function () use ($deferred) {
                    self::$esl->api((new ESL\Request\Api)->setParameters("conference conf xml_list"))
                        ->then(function ($response) use ($deferred) {
                            $deferred->resolve($response);
                        });
                });

                return $deferred->promise();
            })
            ->then(function ($response) use ($bogusMembers): PromiseInterface {
                $count = 0;
                $list = simplexml_load_string($response->getBody());
                $this->assertNotNull($list);

                foreach ($list->conference->members->member as $member) {
                    $attrs = $member->attributes();

                    if (!isset($attrs['type']) || ((string)$attrs['type'] !== 'caller')) {
                        continue;
                    }

                    $count++;
                    $this->assertNotEquals(self::$kicked, (string)$member->id);
                }

                $this->assertEquals(count(self::$members), $count);

                self::$deferred['testConferenceKick/hangup'] = new Deferred;
                self::$deferred['default/hangup'] = new Deferred;
                self::$deferred['testConferenceKick/exit'] = new Deferred;

                return all([
                    'aliceHangup' => self::$deferred['testConferenceKick/hangup']->promise(),
                    'bobHangup' => self::$deferred['default/hangup']->promise(),
                    'exit' => self::$deferred['testConferenceKick/exit']->promise(),
                    'hangup' => self::$esl->api((new ESL\Request\Api)->setParameters("fsctl hupall")),
                ]);
            })
            ->then(function ($args): PromiseInterface {
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
                    '<Conference callbackUrl="http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceKick/conference">conf</Conference>' .
                    '</Response>'
                );

            case '/testConferenceKick/answer':
                self::$deferred['testConferenceKick/answer']->resolve($args);

                return new Response(
                    200, ['Content-Type' => 'application/xml'],
                    '<?xml version="1.0" encoding="UTF-8"?><Response>' .
                    '<Wait length="3600" />' .
                    '</Response>'
                );

            case '/default/hangup':
                self::$deferred['default/hangup']->resolve($args);
                break;

            case '/testConferenceKick/hangup':
                self::$deferred['testConferenceKick/hangup']->resolve($args);
                break;

            case '/testConferenceKick/conference':
                switch ($args['ConferenceAction']) {
                    case 'enter':
                        self::$members[] = $args['ConferenceMemberID'];

                        if (count(self::$members) === 2) {
                            self::$deferred['testConferenceKick/enter']->resolve($args);
                        }
                        break;

                    case 'exit':
                        array_pop(self::$members);

                        if (count(self::$members) === 0) {
                            self::$deferred['testConferenceKick/exit']->resolve($args);
                        }
                        break;
                }
                break;
        }

        return new Response(200, ['Content-Type' => 'text/plain'], 'OK');
    }
}
