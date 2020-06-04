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

Docker forced build:
```
docker rmi tp-canvas-v2 ; docker build --no-cache --tag tp-canvas-v2 .
```

Docker run:
```
```

Oneliner for developing:
```
docker run --env-file=.env.dev.list -it --rm --name tp-canvas-v2-run --volume "/Users/hpe001/repos/tp-canvas-v2/app/:/app/" tp-canvas-v2
```

```
ssh hpe001@tp-canvas.uit.no -L 3306:appbase.uit.no:3306
```

## Production

* `docker build --force-rm --no-cache --rm --build-arg https_proxy --build-arg http_proxy --build-arg GITHUBOAUTH=1234135344646 --tag tp-canvas-v2 .`
* `docker run --env-file=.env.prod.list --interactive --tty --detach --restart=unless-stopped --name tp-canvas-v2-run tp-canvas-v2 mq`
* Copy tp-canvas-v2.service to /etc/systemd/system/
* Build image (see above)
* (as root): `systemctl daemon-reload ; systemctl start tp-canvas-v2 ; systemctl enable tp-canvas-v2`

`docker run --env-file=.env.prod.list --interactive --tty --rm --name tp-canvas-v2-tmp tp-canvas-v2`
