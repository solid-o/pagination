CHANGELOG
=========

v0.4.0
------

__BREAKING CHANGES__:

- `PagerIterator::setToken` has been renamed to `setCurrentPage` and now accepts a `PageToken`, a `PageOffset` or a `PageNumber` object
  - `PageToken` represents the continuation token, its format is not changed
  - `PageOffset` could be used to set the query offset
  - `PageNumber` could be used to paginate via "page number" (1-based)

__Changes__:

- Drop support for PHP < 8.1 and Symfony < 5.4
