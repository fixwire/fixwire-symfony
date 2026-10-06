# Changelog

All notable changes to Fixwire for Symfony are listed here. Versions follow [Semantic
Versioning](https://semver.org); before 1.0, a minor version may change the
API.

## [0.1.0] - 2026-10-06

First release.

- Symfony 6.4, 7 and 8: a bundle configured under `fixwire:`.
- Exceptions the kernel handles (not client errors or what the firewall answers), each request as a span named after its route's path, with the user from the token storage.
- Messenger workers continuing the sender's trace, Doctrine DBAL 3 and 4 queries, every HTTP client traced, console commands in their own scope.
- Worker runtimes (FrankenPHP, RoadRunner): requests don't leak into each other.
