# Changelog

## [1.2.0](https://github.com/magenxcommerce/module-platform/compare/v1.1.3...v1.2.0) (2026-09-04)


### Features

* move the tab strip into the admin's left-hand nav and read every FPM field ([d6cec27](https://github.com/magenxcommerce/module-platform/commit/d6cec2712146757dfda98da550cc27e7fa2a2c40))


### Bug Fixes

* Refactor FPM status display with improved row builders and layout ([#19](https://github.com/magenxcommerce/module-platform/issues/19)) ([d6cec27](https://github.com/magenxcommerce/module-platform/commit/d6cec2712146757dfda98da550cc27e7fa2a2c40))

## [1.1.3](https://github.com/magenxcommerce/module-platform/compare/v1.1.2...v1.1.3) (2026-09-03)


### Bug Fixes

* Add comprehensive unit tests and improve cache/timeout handling ([#17](https://github.com/magenxcommerce/module-platform/issues/17)) ([ba45145](https://github.com/magenxcommerce/module-platform/commit/ba4514543363326b7945a63b408a165184c07f09))

## [1.1.2](https://github.com/magenxcommerce/module-platform/compare/v1.1.1...v1.1.2) (2026-09-03)


### Bug Fixes

* keep breakdown labels as strings, so a status code row cannot fatal ([#15](https://github.com/magenxcommerce/module-platform/issues/15)) ([f74b67f](https://github.com/magenxcommerce/module-platform/commit/f74b67f292cbd34c42f2093ae9409917f3657fcc))

## [1.1.1](https://github.com/magenxcommerce/module-platform/compare/v1.1.0...v1.1.1) (2026-09-03)


### Bug Fixes

* read imgproxy's real metric names, and stop discarding labels ([#13](https://github.com/magenxcommerce/module-platform/issues/13)) ([d090459](https://github.com/magenxcommerce/module-platform/commit/d090459c7f6645353631e86a8c4e9440cab85288))

## [1.1.0](https://github.com/magenxcommerce/module-platform/compare/v1.0.4...v1.1.0) (2026-09-03)


### Features

* add an imgproxy tab, deeper JVM stats and recommended extension checks ([#11](https://github.com/magenxcommerce/module-platform/issues/11)) ([ba64ef2](https://github.com/magenxcommerce/module-platform/commit/ba64ef2e022b7174afd43a598c4c6bba722f7d0f))

## [1.0.4](https://github.com/magenxcommerce/module-platform/compare/v1.0.3...v1.0.4) (2026-09-03)


### Bug Fixes

* Internationalize dashboard UI and improve security/performance ([#9](https://github.com/magenxcommerce/module-platform/issues/9)) ([d9545da](https://github.com/magenxcommerce/module-platform/commit/d9545dacd5eb6d7de505e954f49d381000e487d8))
* keep configured URLs out of the page, and stop redundant probing ([d9545da](https://github.com/magenxcommerce/module-platform/commit/d9545dacd5eb6d7de505e954f49d381000e487d8))

## [1.0.3](https://github.com/magenxcommerce/module-platform/compare/v1.0.2...v1.0.3) (2026-09-02)


### Bug Fixes

* read the search password as stored and accept credentials in the host ([#7](https://github.com/magenxcommerce/module-platform/issues/7)) ([175ba8e](https://github.com/magenxcommerce/module-platform/commit/175ba8e351cc04f04f0edc84c65843a518b81c15))

## [1.0.2](https://github.com/magenxcommerce/module-platform/compare/v1.0.1...v1.0.2) (2026-09-02)


### Bug Fixes

* correct OPcache detection, search auth and the Nginx endpoint card ([#5](https://github.com/magenxcommerce/module-platform/issues/5)) ([ef5da8b](https://github.com/magenxcommerce/module-platform/commit/ef5da8bbe3a00ce7a4307667b2fc390b651c7d14))

## [1.0.1](https://github.com/magenxcommerce/module-platform/compare/v1.0.0...v1.0.1) (2026-09-02)


### Bug Fixes

* inject the concrete filesystem driver, not DriverInterface ([#3](https://github.com/magenxcommerce/module-platform/issues/3)) ([00a6557](https://github.com/magenxcommerce/module-platform/commit/00a65577b48fdc0bc44d9be42e50e6998e237936))

## 1.0.0 (2026-09-02)


### Features

* add Magenx_Platform stack status dashboard for the admin ([a5c079b](https://github.com/magenxcommerce/module-platform/commit/a5c079bf7bb22b7a1177c4cf2cfabb3fc62cc9da))
* add Magenx_Platform stack status dashboard for the admin ([2a5827f](https://github.com/magenxcommerce/module-platform/commit/2a5827f5e5c80da0f41b081b6f3e863f81f75fd1))


### Bug Fixes

* satisfy the Magento coding standard at severity 8 ([0a9c465](https://github.com/magenxcommerce/module-platform/commit/0a9c4652be923aad09faff62bb04b0cd79c4b146))
* take the directory check through the filesystem driver ([3a195db](https://github.com/magenxcommerce/module-platform/commit/3a195db5075dfc55427f9ff752d90d75f38b135e))
