Rather unorthodox acceptance test suite for [Eqivo](https://github.com/rtckit/eqivo), not for the faint-hearted.

First, build the target [slimswitch](https://github.com/rtckit/slimswitch) Docker image

```sh
composer build-slimswitch
```

Run the tests!

```sh
composer acceptance
```

Your mileage may vary considerably as the tooling makes certain assumptions with regards to the host machine.
