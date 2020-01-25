# tp-canvas-v2

Synchronize schedules between TP and Canvas

# Codebase peculiarities

* Code style: [PSR-12](https://www.php-fig.org/psr/psr-12/)
* Code documentation: [PSR-5](https://github.com/php-fig/fig-standards/blob/master/proposed/phpdoc.md)
* Code documentation tags: [PSR-19](https://github.com/php-fig/fig-standards/blob/master/proposed/phpdoc-tags.md)
* Docker image: [Official PHP](https://hub.docker.com/_/php/)

# Install

* Copy `.env.sample.list` to `.env.test.list` and adjust values accordingly.

# Instructions

Docker build:
```
docker build -t tp-canvas-v2 .
```

Docker run:
```
docker run --env-file=.env.test.list -it --rm --name tp-canvas-v2-run tp-canvas-v2
```
