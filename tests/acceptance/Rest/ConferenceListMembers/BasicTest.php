<?php

declare(strict_types = 1);

namespace RTCKit\Eqivo\Tests\Acceptance\Rest\ConferenceListMembers;

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

    public function testConferenceListMembers(): void
    {
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
                        'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceListMembers/answer',
                        'HangupUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceListMembers/hangup',
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
                        'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceListMembers/answer',
                        'HangupUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceListMembers/hangup',
                    ])
                ),
        ])
            ->then(function ($response): PromiseInterface {
                self::$deferred['default/answer'] = new Deferred;

                return self::$deferred['default/answer']->promise();
            })
            ->then(function ($args): PromiseInterface {
                self::$deferred['testConferenceListMembers/answer'] = new Deferred;

                return self::$deferred['testConferenceListMembers/answer']->promise();
            })
            ->then(function ($args): PromiseInterface {
                self::$deferred['testConferenceListMembers/enter'] = new Deferred;

                return self::$deferred['testConferenceListMembers/enter']->promise();
            })
            ->then(function ($args): PromiseInterface {
                return self::$browser->post(
                    '/v0.1/ConferenceListMembers/',
                    [
                        'Authorization' => self::$authHeader,
                        'Content-type' => 'application/x-www-form-urlencoded',
                    ],
                    http_build_query([
                        'ConferenceName' => 'Bob',
                    ])
                );
            })
            ->then(function ($response): PromiseInterface {
                $body = json_decode((string)$response->getBody());
                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertTrue($body->Success);
                $this->assertNotNull($body->List);
                $this->assertNotNull($body->List->Bob);
                $this->assertTrue(!isset($body->List->Carol));

                return self::$esl->api((new ESL\Request\Api)->setParameters("conference Bob xml_list"));
            })
            ->then(function ($response): PromiseInterface {
                $count = 0;
                $list = simplexml_load_string($response->getBody());
                $this->assertNotNull($list);

                foreach ($list as $conference) {
                    foreach ($conference->members->member as $member) {
                        $attrs = $member->attributes();

                        if (!isset($attrs['type']) || ((string)$attrs['type'] !== 'caller')) {
                            continue;
                        }

                        $count++;
                        $this->assertContains((string)$member->id, self::$members);
                    }
                }

                self::$deferred['inbound/Alice/Bob/hangup'] = new Deferred;
                self::$deferred['inbound/Alice/Carol/hangup'] = new Deferred;
                self::$deferred['outbound/Alice/Bob/hangup'] = new Deferred;
                self::$deferred['outbound/Alice/Carol/hangup'] = new Deferred;
                self::$deferred['testConferenceListMembers/exit'] = new Deferred;

                return all([
                    'inbound/Alice/Bob/hangup' => self::$deferred['inbound/Alice/Bob/hangup']->promise(),
                    'inbound/Alice/Carol/hangup' => self::$deferred['inbound/Alice/Carol/hangup']->promise(),
                    'outbound/Alice/Bob/hangup' => self::$deferred['outbound/Alice/Bob/hangup']->promise(),
                    'outbound/Alice/Carol/hangup' => self::$deferred['outbound/Alice/Carol/hangup']->promise(),
                    'exit' => self::$deferred['testConferenceListMembers/exit']->promise(),
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
                    '<Conference callbackUrl="http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceListMembers/conference">' . $args['To'] . '</Conference>' .
                    '</Response>'
                );

            case '/testConferenceListMembers/answer':
                self::$deferred['testConferenceListMembers/answer']->resolve($args);

                return new Response(
                    200, ['Content-Type' => 'application/xml'],
                    '<?xml version="1.0" encoding="UTF-8"?><Response>' .
                    '<Conference callbackUrl="http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceListMembers/conference">' . $args['To'] . '</Conference>' .
                    '</Response>'
                );

            case '/default/hangup':
            case '/testConferenceListMembers/hangup':
                self::$deferred["{$args['Direction']}/{$args['From']}/{$args['To']}/hangup"]->resolve($args);
                break;

            case '/testConferenceListMembers/conference':
                switch ($args['ConferenceAction']) {
                    case 'enter':
                        self::$members[] = $args['ConferenceMemberID'];

                        if (count(self::$members) === 4) {
                            self::$deferred['testConferenceListMembers/enter']->resolve($args);
                        }
                        break;

                    case 'exit':
                        array_pop(self::$members);

                        if (count(self::$members) === 0) {
                            self::$deferred['testConferenceListMembers/exit']->resolve($args);
                        }
                        break;
                }
                break;
        }

        return new Response(200, ['Content-Type' => 'text/plain'], 'OK');
    }
}
