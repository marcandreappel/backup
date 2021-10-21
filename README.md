# Laravel Backup Solution

If standard backup files are bigger than 5Gib and/or Backblaze B2 is the destination, then this package helps.

## Requirements

`zip` needs to be installed and executable on the server. 

## Installation

Install it with `composer require marcandreappel/backup`, run `php artisan vendor:publish` and choose the package configuration for publishing.

## Configuration

The configuration is not different from **Spatie's Backup**, just lighter. The comments help to understand...
