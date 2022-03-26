<?php

declare(strict_types = 1);

namespace RTCKit\Eqivo\Tests\Acceptance\Rest;

use RTCKit\Eqivo\Tests\Acceptance\AbstractAcceptanceTest;

use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;
use Wikimedia\IPSet;
use function React\Promise\resolve;
use function Clue\React\Block\await;

/**
 *
 */
class RouterTest extends AbstractAcceptanceTest
{
    public function testMissingRoute(): void
    {
        $promise = self::$browser
            ->withRejectErrorResponse(false)
            ->post(
                '/v0.1/Bogus/',
                [
                    'Authorization' => self::$authHeader,
                    'Content-type' => 'application/x-www-form-urlencoded',
                ]
            )
            ->then(function ($response) {
                $this->assertEquals(404, $response->getStatusCode());

                return resolve();
            });

        await($promise);
    }

    public function testBadRequest(): void
    {
        $promise = self::$browser
            ->withRejectErrorResponse(false)
            ->get(
                '/v0.1/Call/',
                [
                    'Authorization' => self::$authHeader,
                    'Content-type' => 'application/x-www-form-urlencoded',
                ]
            )
            ->then(function ($response) {
                $this->assertEquals(405, $response->getStatusCode());

                return resolve();
            });

        await($promise);
    }

    public function testNoAuthorization(): void
    {
        $promise = self::$browser
            ->withRejectErrorResponse(false)
            ->post(
                '/v0.1/Call/',
                [
                    'Content-type' => 'application/x-www-form-urlencoded',
                ]
            )
            ->then(function ($response) {
                $this->assertEquals(401, $response->getStatusCode());

                return resolve();
            });

        await($promise);
    }

    public function testBadAuthorization(): void
    {
        $promise = self::$browser
            ->withRejectErrorResponse(false)
            ->post(
                '/v0.1/Call/',
                [
                    'Authorization' => 'Basic ' . base64_encode('not:right'),
                    'Content-type' => 'application/x-www-form-urlencoded',
                ]
            )
            ->then(function ($response) {
                $this->assertEquals(401, $response->getStatusCode());

                return resolve();
            });

        await($promise);
    }

    public function testBadIpAddress(): void
    {
        self::$app->restServer->ipSet = new IPSet([]);

        $promise = self::$browser
            ->withRejectErrorResponse(false)
            ->post(
                '/v0.1/Call/',
                [
                    'Authorization' => self::$authHeader,
                    'Content-type' => 'application/x-www-form-urlencoded',
                ]
            )
            ->then(function ($response) {
                $this->assertEquals(401, $response->getStatusCode());

                return resolve();
            });

        await($promise);

        self::$app->restServer->ipSet = new IPSet(self::$app->config->restAllowedIps);
    }

    public static function onRestXmlRequest(ServerRequestInterface $request)
    {
    }
}
