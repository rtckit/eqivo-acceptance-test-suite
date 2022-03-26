<?php

declare(strict_types = 1);

namespace RTCKit\Eqivo\Tests\Acceptance\Outbound\Wait;

use RTCKit\Eqivo\Tests\Acceptance\AbstractAcceptanceTest;

use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;
use React\Http\Message\Response;
use React\Promise\{
    Deferred,
    PromiseInterface
};
use function React\Promise\{
    all,
    resolve
};
use function Clue\React\Block\await;

/**
 *
 */
class ExceptionTest extends AbstractAcceptanceTest
{
    public static array $deferred = [];

    public static array $milestones = [];

    public function testBadLength(): void
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
                    'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testWait/answer',
                    'HangupUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testWait/hangup',
                ])
            )
            ->then(function ($response): PromiseInterface {
                self::$deferred['default/answer'] = new Deferred;

                return self::$deferred['default/answer']->promise();
            })
            ->then(function ($args): PromiseInterface {
                self::$deferred['testWait/hangup'] = new Deferred;
                self::$deferred['default/hangup'] = new Deferred;

                return all([
                    'aliceHangup' => self::$deferred['testWait/hangup']->promise(),
                    'bobHangup' => self::$deferred['default/hangup']->promise(),
                ]);
            })
            ->then(function ($args): PromiseInterface {
                $delta = (self::$milestones['default/hangup'] - self::$milestones['default/answer'])/1e9;

                $this->assertTrue(($delta >= 0) && ($delta < 1));

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
                self::$milestones['default/answer'] = hrtime(true);
                self::$deferred['default/answer']->resolve($args);

                return new Response(
                    200, ['Content-Type' => 'application/xml'],
                    '<?xml version="1.0" encoding="UTF-8"?><Response>' .
                    '<Wait length="-9" />' .
                    '<Hangup />' .
                    '</Response>'
                );

            case '/default/hangup':
                self::$milestones['default/hangup'] = hrtime(true);
                self::$deferred['default/hangup']->resolve($args);
                break;

            case '/testWait/hangup':
                self::$deferred['testWait/hangup']->resolve($args);
                break;
        }

        return new Response(200, ['Content-Type' => 'text/plain'], 'OK');
    }
}
