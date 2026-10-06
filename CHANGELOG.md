# Changelog

All notable changes to Fixwire for Symfony are listed here. Versions follow [Semantic
Versioning](https://semver.org); before 1.0, a minor version may change the
API.

## [0.1.1] - 2026-10-06

- An unknown key under `fixwire:` (or `breadcrumbs` and `tracing`), a value of the wrong type, or a `before_send` naming no service no longer stops the container from compiling: each is said on PHP's error log (`fixwire: no option 'x', ignored`) and left out, its default taken.
- A malformed `dsn` or an unknown key under `options` no longer stops the kernel from booting: it is said on PHP's error log and Fixwire stays off.
- `trace_propagation_targets` are URL prefixes or hosts with their subdomains (fixwire/fixwire's rules); `options` takes the SDK's new limits (`max_value_length`, `max_stack_frames`).

## [0.1.0] - 2026-10-06

First release.

- Symfony 6.4, 7 and 8: a bundle configured under `fixwire:`.
- Exceptions the kernel handles (not client errors or what the firewall answers), each request as a span named after its route's path, with the user from the token storage.
- Messenger workers continuing the sender's trace, Doctrine DBAL 3 and 4 queries, every HTTP client traced, console commands in their own scope.
- Worker runtimes (FrankenPHP, RoadRunner): requests don't leak into each other.
