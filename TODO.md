## Before v1.0

- [ ] Use Sentry
- [ ] Add multiple monolog handlers
- [ ] Rewrite direct REST calls in code to use webservice wrappers
- [x] Add dry-run mode !!!
- [x] Dry-run should not ack messages!

## After v1.0 (long term wishlist)

- [ ] Timestamp courses in database with "last updated", and check against incoming amqp messages
- [ ] Stop using globals
- [ ] Support Canvas sections
- [ ] Only sync current term (makes larger changes between terms easier)
- [ ] Implement unit tests
