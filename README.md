# Timing Toggl
Simple PHP CLI tool to convert CSV export files from [Timing](https://timingapp.com) to CSV files compatible with [Toggl](https://toggl.com) for import.

## Requirements

* PHP 7+
* Composer

## Getting started

1. Clone or download the repository
2. Run `composer install`
3. Run `bin/timing-toggl --help`
4. Optionally add the `bin` folder to your `PATH` or create a symlink to `bin/timing-toggl` from a directory in your `PATH` (e.g. `/usr/local/bin`).

### Export (report) settings

* Make sure that you only inlude tasks, not app usage
* Select all task fields except for "title -> as subgroup"
* Set file format to CSV
* Set duration format to XX:YY:ZZ

## Todo

- [ ] Create a PHAR file for easier distribution (help wanted)
- [ ] Add tests and CI
