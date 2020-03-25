# tp-canvas-v2

Synchronize schedules between TP and Canvas

# Codebase peculiarities

* Code style: [PSR-12](https://www.php-fig.org/psr/psr-12/)
* Code documentation: [PSR-5](https://github.com/php-fig/fig-standards/blob/master/proposed/phpdoc.md)
* Code documentation tags: [PSR-19](https://github.com/php-fig/fig-standards/blob/master/proposed/phpdoc-tags.md)
* Docker image: [Official PHP](https://hub.docker.com/_/php/)
* PHP Strict mode: [Disabled](https://www.php.net/manual/en/functions.arguments.php#functions.arguments.type-declaration.strict)
* PHP Composer dependency manager [GetComposer.org](https://getcomposer.org)

# Packages

* [Guzzle](http://docs.guzzlephp.org/en/stable/index.html) ([Github](https://github.com/guzzle/guzzle))

# APIs

* [Canvas](https://canvas.instructure.com/doc/api/index.html)
* [UiO Gravitee services](https://api.uio.no/#!/apis) (Includes TP and FS)

# Server environment

* Run Docker as user on Centos: https://coderleaf.wordpress.com/2017/02/10/run-docker-as-user-on-centos7/
* Configure http proxies for Docker: https://www.thegeekdiary.com/how-to-configure-docker-to-use-proxy/


# Install

* Copy `.env.sample.list` to `.env.test.list` and adjust values accordingly.
* Run `composer install` from inside the app directory.
(or `https_proxy='' composer install --ignore-platform-reqs` if your server is old)

# Instructions

Docker build:
```
docker build -t tp-canvas-v2 .
```

Docker run:
```
docker run --env-file=.env.test.list -it --rm --name tp-canvas-v2-run tp-canvas-v2
```

Oneliner for developing:
```
docker build -t tp-canvas-v2 . ; docker run --env-file=.env.test.list -it --rm --name tp-canvas-v2-run tp-canvas-v2

## Production
* Copy tp-canvas-v2.service to /etc/systemd/system/
* Build image (see above)
* (as root): `systemctl daemon-reload ; systemctl start tp-canvas-v2 ; systemctl enable tp-canvas-v2`
