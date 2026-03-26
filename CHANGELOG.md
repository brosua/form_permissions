# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-03-26

### Added

- Permission-aware database storage adapter (decorator around `DatabaseStorageAdapter`)
- `FormPermissionChecker` with read/write/create access checks
- `FormRepository` for form folder lookups and batch PID resolution
- Form folder support: pages with module `forms` as valid storage locations
- TCA override to allow `form_definition` records on any page (`rootLevel = -1`)
- Custom page tree icon for form folders
- Backend user group permissions enforcement (`tables_select` / `tables_modify`)
- Web mount and page permission checks (`PAGE_SHOW`)
